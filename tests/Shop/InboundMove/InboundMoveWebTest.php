<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\AdminUser;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMoveLock;
use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Domain\OrderType;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * PR P7 of docs/design/plan-inbound-change.md (5.2, 5.6, 6.1-6.3, 8.1, the web-controller tests of 8.2): "Apply to
 * existing services" on a plan, its run block and buttons, the service page's move line with "Retry now", the services
 * list's move filters and the doctor counts, in the web panel, on MariaDB and the fake 3x-ui.
 *
 * Every page is read only and asks no panel (the fake's request log stays empty while rendering); every write is a POST
 * with a CSRF token, PLANS_MANAGE for the plan's, SERVICES_MANAGE for the service's; a run made on the page equals one
 * made by app:plan:apply-inbounds with the same options; the pages show service ids and inbound titles only (D5).
 */
final class InboundMoveWebTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
        $this->client = $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
        $this->client->request('GET', '/admin/locale/en');
    }

    public function testTheDryRunCountsLocallyWithTheScopeOptionsAndWritesNothing(): void
    {
        $moving = array_map(fn (int $n) => $this->movableService($n), [1, 2, 3]);
        $suspended = $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 4);
        $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED, 5);
        $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 6, OrderType::TRIAL);
        $this->planService($this->gold, $this->nl, VpnServiceStatus::ACTIVE, 7);
        $this->planService($this->gold, $this->de, VpnServiceStatus::DELETED, 8);
        $this->fake->requests = [];
        $before = $this->checksums() + $this->moveChecksums();

        $page = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds');

        self::assertSame('3', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertSame(['expired' => '1', 'suspended' => '1', 'trial' => '1'], $this->skipCounts($page), 'the planner\'s reasons; the service on NL-2 and the deleted one are not counted');
        self::assertSame(array_map(static fn ($s): string => (string) $s->getId(), $moving), $page->filter('[data-list="moving"] tr[data-service-id]')->each(static fn (Crawler $r): string => (string) $r->attr('data-service-id')));
        self::assertSame((string) $this->nl->getId(), $page->filter('[data-target]')->attr('data-target'));
        self::assertSame(1, $page->filter('[data-continue]')->count());

        $with = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?include_suspended=1&include_expired=1&include_trials=1');
        self::assertSame('6', $with->filter('[data-would-move]')->attr('data-would-move'));
        $one = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?service='.$suspended->getId().'&include_suspended=1&from_inbound='.$this->de->getId());
        self::assertSame('1', $one->filter('[data-would-move]')->attr('data-would-move'));
        $confirm = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm&service='.$moving[0]->getId());
        self::assertSame(1, $confirm->filter('form[data-create-form]')->count());
        self::assertStringContainsString('NL-2', $confirm->filter('[data-phases]')->text());

        self::assertSame([], $this->fake->requestLines(), 'local only: no panel call while rendering');
        self::assertSame($before, $this->checksums() + $this->moveChecksums(), 'no GET writes anything');
    }

    public function testWithTheSwitchOffThePageSaysSoShowsTheCountsAndCreatesNothing(): void
    {
        $this->movableService(1);
        $this->setting('plans.inbound_move_enabled', '0', 'boolean');
        $page = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds');
        self::assertSame(1, $page->filter('[data-switch-off]')->count());
        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertSame(0, $page->filter('[data-continue]')->count());
        self::assertSame(0, $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm')->filter('form[data-create-form]')->count());

        // A crafted POST with a valid token is refused too.
        $this->setting('plans.inbound_move_enabled', '1', 'boolean');
        $form = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm')->filter('form[data-create-form]')->form();
        $this->setting('plans.inbound_move_enabled', '0', 'boolean');
        $this->client->submit($form);
        self::assertSame([], $this->runs());
        self::assertStringContainsString('Moving services is switched off', $this->followedText());
    }

    public function testConfirmingCreatesOnePlannedRunThatTheScheduledTaskProcesses(): void
    {
        array_map(fn (int $n) => $this->movableService($n), [1, 2]);
        $this->fake->requests = [];
        $form = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm&grace_hours=5')->filter('form[data-create-form]')->form();

        $this->client->submit($form);
        $this->client->submit($form);

        self::assertSame([], $this->fake->requestLines(), 'creating a run calls no panel here (single-inbound services)');
        $runs = $this->runs();
        self::assertCount(1, $runs, 'a double submit creates one run');
        self::assertSame(['planned', 5, 0], [$runs[0]['status'], (int) $runs[0]['grace_hours'], (int) $runs[0]['keep_old']]);
        self::assertNotNull($runs[0]['requested_by']);
        self::assertSame(['planned', 'planned'], array_column($this->moves(), 'state'));
        $text = $this->followedText();
        self::assertStringContainsString(sprintf('This plan already has an open run (#%d)', $runs[0]['id']), $text, 'the second submit names the open run');

        $task = self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(1, $task->runs);
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
        $block = $this->get('/admin/plan/'.$this->gold->getId());
        self::assertSame('running', $block->filter('[data-run-status]')->attr('data-run-status'));
        self::assertSame('2', $block->filter('[data-count-done]')->attr('data-count-done'));
        self::assertSame('2', $block->filter('[data-run-counts] [data-count-done]')->attr('data-count-done'));
        // The labels stay: a leading "Word:" with a %count% parameter would be taken for a plural form and cut.
        self::assertSame('Moved: 2 of 2', trim($block->filter('[data-count-done]')->text()));
        self::assertStringStartsWith('Failed: 0', trim($block->filter('[data-count-failed]')->text()));
        self::assertStringStartsWith('Skipped: 0', trim($block->filter('[data-count-skipped]')->text()));
    }

    public function testARunMadeOnThePageEqualsOneMadeByTheCommandWithTheSameOptions(): void
    {
        array_map(fn (int $n) => $this->movableService($n), [1, 2]);
        $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 3);
        $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED, 4);
        $form = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm&include_suspended=1&grace_hours=7')->filter('form[data-create-form]')->form();
        $this->client->submit($form);
        $web = $this->creation();
        $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');

        // The command with the same options creates the run the same way (and then works on it).
        [$code, $output] = $this->command(['--apply' => true, '--include-suspended' => true, '--grace-hours' => '7']);
        self::assertSame(0, $code, $output);
        $cli = $this->creation();

        self::assertSame($cli['run'], $web['run']);
        self::assertSame($cli['skipped'], $web['skipped']);
        self::assertSame($cli['moved'], $web['moved']);
        self::assertSame($cli['audit'], $web['audit'], 'the same plan.inbounds_apply_started entry, counts only');
        self::assertNotNull($web['requestedBy']);
        self::assertNull($cli['requestedBy']);
    }

    public function testOneOpenRunPerPlanAndARunOfTheCommandBlocksTheButton(): void
    {
        $this->movableService(1);
        $form = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm')->filter('form[data-create-form]')->form();
        [$code] = $this->command(['--apply' => true, '--keep-old' => true, '--service' => (string) $this->movableService(2)->getId()]);
        self::assertSame(0, $code);
        // That run finished (keep-old, one service); reopened as an admin's --retire-now would leave it: paused.
        $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'paused', open_plan_id = plan_id, finished_at = NULL, pause_reason = 'test'");

        $this->client->submit($form);

        self::assertCount(1, $this->runs());
        self::assertStringContainsString(sprintf('already has an open run (#%d)', $this->runs()[0]['id']), $this->followedText());
        $page = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm');
        self::assertSame((string) $this->runs()[0]['id'], $page->filter('[data-open-run]')->attr('data-open-run'));
        self::assertSame(0, $page->filter('form[data-create-form]')->count());
        self::assertSame('command line', trim($page->filter('[data-started-by]')->text()));
    }

    public function testEveryPostNeedsItsCsrfTokenAndNoGetChangesAnything(): void
    {
        $service = $this->movableService(1);
        $runId = $this->createRun();
        $before = $this->moveChecksums();
        foreach (['/admin/plan/'.$this->gold->getId().'/apply-inbounds/create', ...array_map(static fn (string $a): string => '/admin/inbound-move-run/'.$runId.'/'.$a, ['pause', 'resume', 'cancel', 'undo', 'retry']), '/admin/vpn-service/'.$service->getId().'/retry-move'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'wrong']);
            self::assertTrue($this->client->getResponse()->isRedirect(), $path);
            self::assertStringContainsString('Invalid or expired security token', $this->followedText(), $path);
            $this->client->request('GET', $path);
            self::assertSame(405, $this->client->getResponse()->getStatusCode(), 'GET '.$path);
        }
        self::assertSame($before, $this->moveChecksums());
        self::assertCount(1, $this->runs());
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function roles(): iterable
    {
        yield 'super admin' => [AdminUser::ROLE_SUPER_ADMIN, true, true];
        yield 'admin' => [AdminUser::ROLE_ADMIN, true, true];
        yield 'support (no PLANS_MANAGE, no SERVICES_MANAGE)' => [AdminUser::ROLE_SUPPORT, false, false];
    }

    #[DataProvider('roles')]
    public function testPermissions(string $role, bool $plans, bool $services): void
    {
        $service = $this->movableService(1);
        $runId = $this->createRun();
        $this->client = $this->webAdmin($role);
        $plan = (int) $this->gold->getId();

        $this->client->request('GET', '/admin/plan/'.$plan.'/apply-inbounds');
        self::assertSame($plans ? 200 : 403, $this->client->getResponse()->getStatusCode());
        foreach (['/admin/plan/'.$plan.'/apply-inbounds/create', '/admin/inbound-move-run/'.$runId.'/pause'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'x']);
            self::assertSame($plans, 403 !== $this->client->getResponse()->getStatusCode(), $role.' POST '.$path);
        }
        $this->client->request('POST', '/admin/vpn-service/'.$service->getId().'/retry-move', ['_token' => 'x']);
        self::assertSame($services, 403 !== $this->client->getResponse()->getStatusCode(), $role.' retry-move');
        self::assertSame('planned', $this->runs()[0]['status'], 'nothing changed');
    }

    public function testTheButtonsFollowTheRunState(): void
    {
        $failing = $this->movableService(1);
        $this->movableService(2);
        $runId = $this->createRun();
        $page = '/admin/plan/'.$this->gold->getId();
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->get($page)), 'planned: undo would cancel the services not started');

        $this->click($page, 'pause');
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertSame(['resume', 'cancel', 'undo'], $this->buttons($this->get($page)));
        self::assertStringContainsString('paused by an admin', $this->get($page)->filter('[data-pause-reason]')->text());

        $this->click($page, 'resume');
        self::assertSame('running', $this->runs()[0]['status']);
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->get($page)));

        // The client of service 1 is on neither inbound: its move fails and gives up (one attempt).
        $this->fake->clients['tg_p1_1']['inboundIds'] = [99];
        $this->fake->saveState();
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['failed', 'switched'], array_column($this->moves(), 'state'));
        $block = $this->get($page);
        self::assertSame(['pause', 'retry', 'cancel', 'undo'], $this->buttons($block));
        self::assertSame('1', $block->filter('[data-count-failed]')->attr('data-count-failed'));
        self::assertStringContainsString('1 with no automatic retry left', $block->filter('[data-count-failed]')->text());

        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $this->fake->saveState();
        $this->click($page, 'retry');
        self::assertSame(0, (int) $this->moves()[0]['attempts']);
        self::assertNotNull($this->moves()[0]['next_attempt_at']);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'), 'retried by the scheduled task');

        $this->click($page, 'cancel');
        self::assertStringContainsString('still wait for their old inbound', $this->followedText());
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->get($page)), 'cancelled with moved services waiting for their retire: still open');
        self::assertSame((string) $failing->getId(), (string) $this->moves()[0]['service_id']);
    }

    public function testUndoOnThePageIsTheCommandsUndoAndRefusesWhileAPassHoldsTheLock(): void
    {
        array_map(fn (int $n) => $this->movableService($n), [1, 2]);
        $runId = $this->createRun();
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
        $page = '/admin/plan/'.$this->gold->getId();
        $form = $this->get($page)->filter('form[data-run-action="undo"]')->form();

        $held = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($held);
        try {
            $this->client->submit($form);
            self::assertStringContainsString('A scheduled run is in progress, try again in a minute', $this->followedText());
            self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'), 'nothing changed');
        } finally {
            $held->release();
        }

        $this->client->submit($form);
        self::assertStringContainsString(sprintf('Undo of run #%d: 2 put back on the old inbound', $runId), $this->followedText());
        self::assertSame(['undone', 'undone'], array_column($this->moves(), 'state'));
        self::assertSame('cancelled', $this->runs()[0]['status']);
        $this->fake->loadState();
        self::assertSame([1], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_undone'], array_column($this->audits(), 'action'), 'the audit entries of the command, nothing per service');
        self::assertSame(['run_id', 'undone', 'failed', 'skipped', 'busy', 'cancelled', 'already_retired'], array_keys($this->audits()[1]['details']));
    }

    public function testUndoIsRefusedForRetiredServicesWithTheCommandsText(): void
    {
        $this->movableService(1);
        $this->createRun(['grace_hours' => '0']);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['done'], array_column($this->moves(), 'state'));
        $block = $this->get('/admin/plan/'.$this->gold->getId());

        self::assertSame('done', $block->filter('[data-run-status]')->attr('data-run-status'));
        self::assertNotContains('undo', $this->buttons($block));
        self::assertSame(
            InboundMoveControls::alreadyRetiredText(1, (int) $this->gold->getId()),
            trim($block->filter('[data-undo-retired]')->text()),
            'the command line\'s text (en)',
        );
        self::assertSame(InboundMoveControls::alreadyRetiredText(4, 9), self::getContainer()->get('translator')->trans('inbound_move.undo_already_retired', ['%count%' => 4, '%plan%' => 9], 'admin', 'en'));
    }

    public function testThePlanChangedSinceTheRunWasCreated(): void
    {
        $this->movableService(1);
        $this->createRun();
        $this->sellOn($this->gold, $this->de);
        $block = $this->get('/admin/plan/'.$this->gold->getId());
        self::assertStringContainsString('Cancel it', $block->filter('[data-plan-changed]')->text());
        self::assertSame((string) $this->nl->getId(), $block->filter('[data-run-target]')->attr('data-run-target'));
    }

    public function testTheServicePageShowsTheMoveWithItsMaskedErrorAndRetriesItWithALimit(): void
    {
        $service = $this->movableService(1);
        $this->createRun();
        $this->fake->clients['tg_p1_1']['inboundIds'] = [99];
        $this->fake->saveState();
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $uuid = (string) $service->getClientUuid();
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET last_error = ?', [sprintf('panel said vless://%s@h:1 for %s', $uuid, $uuid)]);
        $this->fake->requests = [];

        $page = $this->get('/admin/vpn-service/'.$service->getId());
        $line = $page->filter('[data-inbound-move]');
        self::assertSame('failed', $line->attr('data-move-state'));
        self::assertStringContainsString('DE-1', $line->text());
        self::assertStringContainsString('NL-2', $line->text());
        self::assertStringContainsString('next try after', $line->text());
        self::assertStringNotContainsString($uuid, $line->html(), 'the stored error is masked again on display');
        self::assertStringContainsString('vless://[redacted]', $line->html(), 'SecretRedactor keeps the scheme only');
        self::assertSame([], $this->fake->requestLines(), 'no panel call while rendering');

        for ($i = 1; $i <= 6; ++$i) {
            $this->client->submit($this->get('/admin/vpn-service/'.$service->getId())->filter('form[data-move-retry]')->form());
            self::assertStringContainsString(5 >= $i ? 'is due again' : 'Too many retries', $this->followedText(), 'try '.$i);
        }
        self::assertSame(0, (int) $this->moves()[0]['attempts']);
    }

    public function testThePagesShowIdsAndTitlesOnlyEscaped(): void
    {
        $service = $this->movableService(1);
        $this->em->getConnection()->update('plan', ['title' => 'Gold <b>"&\''], ['id' => $this->gold->getId()]);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'NL <script>alert(1)</script>'], ['id' => $this->nl->getId()]);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'DE <img src=x>'], ['id' => $this->de->getId()]);
        $this->createRun();
        $this->fake->requests = [];

        $pages = [
            'apply' => $this->html('/admin/plan/'.$this->gold->getId().'/apply-inbounds'),
            'confirm' => $this->html('/admin/plan/'.$this->gold->getId().'/apply-inbounds?step=confirm'),
            'plan' => $this->html('/admin/plan/'.$this->gold->getId()),
            'service' => (string) (new Crawler($this->html('/admin/vpn-service/'.$service->getId())))->filter('[data-inbound-move]')->html(),
        ];
        foreach ($pages as $name => $html) {
            self::assertStringNotContainsString('<script>alert(1)</script>', $html, $name);
            self::assertStringNotContainsString('<img src=x>', $html, $name);
            self::assertStringNotContainsString('<b>"&', $html, $name);
            foreach ([...$this->secretsOf($service), 'Owner Kaveh', '8940001'] as $secret) {
                self::assertStringNotContainsString($secret, $html, $name.' must not show '.$secret);
            }
        }
        self::assertStringContainsString('NL &lt;script&gt;alert(1)&lt;/script&gt;', $pages['plan']);
        self::assertStringContainsString('Gold &lt;b&gt;&quot;&amp;&#039;', $pages['apply']);
        self::assertSame([], $this->fake->requestLines());
    }

    public function testTheServicesListFiltersByMoveInOneQueryPerPage(): void
    {
        $services = array_map(fn (int $n) => $this->movableService($n), [1, 2, 3, 4]);
        $this->createRun();
        $db = $this->em->getConnection();
        $counts = [];
        foreach ([1, 3] as $failed) {
            $db->executeStatement("UPDATE service_inbound_move SET state = 'planned', next_attempt_at = NULL");
            $db->executeStatement(sprintf("UPDATE service_inbound_move SET state = 'failed', next_attempt_at = NOW() ORDER BY id LIMIT %d", $failed));
            $counter = self::getContainer()->get(QueryCounter::class);
            $counter->start();
            $list = $this->get('/admin/vpn-service?moves=failed');
            $counts[] = $counter->stop();
            self::assertCount($failed, $list->filter('table.datagrid tbody tr[data-id]'), 'failed: '.$failed);
        }
        self::assertSame($counts[0], $counts[1], 'the same number of queries for 1 and 3 rows');
        $moving = $this->get('/admin/vpn-service?moves=moving');
        self::assertCount(1, $moving->filter('table.datagrid tbody tr[data-id]'), 'the one planned move is on its way, not the three failed ones');
        $db->executeStatement("UPDATE service_inbound_move SET state = 'planned', next_attempt_at = NULL");
        self::assertCount(4, $this->get('/admin/vpn-service?moves=moving')->filter('table.datagrid tbody tr[data-id]'));
        $db->executeStatement("UPDATE service_inbound_move SET state = 'skipped' ORDER BY id LIMIT 3");
        $moving = $this->get('/admin/vpn-service?moves=moving');
        self::assertSame([(int) $services[3]->getId()], $moving->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $r): int => (int) $r->attr('data-id')));
        self::assertStringContainsString('Move failed', (string) $this->client->getResponse()->getContent());
    }

    public function testThePanelAndTheDoctorCountFailedAndGivenUpMoves(): void
    {
        $this->movableService(1);
        $this->movableService(2);
        $this->createRun();
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET state = 'failed', next_attempt_at = NULL, attempts = 3");
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = NOW() ORDER BY id LIMIT 1");

        $panel = $this->get('/admin/vpn-panel/'.$this->modernPanel->getId());
        self::assertSame('2/1', $panel->filter('[data-inbound-moves]')->attr('data-inbound-moves'));
        self::assertSame('2/1', $this->get('/admin/plan/'.$this->gold->getId())->filter('[data-move-problems]')->attr('data-move-problems'));
        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))->find('app:panel:doctor'));
        $tester->execute(['panelId' => (string) $this->modernPanel->getId()]);
        self::assertMatchesRegularExpression('/inbound moves +2 failed \(1 given up/', $tester->getDisplay());
    }

    public function testNothingSecretInTheLogsOrFlashesOfAWebUndoWithAThrowingPanel(): void
    {
        foreach (['0', '1'] as $ignoreArgs) {
            $before = ini_get('zend.exception_ignore_args');
            ini_set('zend.exception_ignore_args', $ignoreArgs);
            try {
                $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
                $service = $this->movableService(10 + (int) $ignoreArgs);
                $email = (string) $service->getClientEmail();
                $this->createRun(['service' => (string) $service->getId()]);
                self::getContainer()->get(InboundMoveScheduler::class)->run();
                $leak = sprintf('client %s (%s) at %s/secret/x token %s vless://%s@h', $email, $service->getClientUuid(), Fake3xuiPanel::ORIGIN, Fake3xuiPanel::TOKEN, $service->getClientUuid());
                $this->fake->script('GET', 'panel/api/clients/get/'.$email, static function () use ($leak): MockResponse {
                    throw new \RuntimeException($leak);
                });
                $logger = self::getContainer()->get(RecordingLogger::class);
                $logs = count($logger->records);
                $this->click('/admin/plan/'.$this->gold->getId(), 'undo');
                $flash = $this->followedOrCurrent();
                $page = $this->html('/admin/vpn-service/'.$service->getId());
                $text = implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), array_slice($logger->records, $logs)));
                self::assertStringContainsString('1 failed', $flash, 'the undo really failed: the test proves something');
                foreach ([(string) $service->getClientUuid(), Fake3xuiPanel::TOKEN, '/secret/', 'vless://'] as $secret) {
                    self::assertStringNotContainsString($secret, $text, 'logs, ignore_args='.$ignoreArgs);
                    self::assertStringNotContainsString($secret, $flash, 'flash, ignore_args='.$ignoreArgs);
                    self::assertStringNotContainsString($secret, (string) (new Crawler($page))->filter('[data-inbound-move]')->html(), 'service line, ignore_args='.$ignoreArgs);
                }
            } finally {
                ini_set('zend.exception_ignore_args', (string) $before);
            }
        }
    }

    public function testTheNewTextsExistInBothLanguagesWithTheSameKeys(): void
    {
        $en = Yaml::parseFile(dirname(__DIR__, 3).'/translations/admin.en.yaml');
        $fa = Yaml::parseFile(dirname(__DIR__, 3).'/translations/admin.fa.yaml');
        self::assertSame(array_keys($en['inbound_move']), array_keys($fa['inbound_move']));
        foreach (['plan_saved_apply', 'form_apply', 'service_apply'] as $key) {
            self::assertStringEndsWith('Use "Apply to existing services" on the plan to move them.', $en['plan_inbound_drift'][$key]);
            self::assertStringContainsString('«اعمال روی سرویس‌های موجود»', $fa['plan_inbound_drift'][$key]);
        }
        foreach (['moves_moving', 'moves_failed'] as $key) {
            self::assertArrayHasKey('tabs.'.$key, $fa['service']);
        }
        foreach ((new \ReflectionClass(\App\Provisioning\Application\InboundMovePlanner::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'SKIP_')) {
                self::assertArrayHasKey('skip.'.$value, $en['inbound_move'], $name);
            }
        }
        foreach (\App\Entity\ServiceInboundMove::STATES as $state) {
            self::assertArrayHasKey('state.'.$state, $fa['inbound_move']);
        }
        $this->client->request('GET', '/admin/locale/fa');
        self::assertStringContainsString('اعمال روی سرویس‌های موجود', $this->html('/admin/plan/'.$this->gold->getId().'/apply-inbounds'));
        self::assertStringContainsString('اعمال روی سرویس‌های موجود', (new Crawler($this->html('/admin/plan/'.$this->gold->getId())))->filter('[data-apply-link]')->text());
    }

    /**
     * The run's creation as stored: the run's own options and counts, the skipped moves, the moved services and the
     * audit entry (ids and counts).
     *
     * @return array{run: array<string, mixed>, skipped: list<array<string, mixed>>, moved: list<int>, audit: array<string, mixed>, requestedBy: mixed}
     */
    private function creation(): array
    {
        $db = $this->em->getConnection();
        $run = $db->fetchAssociative('SELECT plan_id, kind, target_inbound_id, scope, grace_hours, keep_old, planned_count, skipped_count, requested_by FROM inbound_move_run') ?: [];
        $requestedBy = $run['requested_by'] ?? null;
        unset($run['requested_by']);

        return [
            'run' => $run,
            'skipped' => $db->fetchAllAssociative("SELECT service_id, from_inbound_id, to_inbound_id, from_remote_inbound_id, to_remote_inbound_id, skip_reason FROM service_inbound_move WHERE state = 'skipped' ORDER BY service_id"),
            'moved' => array_map('intval', $db->fetchFirstColumn("SELECT service_id FROM service_inbound_move WHERE state <> 'skipped' ORDER BY service_id")),
            // The run's id differs (a second run); everything else of the entry is compared.
            'audit' => array_diff_key(array_values(array_filter($this->audits(), static fn (array $a): bool => 'plan.inbounds_apply_started' === $a['action']))[0]['details'] ?? [], ['run_id' => true]),
            'requestedBy' => $requestedBy,
        ];
    }

    /** @param array<string, string> $query */
    private function createRun(array $query = []): int
    {
        $client = $this->client;
        $this->client = $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
        $form = $this->get('/admin/plan/'.$this->gold->getId().'/apply-inbounds?'.http_build_query(['step' => 'confirm'] + $query))->filter('form[data-create-form]')->form();
        $this->client->submit($form);
        $this->client = $client;
        $runs = $this->runs();
        self::assertCount(1, $runs);

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

    /** @return array<string, string> */
    private function skipCounts(Crawler $page): array
    {
        $counts = [];
        $page->filter('[data-summary] [data-skip-reason]')->each(function (Crawler $li) use (&$counts): void {
            $counts[(string) $li->attr('data-skip-reason')] = (string) $li->attr('data-skip-count');
        });
        ksort($counts);

        return $counts;
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

    /** The page after the redirect of a POST, as text (the flash messages included). */
    private function followedText(): string
    {
        self::assertTrue($this->client->getResponse()->isRedirect(), mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1000));
        $this->client->followRedirect();
        $this->em->clear();

        return html_entity_decode(strip_tags((string) $this->client->getResponse()->getContent()), ENT_QUOTES);
    }

    private function followedOrCurrent(): string
    {
        if ($this->client->getResponse()->isRedirect()) {
            return $this->followedText();
        }

        return html_entity_decode(strip_tags((string) $this->client->getResponse()->getContent()), ENT_QUOTES);
    }

    /** @return array<string, string> */
    private function moveChecksums(): array
    {
        $sums = [];
        foreach (['inbound_move_run', 'service_inbound_move', 'admin_audit_log'] as $table) {
            $sums[$table] = (string) $this->em->getConnection()->fetchAssociative('CHECKSUM TABLE `'.$table.'`')['Checksum'];
        }

        return $sums;
    }

    private function webAdmin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('p7-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $client->request('GET', '/admin/locale/en');

        return $client;
    }
}
