<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\AdminUser;
use App\Entity\TrialPlan;
use App\Entity\VpnPanel;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

/**
 * PR P11d of docs/design/plan-inbound-add-remove.md (9.1): adding or removing one inbound from the "Apply to existing
 * services" page, the run block and the service page, on MariaDB and the fake 3x-ui.
 *
 * Plan "Gold" sells DE-1 and NL-2, or DE-1 only for a remove. Every GET asks no panel and writes nothing; every write is a
 * POST with a CSRF token and PLANS_MANAGE; a run made here equals one made by the command with the same options; what the
 * command refuses is refused here; the pages only create runs as "planned", and the task processes them.
 */
final class InboundMoveAddRemoveWebTest extends IntegrationTestCase
{
    use InboundRemoveFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdd();
        $this->client = $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
    }

    public function testTheKindsShowOnlyWithBothSwitchesOn(): void
    {
        $this->goldService(1);
        $kinds = fn (): array => $this->get($this->page())->filter('[data-kind-option]')->each(static fn (Crawler $a): string => (string) $a->attr('data-kind-option'));

        self::assertSame(['primary', 'add', 'remove'], $kinds());

        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');
        self::assertSame([], $kinds());

        $this->setting(MultiInboundSettings::ENABLED, 'true', 'boolean');
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        self::assertSame([], $kinds());
    }

    public function testABackingPlanOffersNoKindAndRefusesThemWithAClearText(): void
    {
        $this->goldService(1);
        $this->em->persist((new TrialPlan())->setTitle('trial')->setDurationDays(1)->setInbound($this->managed($this->de))->setBackingPlan($this->managed($this->gold)));
        $this->em->flush();

        self::assertSame(0, $this->get($this->page())->filter('[data-kind-selector]')->count());
        foreach (['add' => 'its inbounds are fixed, so no inbound is added', 'remove' => 'its inbounds are fixed, so no inbound is removed'] as $kind => $text) {
            $page = $this->get($this->page(['kind' => $kind]));
            self::assertSame($kind.'_backing', $page->filter('[data-target-problem]')->attr('data-target-problem'));
            self::assertStringContainsString($text, $page->filter('[data-target-problem]')->text());
            self::assertSame(0, $page->filter('[data-target-select]')->count());
        }
    }

    public function testTheAddChooserOffersOnlyWhatThePlanRuleAllows(): void
    {
        $service = $this->goldService(1);
        $spare = $this->panelInbound(3, 'Not sold');
        $inactive = $this->panelInbound(4, 'Sold but off');
        $elsewhere = $this->panelInbound(5, 'Sold elsewhere');
        $other = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://panel2.example.test:2053/x/')->setPlainApiToken('other-token-0123456789')
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($other);
        $inactive->setIsActive(false);
        $elsewhere->setPanel($other);
        $this->managed($this->gold)->setAdditionalInbounds([$this->managed($this->nl), $inactive, $elsewhere]);
        $this->em->flush();
        self::assertNotNull($spare->getId());

        self::assertSame([(string) $this->nl->getId()], $this->choices(['kind' => 'add']));

        // Every service has it: nothing to offer, and the page says why, with a way to the plan.
        $this->rowFor($service, 'attached', ['attached_at' => '2026-01-01 00:00:00']);
        $page = $this->get($this->page(['kind' => 'add']));
        self::assertSame(0, $page->filter('[data-target-select]')->count());
        self::assertStringContainsString('Add the inbound to the plan first', $page->filter('[data-no-targets]')->text());
        self::assertStringContainsString('/edit', (string) $page->filter('[data-no-targets] a')->attr('href'));
    }

    public function testTheRemoveChooserOffersOnlyWhatThePlanNoLongerSells(): void
    {
        $this->sellNoMore();
        $sold = $this->panelInbound(3, 'Still sold');
        $this->managed($this->gold)->setAdditionalInbounds([$sold]);
        $this->em->flush();
        $service = $this->onBoth(1);
        $this->rowFor($service, 'attached', ['inbound_id' => $sold->getId(), 'remote_inbound_id' => '3', 'attempts' => 0, 'last_error' => null]);
        $this->managed($this->nl)->setIsActive(false);
        $this->em->flush();

        // The primary and the inbound still sold are not offered; an inactive inbound may be removed.
        self::assertSame([(string) $this->nl->getId()], $this->choices(['kind' => 'remove']));

        $this->em->getConnection()->executeStatement('DELETE FROM vpn_service_inbound WHERE inbound_id = ?', [$this->nl->getId()]);
        $page = $this->get($this->page(['kind' => 'remove']));
        self::assertStringContainsString('Take the inbound off the plan first', $page->filter('[data-no-targets]')->text());
    }

    public function testTheAddDryRunCountsLocallyAndWritesNothing(): void
    {
        $moving = $this->goldService(1);
        $this->goldService(2, VpnServiceStatus::SUSPENDED);
        // Two Shadowsocks inbounds: the command would read the panel's options here. The page never does.
        $this->managed($this->de)->setProtocol('shadowsocks');
        $this->managed($this->nl)->setProtocol('shadowsocks');
        $this->em->flush();
        $this->fake->requests = [];
        $before = $this->checksums() + $this->moveChecksums();

        $page = $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId()]));

        self::assertSame('add', $page->filter('[data-kind]')->attr('data-kind'));
        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertStringContainsString('1 would get the inbound', $page->filter('[data-would-move]')->text());
        self::assertSame('1', $page->filter('[data-unchecked]')->attr('data-unchecked'), 'checked when the run is created');
        self::assertSame('1', $page->filter('[data-summary] [data-skip-reason="suspended"]')->attr('data-skip-count'));
        $row = $page->filter('[data-list="moving"] tr[data-service-id]');
        self::assertSame((string) $moving->getId(), $row->attr('data-service-id'));
        self::assertSame('DE-1', $row->filter('[data-inbounds-now]')->attr('data-inbounds-now'));
        self::assertSame('NL-2, DE-1', $row->filter('[data-inbounds-now]')->attr('data-inbounds-after'));
        self::assertSame(1, $page->filter('[data-continue]')->count());
        self::assertSame(0, $page->filter('input[name="grace_hours"]')->count());
        self::assertSame(0, $page->filter('input[name="keep_old"]')->count());
        self::assertSame(0, $page->filter('input[name="include_trials"]')->count());
        self::assertGreaterThan(0, $page->filter('select[name="from_inbound"] option[value="'.$this->de->getId().'"]')->count(), 'its --from-inbound: the primary is a choice');

        self::assertSame([], $this->fake->requestLines(), 'local only: no panel call while rendering');
        self::assertSame($before, $this->checksums() + $this->moveChecksums());
    }

    public function testTheRemoveDryRunCountsLocallyWithAGraceOfZero(): void
    {
        $this->sellNoMore();
        $this->setting(InboundMoveSettings::DETACH_GRACE_HOURS, '48');
        $moving = $this->onBoth(1);
        $this->fake->requests = [];
        $before = $this->checksums() + $this->moveChecksums();

        $page = $this->get($this->page(['kind' => 'remove', 'inbound' => $this->nl->getId()]));

        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertStringContainsString('1 would lose the inbound', $page->filter('[data-would-move]')->text());
        self::assertSame('0', $page->filter('input[name="grace_hours"]')->attr('value'), 'Q1: the setting of a move is not read');
        $row = $page->filter('[data-list="moving"] tr[data-service-id="'.$moving->getId().'"] [data-inbounds-now]');
        self::assertSame('NL-2, DE-1', $row->attr('data-inbounds-now'));
        self::assertSame('DE-1', $row->attr('data-inbounds-after'));
        self::assertSame(0, $page->filter('select[name="from_inbound"]')->count());
        self::assertSame(0, $page->filter('input[name="keep_old"]')->count());

        self::assertSame([], $this->fake->requestLines());
        self::assertSame($before, $this->checksums() + $this->moveChecksums());
    }

    public function testTheConfirmationNamesTheInboundSaysWhatTheCustomerSeesAndTheRunIsPlanned(): void
    {
        $this->goldService(1);
        $this->fake->requests = [];
        $confirm = $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId(), 'step' => 'confirm']));
        self::assertStringContainsString('"NL-2"', $confirm->filter('[data-phases]')->text());
        self::assertStringContainsString('next subscription update', $confirm->filter('[data-customer-sees]')->text());
        self::assertStringContainsString('already sells this inbound', $this->lastPlanNote($confirm));
        self::assertSame(['add', (string) $this->nl->getId()], [$this->hidden($confirm, 'kind'), $this->hidden($confirm, 'inbound')]);

        $this->client->submit($confirm->filter('form[data-create-form]')->form());
        $this->client->submit($confirm->filter('form[data-create-form]')->form());

        self::assertSame([], $this->panelWrites(), 'the request calls no panel for a write');
        $runs = $this->runs();
        self::assertCount(1, $runs, 'a double submit creates one run');
        self::assertSame(['add', 'planned', (string) $this->nl->getId()], [$runs[0]['kind'], $runs[0]['status'], (string) $runs[0]['target_inbound_id']]);
        self::assertNotNull($runs[0]['requested_by']);
        self::assertSame(['planned'], array_column($this->moves(), 'state'));
        self::assertStringContainsString(sprintf('already has an open run (#%d)', $runs[0]['id']), $this->followedText());

        self::assertSame(1, self::getContainer()->get(InboundMoveScheduler::class)->run()->runs);
        self::assertSame(['done'], array_column($this->moves(), 'state'));
        $block = $this->get('/admin/plan/'.$this->gold->getId());
        self::assertSame('Added: 1 of 1', trim($block->filter('[data-count-done]')->text()));
        self::assertSame('Not added yet: 0', trim($block->filter('[data-count-remaining]')->text()));
        self::assertSame(0, $block->filter('[data-count-retired]')->count());
    }

    public function testARemoveFromThePageWaitsItsGraceThenTheTaskRemovesTheInbound(): void
    {
        $this->sellNoMore();
        $service = $this->onBoth(1);
        $this->fake->requests = [];
        $confirm = $this->get($this->page(['kind' => 'remove', 'inbound' => $this->nl->getId(), 'grace_hours' => 2, 'step' => 'confirm']));
        self::assertStringContainsString('stops working', $confirm->filter('[data-customer-sees]')->text());
        self::assertSame('2', $confirm->filter('[data-grace]')->attr('data-grace'));

        $this->client->submit($confirm->filter('form[data-create-form]')->form());

        self::assertSame([], $this->fake->requestLines());
        $run = $this->runs()[0];
        self::assertSame(['remove', 'planned', 2], [$run['kind'], $run['status'], (int) $run['grace_hours']]);
        self::assertNotNull($this->moves()[0]['detach_after']);
        $block = $this->get('/admin/plan/'.$this->gold->getId());
        self::assertSame('2', $block->filter('[data-run-grace]')->attr('data-run-grace'));
        self::assertSame('1', $block->filter('[data-count-waiting]')->attr('data-count-waiting'));

        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame([], $this->panelWrites(), 'not due yet');

        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET detach_after = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['done'], array_column($this->moves(), 'state'));
        self::assertNull($this->nlRow($service));
        self::assertSame([1], $this->onPanel(1));
        self::assertSame('Removed: 1 of 1', trim($this->get('/admin/plan/'.$this->gold->getId())->filter('[data-count-done]')->text()));
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'add' => ['add'];
        yield 'remove' => ['remove'];
    }

    #[DataProvider('kinds')]
    public function testARunMadeOnThePageEqualsOneMadeByTheCommandWithTheSameOptions(string $kind): void
    {
        $remove = 'remove' === $kind;
        if ($remove) {
            $this->sellNoMore();
        }
        $service = fn (int $n, string $status = 'active') => $remove ? $this->onBoth($n, $status) : $this->goldService($n, $status);
        $first = $service(1);
        $service(2);
        $service(3, VpnServiceStatus::SUSPENDED);
        $service(4, VpnServiceStatus::EXPIRED);
        $query = ['kind' => $kind, 'inbound' => $this->nl->getId(), 'include_suspended' => 1] + ($remove ? ['grace_hours' => 7] : []);
        $this->client->submit($this->get($this->page($query + ['step' => 'confirm']))->filter('form[data-create-form]')->form());
        $web = $this->creation();
        $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');

        [$code, $output] = $this->command(['--kind' => $kind, '--inbound' => (string) $this->nl->getId(), '--apply' => true, '--include-suspended' => true] + ($remove ? ['--grace-hours' => '7'] : []));
        self::assertSame(0, $code, $output);
        $command = $this->creation();

        self::assertNotNull($web['requestedBy']);
        self::assertNull($command['requestedBy']);
        unset($web['requestedBy'], $command['requestedBy']);
        self::assertSame($command, $web);
        self::assertGreaterThan(0, count($web['moved']));
        self::assertContains((int) $first->getId(), $web['moved']);
    }

    /** @return iterable<string, array{string, array<string, string|int>, string}> */
    public static function refusals(): iterable
    {
        yield 'add: keep old' => ['add', ['keep_old' => 1], 'Keeping the old inbound applies to a move only'];
        yield 'remove: keep old' => ['remove', ['keep_old' => 1], 'Keeping the old inbound applies to a move only'];
        yield 'add: trials' => ['add', ['include_trials' => 1], 'Trial services are not part of this'];
        yield 'remove: trials' => ['remove', ['include_trials' => 1], 'Trial services are not part of this'];
        yield 'add: grace' => ['add', ['grace_hours' => 3], 'has no waiting time'];
        yield 'remove: from inbound' => ['remove', ['from_inbound' => 1], 'does not apply here'];
        yield 'remove: grace too long' => ['remove', ['grace_hours' => 169], 'from 0 to 168'];
    }

    /**
     * The command's option rules, on a crafted form with a valid token.
     *
     * @param array<string, string|int> $extra
     */
    #[DataProvider('refusals')]
    public function testWhatTheCommandRefusesIsRefusedHere(string $kind, array $extra, string $text): void
    {
        if ('remove' === $kind) {
            $this->sellNoMore();
            $this->onBoth(1);
        } else {
            $this->goldService(1);
        }
        $form = $this->get($this->page(['kind' => $kind, 'inbound' => $this->nl->getId(), 'step' => 'confirm']))->filter('form[data-create-form]');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');
        $target = ['kind' => $kind, 'inbound' => (string) $this->nl->getId(), '_token' => $token] + array_map('strval', $extra);
        if (isset($extra['from_inbound'])) {
            $target['from_inbound'] = (string) $this->de->getId();
        }

        $this->client->request('POST', '/admin/plan/'.$this->gold->getId().'/apply-inbounds/create', $target);

        self::assertStringContainsString($text, $this->followedText());
        self::assertSame([], $this->runs());
    }

    public function testTheTargetAndKindAreCheckedAgainOnCreate(): void
    {
        $this->goldService(1);
        $form = $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId(), 'step' => 'confirm']))->filter('form[data-create-form]');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');
        $path = '/admin/plan/'.$this->gold->getId().'/apply-inbounds/create';
        $this->modernPanel = $this->managed($this->modernPanel);
        $spare = $this->panelInbound(3, 'Not sold');
        $this->em->flush();

        $tries = [
            [['kind' => 'add', 'inbound' => (string) $spare->getId()], 'The plan does not sell "Not sold"'],
            [['kind' => 'add'], 'Choose the inbound'],
            [['kind' => 'add', 'inbound' => '999999'], 'There is no such inbound'],
            [['kind' => 'add', 'inbound' => 'x'], 'Choose the inbound'],
            [['kind' => 'remove', 'inbound' => (string) $this->nl->getId()], 'The plan still sells "NL-2"'],
            [['kind' => 'remove', 'inbound' => (string) $this->de->getId()], 'is the plan\'s primary inbound: it is never removed'],
            [['inbound' => (string) $this->nl->getId()], 'an inbound is chosen only to add or remove it'],
            [['kind' => 'replace'], 'Unknown kind of run'],
        ];
        foreach ($tries as [$input, $text]) {
            $this->client->request('POST', $path, ['_token' => $token] + $input);
            self::assertStringContainsString($text, $this->followedText(), (string) json_encode($input));
        }
        self::assertSame([], $this->runs());
    }

    public function testEveryPostNeedsItsCsrfTokenAndNoGetCreatesARun(): void
    {
        $this->goldService(1);
        $path = '/admin/plan/'.$this->gold->getId().'/apply-inbounds';
        $this->client->request('POST', $path.'/create', ['kind' => 'add', 'inbound' => (string) $this->nl->getId(), '_token' => 'wrong']);
        self::assertSame([], $this->runs());
        $this->followedText();

        $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId(), 'step' => 'confirm']));
        $this->client->request('GET', $path.'/create');
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
        self::assertSame([], $this->runs());

        $runId = $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        foreach (['pause', 'cancel', 'undo', 'retry', 'resume'] as $action) {
            $this->client->request('POST', '/admin/inbound-move-run/'.$runId.'/'.$action, ['_token' => 'wrong']);
            self::assertStringContainsString('security token', mb_strtolower($this->followedText()), $action);
        }
        self::assertSame('planned', $this->runs()[0]['status']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function roles(): iterable
    {
        yield 'super admin' => [AdminUser::ROLE_SUPER_ADMIN, true];
        yield 'admin' => [AdminUser::ROLE_ADMIN, true];
        yield 'support (no PLANS_MANAGE)' => [AdminUser::ROLE_SUPPORT, false];
    }

    #[DataProvider('roles')]
    public function testPermissions(string $role, bool $plans): void
    {
        $this->goldService(1);
        $runId = $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        $this->client = $this->webAdmin($role);

        $this->client->request('GET', $this->page(['kind' => 'add']));
        self::assertSame($plans ? 200 : 403, $this->client->getResponse()->getStatusCode());
        foreach (['/admin/plan/'.$this->gold->getId().'/apply-inbounds/create', '/admin/inbound-move-run/'.$runId.'/undo'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'x', 'kind' => 'add', 'inbound' => (string) $this->nl->getId()]);
            self::assertSame($plans, 403 !== $this->client->getResponse()->getStatusCode(), $role.' POST '.$path);
        }
        self::assertSame('planned', $this->runs()[0]['status'], 'nothing changed');
    }

    /** @return iterable<string, array{string, string}> */
    public static function switches(): iterable
    {
        yield 'moving off' => [InboundMoveSettings::ENABLED, 'Moving services is switched off'];
        yield 'multi-inbound off' => [MultiInboundSettings::ENABLED, 'Multi-inbound plans are switched off'];
    }

    #[DataProvider('switches')]
    public function testASwitchOffRefusesCreatingWithAClearMessageAndTheCountsStillShow(string $key, string $text): void
    {
        $this->goldService(1);
        $form = $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId(), 'step' => 'confirm']))->filter('form[data-create-form]')->form();
        $this->setting($key, '0', 'boolean');

        $page = $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId()]));
        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'), 'a dry run works with the switches off, as the command\'s');
        self::assertSame(0, $page->filter('[data-continue]')->count());
        self::assertSame(1, $page->filter(InboundMoveSettings::ENABLED === $key ? '[data-switch-off]' : '[data-multi-off]')->count());
        self::assertSame(0, $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId(), 'step' => 'confirm']))->filter('form[data-create-form]')->count());

        $this->client->submit($form);
        self::assertSame([], $this->runs());
        self::assertStringContainsString($text, $this->followedText());
    }

    public function testWithMultiInboundOffResumeAndRetryAreRefusedAndUndoStillWorks(): void
    {
        $service = $this->goldService(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'no such inbound'));
        $runId = $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['failed'], array_column($this->moves(), 'state'));
        $this->click('/admin/plan/'.$this->gold->getId(), 'pause');
        $page = '/admin/plan/'.$this->gold->getId();
        $resume = $this->get($page)->filter('form[data-run-action="resume"]')->form();
        $retry = $this->get($page)->filter('form[data-run-action="retry"]')->form();
        $retryNow = $this->get('/admin/vpn-service/'.$service->getId())->filter('form[data-move-retry]')->form();

        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');
        $block = $this->get($page);
        self::assertSame(['cancel', 'undo'], $this->buttons($block), 'pause, cancel and undo stay (6.3)');
        self::assertSame(1, $block->filter('[data-multi-off-buttons]')->count());
        self::assertSame(0, $this->get('/admin/vpn-service/'.$service->getId())->filter('form[data-move-retry]')->count());

        foreach ([$resume, $retry, $retryNow] as $form) {
            $this->client->submit($form);
            self::assertStringContainsString('Multi-inbound plans are switched off', $this->followedText());
        }
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertSame(1, (int) $this->moves()[0]['attempts'], 'nothing was re-armed');

        $this->click($page, 'undo');
        self::assertSame((string) $runId, (string) $this->runs()[0]['id']);
        self::assertContains($this->moves()[0]['state'], ['undone', 'failed']);
    }

    public function testTheButtonsWorkOnAnAddRun(): void
    {
        $this->goldService(1);
        $this->goldService(2);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'no such inbound'));
        $runId = $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        $page = '/admin/plan/'.$this->gold->getId();
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->get($page)), 'planned: undo cancels what has not started');

        $this->click($page, 'pause');
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertSame(['resume', 'cancel', 'undo'], $this->buttons($this->get($page)));
        self::assertSame(0, self::getContainer()->get(InboundMoveScheduler::class)->run()->runs, 'the task leaves a paused run alone');
        $this->click($page, 'resume');
        self::assertSame('running', $this->runs()[0]['status']);

        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['failed', 'done'], array_column($this->moves(), 'state'));
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = NULL, attempts = 99 WHERE state = 'failed'");
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertContains('retry', $this->buttons($this->get($page)));

        $this->fake->clearScripts();
        $this->click($page, 'retry');
        self::assertSame('running', $this->runs()[0]['status'], 'a done run is reopened');
        self::assertSame(0, (int) $this->moves()[0]['attempts']);
        self::assertSame((string) $runId, (string) $this->runs()[0]['id']);
    }

    public function testCancelOnThePageKeepsTheServicesNotReached(): void
    {
        $this->sellNoMore();
        $this->onBoth(1);
        $this->createRun(['kind' => 'remove', 'inbound' => $this->nl->getId(), 'grace_hours' => 5]);

        $this->click('/admin/plan/'.$this->gold->getId(), 'cancel');

        self::assertSame('cancelled', $this->runs()[0]['status']);
        self::assertSame(['cancelled'], array_column($this->moves(), 'state'));
        self::assertSame([], $this->panelWrites());
    }

    public function testUndoOfAnAddOnThePageTakesItOffAndSaysThePlanStillSellsIt(): void
    {
        $service = $this->goldService(1);
        $runId = $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertNotNull($this->nlRow($service));

        $page = $this->get('/admin/plan/'.$this->gold->getId());
        self::assertStringContainsString('still sells this inbound', str_replace('\u0020', ' ', (string) $page->filter('form[data-run-action="undo"]')->attr('onsubmit')));
        $this->client->submit($page->filter('form[data-run-action="undo"]')->form());

        self::assertStringContainsString(sprintf('Undo of run #%d: the inbound was taken off 1', $runId), $this->followedText());
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
        $this->em->clear();
        self::assertCount(1, $this->managed($this->gold)->getAdditionalInbounds(), 'a run never changes the plan');
    }

    public function testUndoOfARemoveOnThePagePutsItBackAndARemoveNeverSaysItCannot(): void
    {
        $this->sellNoMore();
        $service = $this->onBoth(1);
        $runId = $this->createRun(['kind' => 'remove', 'inbound' => $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame([1], $this->onPanel(1));

        $page = $this->get('/admin/plan/'.$this->gold->getId());
        self::assertSame('done', $page->filter('[data-run-status]')->attr('data-run-status'));
        self::assertSame(0, $page->filter('[data-undo-retired]')->count(), 'that text is a primary run\'s: a done remove can be undone');
        $this->client->submit($page->filter('form[data-run-action="undo"]')->form());

        $text = $this->followedText();
        self::assertStringContainsString(sprintf('Undo of run #%d: the inbound was put back on 1', $runId), $text);
        self::assertStringNotContainsString('old inbound', mb_substr($text, (int) mb_strpos($text, 'Undo of run'), 200));
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testTheUndoButtonStaysWhileAnUndoStillOwesItsLinks(): void
    {
        $this->goldService(1);
        $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        // As --undo leaves a finished move whose link refresh failed: only --undo finishes it.
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET state = 'undone', next_attempt_at = '2026-01-01 00:00:00'");

        self::assertContains('undo', $this->buttons($this->get('/admin/plan/'.$this->gold->getId())));

        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET next_attempt_at = NULL');
        self::assertNotContains('undo', $this->buttons($this->get('/admin/plan/'.$this->gold->getId())));
    }

    public function testRetryIsHiddenAndRefusedForAnAddWhoseUndoStopped(): void
    {
        $service = $this->goldService(1);
        $runId = $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->click('/admin/plan/'.$this->gold->getId(), 'undo');
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);

        self::assertNotContains('retry', $this->buttons($this->get('/admin/plan/'.$this->gold->getId())));
        $page = $this->get('/admin/vpn-service/'.$service->getId());
        self::assertSame(0, $page->filter('form[data-move-retry]')->count(), 'a retry would attach it again under the hold');

        self::assertFalse(self::getContainer()->get(InboundMoveControls::class)->retryFailed($runId, true, (int) $service->getId())['ok']);
        self::assertSame('failed', $this->moves()[0]['state']);
    }

    public function testTheServicePageNamesTheInboundInsteadOfAnArrow(): void
    {
        $added = $this->goldService(1);
        $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        $this->fake->requests = [];

        $line = $this->get('/admin/vpn-service/'.$added->getId())->filter('[data-inbound-move]');
        self::assertSame('add', $line->attr('data-move-kind'));
        self::assertSame('Adding inbound: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringNotContainsString('→', $line->text());

        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $line = $this->get('/admin/vpn-service/'.$added->getId())->filter('[data-inbound-move]');
        self::assertSame('Inbound added: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringNotContainsString('old inbound', $line->text());

        $this->click('/admin/plan/'.$this->gold->getId(), 'undo');
        self::assertStringContainsString('taken off again', $this->get('/admin/vpn-service/'.$added->getId())->filter('[data-inbound-move]')->text());
    }

    public function testTheServicePageOfARemove(): void
    {
        $this->sellNoMore();
        $service = $this->onBoth(1);
        $this->createRun(['kind' => 'remove', 'inbound' => $this->nl->getId(), 'grace_hours' => 3]);

        $line = $this->get('/admin/vpn-service/'.$service->getId())->filter('[data-inbound-move]');
        self::assertSame('Removing inbound: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringContainsString('removed after', $line->text());

        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET detach_after = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $line = $this->get('/admin/vpn-service/'.$service->getId())->filter('[data-inbound-move]');
        self::assertSame('Inbound removed: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringContainsString('removed at', $line->text());
        self::assertStringNotContainsString('old inbound', $line->text());
    }

    public function testTheRunBlockSaysWhenLinksWaitAndWhenThePlanChanged(): void
    {
        $this->goldService(1);
        $this->createRun(['kind' => 'add', 'inbound' => $this->nl->getId()]);
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE service_inbound_move SET state = 'switched'");
        self::assertSame('1', $this->get('/admin/plan/'.$this->gold->getId())->filter('[data-count-links]')->attr('data-count-links'));

        $this->sellNoMore();
        $text = $this->get('/admin/plan/'.$this->gold->getId())->filter('[data-plan-changed]')->text();
        self::assertStringContainsString('no longer sells this inbound', $text);
        self::assertStringNotContainsString('its own target', $text);
    }

    public function testThePagesShowIdsAndTitlesOnlyEscaped(): void
    {
        $service = $this->goldService(1);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'NL <script>alert(1)</script>'], ['id' => $this->nl->getId()]);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'DE <img src=x>'], ['id' => $this->de->getId()]);
        $this->em->clear();
        $query = ['kind' => 'add', 'inbound' => $this->nl->getId()];
        $this->createRun($query);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $this->fake->requests = [];

        $pages = [
            'chooser' => $this->html($this->page(['kind' => 'add'])),
            'dry run' => $this->html($this->page($query)),
            'confirm' => $this->html($this->page($query + ['step' => 'confirm'])),
            'plan' => $this->html('/admin/plan/'.$this->gold->getId()),
            'service' => (string) (new Crawler($this->html('/admin/vpn-service/'.$service->getId())))->filter('[data-inbound-move]')->html(),
        ];
        foreach ($pages as $name => $html) {
            self::assertStringNotContainsString('<script>alert(1)</script>', $html, $name);
            self::assertStringNotContainsString('<img src=x>', $html, $name);
            foreach ([...$this->secretsOf($service), 'Owner Kaveh', '8940001'] as $secret) {
                self::assertStringNotContainsString($secret, $html, $name.' must not show '.$secret);
            }
        }
        self::assertStringContainsString('NL &lt;script&gt;alert(1)&lt;/script&gt;', $pages['service']);
        self::assertSame([], $this->fake->requestLines());
    }

    public function testTheTextsExistInBothLanguagesWithTheSameKeys(): void
    {
        $en = Yaml::parseFile(dirname(__DIR__, 3).'/translations/admin.en.yaml')['inbound_move'];
        $fa = Yaml::parseFile(dirname(__DIR__, 3).'/translations/admin.fa.yaml')['inbound_move'];
        self::assertSame(array_keys($en), array_keys($fa));

        foreach (['add', 'remove'] as $kind) {
            foreach (['planned', 'running', 'paused', 'done', 'cancelled'] as $status) {
                self::assertArrayHasKey('status_help_'.$kind.'.'.$status, $en);
            }
            foreach (['backing'] as $issue) {
                self::assertArrayHasKey('apply.'.$kind.'_'.$issue, $en);
            }
        }
        foreach ([InboundMovePlanner::TARGET_NOT_IN_PLAN, InboundMovePlanner::TARGET_INACTIVE, InboundMovePlanner::TARGET_OTHER_PANEL] as $issue) {
            self::assertArrayHasKey('apply.add_'.$issue, $en);
        }
        foreach ([InboundMovePlanner::TARGET_IS_PRIMARY, InboundMovePlanner::TARGET_IN_PLAN] as $issue) {
            self::assertArrayHasKey('apply.remove_'.$issue, $en);
        }

        $this->goldService(1);
        $this->client->request('GET', '/admin/locale/fa');
        $page = $this->get($this->page(['kind' => 'add', 'inbound' => $this->nl->getId(), 'step' => 'confirm']));
        self::assertStringContainsString('آنچه مشتری می‌بیند', $page->filter('[data-customer-sees]')->text());
    }

    private function lastPlanNote(Crawler $page): string
    {
        return $page->filter('[data-plan-note]')->text();
    }

    private function hidden(Crawler $page, string $name): ?string
    {
        return $page->filter('form[data-create-form] input[name="'.$name.'"]')->attr('value');
    }

    /** @param array<string, mixed> $query */
    private function page(array $query = []): string
    {
        return '/admin/plan/'.$this->gold->getId().'/apply-inbounds'.([] === $query ? '' : '?'.http_build_query($query));
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function choices(array $query): array
    {
        return array_values(array_filter($this->get($this->page($query))->filter('[data-target-select] option')->each(static fn (Crawler $o): string => (string) $o->attr('value'))));
    }

    /** @param array<string, mixed> $query */
    private function createRun(array $query): int
    {
        $this->client->submit($this->get($this->page(['step' => 'confirm'] + $query))->filter('form[data-create-form]')->form());
        $runs = $this->runs();
        self::assertCount(1, $runs);
        $this->fake->requests = [];

        return (int) $runs[0]['id'];
    }

    private function click(string $page, string $action): void
    {
        $form = $this->get($page)->filter('form[data-run-action="'.$action.'"]');
        self::assertCount(1, $form, 'button '.$action);
        $this->client->submit($form->form());
        $this->em->clear();
    }

    /** @return list<string> */
    private function buttons(Crawler $page): array
    {
        return $page->filter('[data-run-buttons] form[data-run-action]')->each(static fn (Crawler $f): string => (string) $f->attr('data-run-action'));
    }

    private function get(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path.': '.mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1500));
        $this->em->clear();

        return $crawler;
    }

    private function html(string $path): string
    {
        $this->get($path);

        return (string) $this->client->getResponse()->getContent();
    }

    private function followedText(): string
    {
        self::assertTrue($this->client->getResponse()->isRedirect(), mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1000));
        $this->client->followRedirect();
        $this->em->clear();

        return html_entity_decode(strip_tags((string) $this->client->getResponse()->getContent()), ENT_QUOTES);
    }

    /**
     * The run's creation as stored, without its ids.
     *
     * @return array{run: array<string, mixed>, planned: list<array<string, mixed>>, skipped: list<array<string, mixed>>, moved: list<int>, audit: array<string, mixed>, requestedBy: mixed}
     */
    private function creation(): array
    {
        $db = $this->em->getConnection();
        $run = $db->fetchAssociative('SELECT plan_id, kind, target_inbound_id, scope, grace_hours, keep_old, requested_by FROM inbound_move_run') ?: [];
        $requestedBy = $run['requested_by'] ?? null;
        unset($run['requested_by']);

        return [
            'run' => $run,
            'planned' => $db->fetchAllAssociative("SELECT service_id, from_inbound_id, to_inbound_id, from_remote_inbound_id, to_remote_inbound_id FROM service_inbound_move WHERE state <> 'skipped' ORDER BY service_id"),
            'skipped' => $db->fetchAllAssociative("SELECT service_id, from_inbound_id, to_inbound_id, skip_reason FROM service_inbound_move WHERE state = 'skipped' ORDER BY service_id"),
            'moved' => array_map('intval', $db->fetchFirstColumn("SELECT service_id FROM service_inbound_move WHERE state <> 'skipped' ORDER BY service_id")),
            'audit' => array_diff_key(array_values(array_filter($this->audits(), static fn (array $a): bool => 'plan.inbounds_apply_started' === $a['action']))[0]['details'] ?? [], ['run_id' => true]),
            'requestedBy' => $requestedBy,
        ];
    }

    /** @return array<string, string> */
    private function moveChecksums(): array
    {
        $sums = [];
        foreach (['inbound_move_run', 'service_inbound_move', 'vpn_service_inbound', 'admin_audit_log'] as $table) {
            $sums[$table] = (string) $this->em->getConnection()->fetchAssociative('CHECKSUM TABLE `'.$table.'`')['Checksum'];
        }

        return $sums;
    }

    private function webAdmin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('p11d-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $client->request('GET', '/admin/locale/en');

        return $client;
    }
}
