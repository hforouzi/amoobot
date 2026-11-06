<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Shop\InboundMove\InboundRemoveFixtures;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

/**
 * PR P11e of docs/design/plan-inbound-add-remove.md: adding or removing one inbound in the Mini App admin, on MariaDB and
 * the fake 3x-ui. The same actions as the web panel (P11d), through the same code (InboundMoveActions): a run made here
 * equals one made by app:plan:apply-inbounds with the same options, and what the command refuses is refused here.
 *
 * Every screen is read only and asks no panel; every write is a POST with the session's CSRF token and PLANS_MANAGE; the
 * screens show service ids and inbound titles only (D5).
 */
final class MiniAppAdminInboundAddRemoveTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use InboundRemoveFixtures {
        BotAdminTestTrait::requestBody insteadof InboundRemoveFixtures;
    }
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const PERSIAN = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

    /** @var array{token: string, csrf: string} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
        $this->setUpAdd();
        $this->session = $this->authenticate(self::ADMIN);
    }

    public function testTheKindsAreChipsOnlyWithBothSwitchesOn(): void
    {
        $this->goldService(1);
        $chips = fn (): array => $this->screen('/apply-inbounds?lang=en')->filter('[data-kind-option]')->each(static fn (Crawler $a): string => (string) $a->attr('data-kind-option'));

        self::assertSame(['primary', 'add', 'remove'], $chips());

        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');
        self::assertSame([], $chips());

        $this->setting(MultiInboundSettings::ENABLED, 'true', 'boolean');
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        self::assertSame([], $chips());
    }

    public function testTheChoosersOfferWhatTheWebOffersAndAnEmptyOneSaysWhereToEditThePlan(): void
    {
        $service = $this->goldService(1);
        $this->modernPanel = $this->managed($this->modernPanel);
        $this->panelInbound(3, 'Not sold');
        $this->em->flush();
        self::assertSame([(string) $this->nl->getId()], $this->choices('kind=add'));
        self::assertSame([], $this->choices('kind=remove'));

        $this->rowFor($service, 'attached', ['attached_at' => '2026-01-01 00:00:00']);
        self::assertSame([], $this->choices('kind=add'));
        $empty = $this->screen('/apply-inbounds?lang=en&kind=add')->filter('[data-no-targets]');
        self::assertStringContainsString('Add the inbound to the plan first', $empty->text());
        self::assertStringContainsString('edited on the web panel', $empty->text());

        $this->sellNoMore();
        self::assertSame([(string) $this->nl->getId()], $this->choices('kind=remove'), 'the plan no longer sells it and a service has it');
    }

    public function testTheAddDryRunCountsLocallyTakesPersianDigitsAndWritesNothing(): void
    {
        $service = $this->goldService(1);
        $this->goldService(2, VpnServiceStatus::SUSPENDED);
        $this->fake->requests = [];
        $before = $this->moveChecksums();

        $page = $this->screen('/apply-inbounds?lang=en&kind=add&inbound='.strtr((string) $this->nl->getId(), self::PERSIAN));

        self::assertSame('add', $page->filter('[data-kind]')->attr('data-kind'));
        self::assertSame((string) $this->nl->getId(), $page->filter('[data-target]')->attr('data-target'));
        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertStringContainsString('1 would get the inbound', $page->filter('[data-would-move]')->text());
        $row = $page->filter('[data-list="moving"] [data-service-id="'.$service->getId().'"] [data-inbounds-now]');
        self::assertSame(['DE-1', 'NL-2, DE-1'], [$row->attr('data-inbounds-now'), $row->attr('data-inbounds-after')]);
        foreach (['grace_hours', 'keep_old', 'include_trials'] as $absent) {
            self::assertCount(0, $page->filter('[name="'.$absent.'"]'), $absent);
        }

        self::assertSame([], $this->fake->requestLines(), 'no panel request while rendering');
        self::assertSame($before, $this->moveChecksums(), 'nothing written');
    }

    public function testTheRemoveDryRunUsesAGraceOfZeroWhateverTheSettingSays(): void
    {
        $this->sellNoMore();
        $this->setting(InboundMoveSettings::DETACH_GRACE_HOURS, '48');
        $this->onBoth(1);
        $this->fake->requests = [];

        $page = $this->screen('/apply-inbounds?lang=en&kind=remove&inbound='.$this->nl->getId());

        self::assertSame('0', $page->filter('input[name="grace_hours"]')->attr('value'));
        self::assertStringContainsString('1 would lose the inbound', $page->filter('[data-would-move]')->text());
        self::assertCount(0, $page->filter('[name="from_inbound"]'));
        self::assertSame([], $this->fake->requestLines());
    }

    public function testConfirmingAnAddCreatesOnePlannedRunADoubleTapIsOneAndTheTaskProcessesIt(): void
    {
        $service = $this->goldService(1);
        $this->fake->requests = [];
        $page = $this->screen('/apply-inbounds?lang=en&step=confirm&kind=add&inbound='.$this->nl->getId());
        self::assertStringContainsString('next subscription update', $page->filter('[data-customer-sees]')->text());
        self::assertStringContainsString('already sells this inbound', $page->filter('[data-plan-note]')->text());
        $fields = $this->fields($page->filter('form[data-create-form]'));
        self::assertSame(['kind' => 'add', 'inbound' => (string) $this->nl->getId()], $fields);

        $key = self::newKey();
        $first = $this->post('/apply-inbounds?lang=en', $fields, $key);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($first));
        self::assertSame($first, $this->post('/apply-inbounds?lang=en', $fields, $key), 'a double tap (same Idempotency-Key) gets the first answer');

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame(['add', 'planned'], [$runs[0]['kind'], $runs[0]['status']]);
        self::assertSame([], $this->panelWrites(), 'the request writes to no panel');

        self::assertSame(1, self::getContainer()->get(InboundMoveScheduler::class)->run()->runs);
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertNotNull($this->nlRow($service));
        $block = $this->screen('?lang=en');
        self::assertSame('Added: 1 of 1', trim($block->filter('[data-count-done]')->text()));
        self::assertCount(0, $block->filter('[data-count-retired]'));
    }

    public function testARemoveWaitsItsGraceThenTheTaskTakesTheInboundOff(): void
    {
        $this->sellNoMore();
        $service = $this->onBoth(1);
        $page = $this->screen('/apply-inbounds?lang=en&step=confirm&kind=remove&inbound='.$this->nl->getId().'&grace_hours='.strtr('2', self::PERSIAN));
        self::assertStringContainsString('stops working', $page->filter('[data-customer-sees]')->text());
        self::assertSame('2', $page->filter('[data-grace]')->attr('data-grace'));

        $this->post('/apply-inbounds?lang=en', $this->fields($page->filter('form[data-create-form]')));
        self::assertSame(200, $this->httpStatus());
        $block = $this->screen('?lang=en');
        self::assertSame('2', $block->filter('[data-run-grace]')->attr('data-run-grace'));
        self::assertSame('1', $block->filter('[data-count-waiting]')->attr('data-count-waiting'));

        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET detach_after = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();

        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
        self::assertSame('Removed: 1 of 1', trim($this->screen('?lang=en')->filter('[data-count-done]')->text()));
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'add' => ['add'];
        yield 'remove' => ['remove'];
    }

    #[DataProvider('kinds')]
    public function testARunMadeHereEqualsOneMadeByTheCommandWithTheSameOptions(string $kind): void
    {
        $remove = 'remove' === $kind;
        if ($remove) {
            $this->sellNoMore();
        }
        $service = fn (int $n, string $status = 'active') => $remove ? $this->onBoth($n, $status) : $this->goldService($n, $status);
        $service(1);
        $service(2);
        $service(3, VpnServiceStatus::SUSPENDED);
        $service(4, VpnServiceStatus::EXPIRED);
        $query = ['kind' => $kind, 'inbound' => (string) $this->nl->getId(), 'include_suspended' => '1'] + ($remove ? ['grace_hours' => '7'] : []);

        $this->createRun($query);
        $miniApp = $this->creation();
        $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');

        [$code, $output] = $this->command(['--kind' => $kind, '--inbound' => (string) $this->nl->getId(), '--apply' => true, '--include-suspended' => true] + ($remove ? ['--grace-hours' => '7'] : []));
        self::assertSame(0, $code, $output);

        self::assertSame($this->creation(), $miniApp);
        self::assertCount(3, $miniApp['moved']);
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function refusals(): iterable
    {
        yield 'add: keep old' => ['add', ['keep_old' => '1'], 'Keeping the old inbound applies to a move only'];
        yield 'remove: trials' => ['remove', ['include_trials' => '1'], 'Trial services are not part of this'];
        yield 'add: grace' => ['add', ['grace_hours' => '3'], 'has no waiting time'];
        yield 'remove: from inbound' => ['remove', ['from_inbound' => '1'], 'does not apply here'];
        yield 'remove: grace too long' => ['remove', ['grace_hours' => '169'], 'from 0 to 168'];
        yield 'an inbound on a move' => ['', [], 'an inbound is chosen only to add or remove it'];
    }

    /**
     * @param array<string, string> $extra
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

        $answer = $this->post('/apply-inbounds?lang=en', array_filter(['kind' => $kind, 'inbound' => (string) $this->nl->getId()]) + $extra);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString($text, (string) $answer['message']);
        self::assertSame([], $this->runs());
    }

    public function testThePrimaryAndAnInboundStillSoldAreRefusedAsTargets(): void
    {
        $this->goldService(1);

        $answer = $this->post('/apply-inbounds?lang=en', ['kind' => 'remove', 'inbound' => (string) $this->de->getId()]);
        self::assertStringContainsString('never removed', (string) $answer['message']);
        $answer = $this->post('/apply-inbounds?lang=en', ['kind' => 'remove', 'inbound' => (string) $this->nl->getId()]);
        self::assertStringContainsString('still sells', (string) $answer['message']);

        self::assertSame([], $this->runs());
    }

    public function testEveryPostNeedsTheSessionCsrfTokenAndAKind(): void
    {
        $this->goldService(1);

        $this->appPostJson($this->apiPath('/apply-inbounds'), ['token' => $this->session['token'], 'csrf' => 'wrong'], ['kind' => 'add', 'inbound' => (string) $this->nl->getId()], self::newKey());

        self::assertSame(403, $this->httpStatus());
        self::assertSame([], $this->runs());
    }

    public function testPermissionsAsOnTheWeb(): void
    {
        $service = $this->goldService(1);
        $this->createRun(['kind' => 'add', 'inbound' => (string) $this->nl->getId()]);
        $plan = (int) $this->gold->getId();
        foreach ([self::SUPPORT => false, self::CUSTOMER_ONLY => false, self::INACTIVE => false, self::SUPER => true, self::ADMIN => true] as $who => $allowed) {
            $session = $this->authenticate((string) $who);
            $this->appGet('/app/admin/plans/'.$plan.'/apply-inbounds?kind=add', $session);
            self::assertSame($allowed ? 200 : 403, $this->httpStatus(), $who.' apply screen');
            foreach (['/app/api/admin/plans/'.$plan.'/apply-inbounds', '/app/api/admin/plans/'.$plan.'/inbound-move/pause', '/app/api/admin/services/'.$service->getId().'/retry-move'] as $path) {
                $this->appPostJson($path, $session, ['run' => 999999, 'kind' => 'add', 'inbound' => (string) $this->nl->getId()], self::newKey());
                self::assertSame($allowed, 403 !== $this->httpStatus(), $who.' POST '.$path);
            }
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
        $query = 'kind=add&inbound='.$this->nl->getId();
        $this->setting($key, '0', 'boolean');

        $page = $this->screen('/apply-inbounds?lang=en&step=confirm&'.$query);
        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertCount(1, $page->filter(InboundMoveSettings::ENABLED === $key ? '[data-switch-off]' : '[data-multi-off]'));
        self::assertCount(0, $page->filter('form[data-create-form]'));

        $answer = $this->post('/apply-inbounds?lang=en', ['kind' => 'add', 'inbound' => (string) $this->nl->getId()]);
        self::assertStringContainsString($text, (string) $answer['message']);
        self::assertSame([], $this->runs());
    }

    public function testWithMultiInboundOffResumeAndRetryAreRefusedAndUndoStillWorks(): void
    {
        $service = $this->goldService(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'no such inbound'));
        $runId = $this->createRun(['kind' => 'add', 'inbound' => (string) $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['failed'], array_column($this->moves(), 'state'));
        $this->act('pause', $runId);
        $attempts = (int) $this->moves()[0]['attempts'];

        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');
        $block = $this->screen('?lang=en');
        self::assertSame(['cancel', 'undo'], $this->buttons($block));
        self::assertCount(1, $block->filter('[data-multi-off-buttons]'));
        self::assertCount(0, $block->filter('[data-due-hint]'));
        self::assertCount(0, $this->serviceScreen($service->getId())->filter('[data-move-retry]'));

        foreach (['resume', 'retry'] as $action) {
            $answer = $this->appPostJson($this->apiPath('/inbound-move/'.$action.'?lang=en'), $this->session, ['run' => $runId], self::newKey());
            self::assertSame(409, $this->httpStatus(), $action);
            self::assertStringContainsString('Multi-inbound plans are switched off', (string) $answer['message']);
        }
        $answer = $this->appPostJson('/app/api/admin/services/'.$service->getId().'/retry-move?lang=en', $this->session, [], self::newKey());
        self::assertStringContainsString('Multi-inbound plans are switched off', (string) $answer['message']);
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertSame($attempts, (int) $this->moves()[0]['attempts'], 'nothing was re-armed');

        $this->act('undo', $runId);
        self::assertContains($this->moves()[0]['state'], ['undone', 'failed']);
    }

    public function testTheButtonsWorkOnARemoveRun(): void
    {
        $this->sellNoMore();
        $this->onBoth(1);
        $runId = $this->createRun(['kind' => 'remove', 'inbound' => (string) $this->nl->getId(), 'grace_hours' => '5']);
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->screen('?lang=en')));

        $this->act('pause', $runId);
        self::assertSame(['resume', 'cancel', 'undo'], $this->buttons($this->screen('?lang=en')));
        $this->act('resume', $runId);
        self::assertSame('running', $this->runs()[0]['status']);

        $this->act('cancel', $runId);
        self::assertSame('cancelled', $this->runs()[0]['status']);
        self::assertSame(['cancelled'], array_column($this->moves(), 'state'));
        self::assertSame([], $this->panelWrites());
    }

    public function testTheConfirmStepOfUndoUsesTheKindsWordingAndARunOfAnotherPlanIsNotFound(): void
    {
        $service = $this->goldService(1);
        $runId = $this->createRun(['kind' => 'add', 'inbound' => (string) $this->nl->getId()]);
        self::getContainer()->get(InboundMoveScheduler::class)->run();

        $asked = $this->appPostJson($this->apiPath('/inbound-move/undo?lang=en'), $this->session, ['run' => $runId], self::newKey());
        self::assertStringContainsString('still sells this inbound', (string) json_encode($asked));
        self::assertSame([1, 2], $this->onPanel(1), 'asked first: nothing happened yet');

        $answer = $this->act('undo', $runId);
        self::assertStringContainsString('the inbound was taken off 1', (string) $answer['message']);
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
        $this->em->clear();
        self::assertCount(1, $this->managed($this->gold)->getAdditionalInbounds(), 'a run never changes the plan');

        $this->appPostJson('/app/api/admin/plans/999999/inbound-move/pause', $this->session, ['run' => $runId], self::newKey());
        self::assertSame(404, $this->httpStatus());
    }

    public function testRetryIsHiddenForAnAddWhoseUndoStoppedAndTheServiceBlockNamesTheInbound(): void
    {
        $service = $this->goldService(1);
        $runId = $this->createRun(['kind' => 'add', 'inbound' => (string) $this->nl->getId()]);
        $line = $this->serviceScreen($service->getId())->filter('[data-inbound-move]');
        self::assertSame('add', $line->attr('data-move-kind'));
        self::assertSame('Adding inbound: NL-2', trim($line->filter('[data-move-line]')->text()));

        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $line = $this->serviceScreen($service->getId())->filter('[data-inbound-move]');
        self::assertSame('Inbound added: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringNotContainsString('→', $line->text());
        self::assertStringNotContainsString('Old inbound', $line->text());

        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->act('undo', $runId);
        self::assertSame('failed', $this->moves()[0]['state']);

        self::assertNotContains('retry', $this->buttons($this->screen('?lang=en')));
        self::assertCount(0, $this->serviceScreen($service->getId())->filter('[data-move-retry]'));
    }

    public function testAFailedRemoveCanBeRetriedFromTheServiceBlockAndShowsItsRemoval(): void
    {
        $this->sellNoMore();
        $service = $this->onBoth(1);
        $this->createRun(['kind' => 'remove', 'inbound' => (string) $this->nl->getId(), 'grace_hours' => '3']);
        $line = $this->serviceScreen($service->getId())->filter('[data-inbound-move]');
        self::assertSame('Removing inbound: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringContainsString('removed after', $line->text());

        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET detach_after = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertCount(1, $this->serviceScreen($service->getId())->filter('[data-move-retry]'));

        $this->appPostJson('/app/api/admin/services/'.$service->getId().'/retry-move?lang=en', $this->session, [], self::newKey());
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, (int) $this->moves()[0]['attempts']);

        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame('done', $this->moves()[0]['state']);
        $line = $this->serviceScreen($service->getId())->filter('[data-inbound-move]');
        self::assertSame('Inbound removed: NL-2', trim($line->filter('[data-move-line]')->text()));
        self::assertStringContainsString('Removal', $line->text());
        self::assertStringNotContainsString('Old inbound', $line->text());
    }

    public function testTheScreensShowIdsAndTitlesOnlyEscaped(): void
    {
        $service = $this->goldService(1);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'NL <script>alert(1)</script>'], ['id' => $this->nl->getId()]);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'DE <img src=x>'], ['id' => $this->de->getId()]);
        $this->em->clear();
        $query = ['kind' => 'add', 'inbound' => (string) $this->nl->getId()];
        $pages = ['chooser' => $this->rawScreen('/apply-inbounds?lang=en&kind=add'), 'confirm' => $this->rawScreen('/apply-inbounds?lang=en&step=confirm&'.http_build_query($query))];
        $this->createRun($query);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $this->fake->requests = [];
        $pages['plan'] = $this->rawScreen('?lang=en');
        $pages['service'] = (string) (new Crawler($this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $this->session)))->filter('[data-inbound-move]')->html();

        foreach ($pages as $name => $html) {
            self::assertStringNotContainsString('<script>alert(1)</script>', $html, $name);
            self::assertStringNotContainsString('<img src=x>', $html, $name);
            if ('service' === $name) {
                foreach ($this->secretsOf($service) as $secret) {
                    self::assertStringNotContainsString($secret, $html, 'the move block must not show '.$secret);
                }
            }
        }
        self::assertStringContainsString('NL &lt;script&gt;alert(1)&lt;/script&gt;', $pages['service']);
        self::assertSame([], $this->fake->requestLines());
    }

    public function testTheTextsExistInBothLanguages(): void
    {
        $dir = dirname(__DIR__, 3).'/translations/';
        $keys = static fn (array $t): array => array_values(array_filter(array_keys($t), static fn (string $k): bool => str_starts_with($k, 'admin.inbound_move.')));
        $en = $keys(Yaml::parseFile($dir.'miniapp.en.yaml'));
        self::assertSame($en, $keys(Yaml::parseFile($dir.'miniapp.fa.yaml')));
        self::assertContains('admin.inbound_move.removal', $en);
        self::assertContains('admin.inbound_move.plan_on_web', $en);

        $this->goldService(1);
        $page = $this->screen('/apply-inbounds?step=confirm&kind=add&inbound='.$this->nl->getId());
        self::assertStringContainsString('آنچه مشتری می‌بیند', $page->filter('[data-customer-sees]')->text());
    }

    private function planPath(string $suffix): string
    {
        return '/app/admin/plans/'.$this->gold->getId().$suffix;
    }

    private function apiPath(string $suffix): string
    {
        return '/app/api/admin/plans/'.$this->gold->getId().$suffix;
    }

    private function rawScreen(string $suffix): string
    {
        $html = $this->appGet($this->planPath($suffix), $this->session);
        self::assertSame(200, $this->httpStatus(), $suffix.': '.mb_substr(strip_tags($html), 0, 1500));
        $this->em->clear();

        return $html;
    }

    private function screen(string $suffix): Crawler
    {
        return new Crawler($this->rawScreen($suffix));
    }

    private function serviceScreen(mixed $id): Crawler
    {
        $html = $this->appGet('/app/admin/services/'.(int) $id.'?lang=en', $this->session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();

        return new Crawler($html);
    }

    /** @return list<string> */
    private function choices(string $query): array
    {
        return array_values(array_filter($this->screen('/apply-inbounds?lang=en&'.$query)->filter('[data-target-select] option')->each(static fn (Crawler $o): string => (string) $o->attr('value'))));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $suffix, array $body, ?string $key = null): array
    {
        $answer = $this->appPostJson($this->apiPath($suffix), $this->session, $body, $key ?? self::newKey());
        $this->em->clear();

        return $answer;
    }

    /** @return array<string, mixed> */
    private function act(string $action, int $runId): array
    {
        $path = $this->apiPath('/inbound-move/'.$action.'?lang=en');
        $answer = in_array($action, ['cancel', 'undo'], true)
            ? $this->confirmedPost($path, $this->session, ['run' => $runId])
            : $this->appPostJson($path, $this->session, ['run' => $runId], self::newKey());
        self::assertSame(200, $this->httpStatus(), $action.': '.json_encode($answer));
        $this->em->clear();

        return $answer;
    }

    /** @return array<string, string> the hidden fields of the create form, as app.js sends them */
    private function fields(Crawler $form): array
    {
        self::assertCount(1, $form);
        $fields = [];
        $form->filter('input[type="hidden"]')->each(function (Crawler $input) use (&$fields): void {
            $fields[(string) $input->attr('name')] = (string) $input->attr('value');
        });

        return $fields;
    }

    /** @param array<string, string> $query */
    private function createRun(array $query): int
    {
        $this->post('/apply-inbounds', $this->fields($this->screen('/apply-inbounds?'.http_build_query(['step' => 'confirm', 'lang' => 'en'] + $query))->filter('form[data-create-form]')));
        self::assertSame(200, $this->httpStatus());
        $runs = $this->runs();
        self::assertCount(1, $runs);
        $this->fake->requests = [];

        return (int) $runs[0]['id'];
    }

    /** @return list<string> */
    private function buttons(Crawler $page): array
    {
        return $page->filter('[data-run-buttons] button[data-run-action]')->each(static fn (Crawler $b): string => (string) $b->attr('data-run-action'));
    }

    /** @return array<string, string> */
    private function moveChecksums(): array
    {
        $sums = [];
        foreach (['inbound_move_run', 'service_inbound_move', 'vpn_service_inbound', 'admin_audit_log', 'vpn_service'] as $table) {
            $sums[$table] = (string) $this->em->getConnection()->fetchAssociative('CHECKSUM TABLE `'.$table.'`')['Checksum'];
        }

        return $sums;
    }

    /**
     * The run's creation as stored, without its ids or who asked.
     *
     * @return array{run: array<string, mixed>, planned: list<array<string, mixed>>, moved: list<int>, audit: array<string, mixed>}
     */
    private function creation(): array
    {
        $db = $this->em->getConnection();

        return [
            'run' => $db->fetchAssociative('SELECT plan_id, kind, target_inbound_id, scope, grace_hours, keep_old, planned_count, skipped_count FROM inbound_move_run') ?: [],
            'planned' => $db->fetchAllAssociative('SELECT service_id, from_inbound_id, to_inbound_id, from_remote_inbound_id, to_remote_inbound_id, skip_reason FROM service_inbound_move ORDER BY service_id'),
            'moved' => array_map('intval', $db->fetchFirstColumn("SELECT service_id FROM service_inbound_move WHERE state <> 'skipped' ORDER BY service_id")),
            'audit' => array_diff_key(array_values(array_filter($this->audits(), static fn (array $a): bool => 'plan.inbounds_apply_started' === $a['action']))[0]['details'] ?? [], ['run_id' => true]),
        ];
    }
}
