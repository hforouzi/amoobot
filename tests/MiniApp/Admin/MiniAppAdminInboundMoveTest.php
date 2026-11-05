<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\AdminUser;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMoveLock;
use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Shop\InboundMove\InboundMoveFixtures;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\QueryCounter;
use App\Tests\Support\RecordingLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * PR P9 of docs/design/plan-inbound-change.md (the Mini App parts of 5.2 and 5.6): "Apply to existing services", the
 * run block and its buttons, the service's move block with "Retry now" and the services list's move filters, in the
 * Mini App admin, on MariaDB and the fake 3x-ui. The same actions as the web panel (P7), through the same code
 * (InboundMoveActions): a run made here equals one made on the web or by app:plan:apply-inbounds with the same options.
 *
 * Every screen is read only and asks no panel (the fake's request log stays empty while rendering); every write is a
 * POST with the session's CSRF token, PLANS_MANAGE for the plan's, SERVICES_MANAGE for the service's; the screens show
 * service ids and inbound titles only (D5).
 */
final class MiniAppAdminInboundMoveTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use InboundMoveFixtures {
        BotAdminTestTrait::requestBody insteadof InboundMoveFixtures;
    }
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    /** @var array{token: string, csrf: string} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
        $this->setUpMove();
        $this->session = $this->authenticate(self::ADMIN);
    }

    public function testTheDryRunIsCountedLocallyWithTheScopeOptionsAndWritesNothing(): void
    {
        $one = $this->movableService(1);
        $this->movableService(2);
        $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 3);
        $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED, 4);
        $this->fake->requests = [];
        $before = $this->moveChecksums();

        $page = $this->screen('/apply-inbounds?lang=en');
        self::assertSame('dry_run', $page->filter('[data-step]')->attr('data-step'));
        self::assertSame('2', $page->filter('[data-would-move]')->attr('data-would-move'));
        self::assertSame(['expired' => '1', 'suspended' => '1'], $this->skipCounts($page));
        self::assertCount(1, $page->filter('form[data-scope-form]'));
        self::assertCount(1, $page->filter('a[data-continue]'));
        self::assertStringContainsString('the panel is not asked on this page', $page->text());

        $wider = $this->screen('/apply-inbounds?lang=en&include_suspended=1&include_expired=1&grace_hours=12');
        self::assertSame('4', $wider->filter('[data-would-move]')->attr('data-would-move'));
        // One service first, typed with Persian digits as every Mini App admin form accepts them.
        $first = $this->screen('/apply-inbounds?lang=en&service='.strtr((string) $one->getId(), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']));
        self::assertSame('1', $first->filter('[data-would-move]')->attr('data-would-move'));
        self::assertSame((string) $one->getId(), $first->filter('[data-list="moving"] [data-service-id]')->attr('data-service-id'));
        $bad = $this->screen('/apply-inbounds?lang=en&grace_hours=999');
        self::assertStringContainsString('from 0 to 168', $bad->filter('[data-scope-error]')->text());

        self::assertSame([], $this->fake->requestLines(), 'no panel request while rendering');
        self::assertSame($before, $this->moveChecksums(), 'nothing written');
    }

    public function testWithTheSwitchOffTheScreenSaysSoShowsTheCountsAndCreatesNothing(): void
    {
        $this->movableService(1);
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');

        $page = $this->screen('/apply-inbounds?lang=en&step=confirm');
        self::assertCount(1, $page->filter('[data-switch-off]'));
        self::assertSame('1', $page->filter('[data-would-move]')->attr('data-would-move'), 'the counts still show');
        self::assertCount(0, $page->filter('form[data-create-form]'));
        self::assertCount(0, $page->filter('a[data-continue]'));

        $answer = $this->post('/apply-inbounds?lang=en', []);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('switched off', (string) $answer['message']);
        self::assertSame([], $this->runs());
    }

    public function testConfirmingCreatesOnePlannedRunADoubleTapIsOneAndTheTaskProcessesIt(): void
    {
        array_map(fn (int $n) => $this->movableService($n), [1, 2]);
        $this->fake->requests = [];
        $page = $this->screen('/apply-inbounds?lang=en&step=confirm&grace_hours=5');
        self::assertSame('confirm', $page->filter('[data-step]')->attr('data-step'));
        self::assertStringContainsString('NL-2', $page->filter('[data-phases]')->text());
        self::assertStringContainsString('5 hour(s) after its switch', $page->filter('[data-phases]')->text());
        self::assertCount(1, $page->filter('[data-one-first]'));
        $fields = $this->fields($page->filter('form[data-create-form]'));
        self::assertSame(['grace_hours' => '5'], $fields);

        $key = self::newKey();
        $first = $this->post('/apply-inbounds?lang=en', $fields, $key);
        self::assertSame(200, $this->httpStatus(), json_encode($first));
        $again = $this->post('/apply-inbounds?lang=en', $fields, $key);
        self::assertSame($first, $again, 'a double tap (same Idempotency-Key) gets the first answer');
        $other = $this->post('/apply-inbounds?lang=en', $fields);
        self::assertSame(409, $this->httpStatus());

        $runs = $this->runs();
        self::assertCount(1, $runs, 'one run');
        self::assertStringContainsString(sprintf('Run #%d was created: 2 service(s) will be moved', $runs[0]['id']), (string) $first['message']);
        self::assertSame('/app/admin/plans/'.$this->gold->getId().'#inbound-move', $first['redirect']);
        self::assertStringContainsString(sprintf('already has an open run (#%d)', $runs[0]['id']), (string) $other['message']);
        self::assertSame(['planned', 5, (int) $this->adminUser->getId()], [$runs[0]['status'], (int) $runs[0]['grace_hours'], (int) $runs[0]['requested_by']]);
        self::assertSame([], $this->fake->requestLines(), 'creating calls no panel here (single-inbound services)');
        $audit = $this->em->getConnection()->fetchAssociative("SELECT source, actor_label, actor_admin_user_id FROM admin_audit_log WHERE action = 'plan.inbounds_apply_started'");
        self::assertSame(['miniapp', (string) $this->adminUser->getId()], [$audit['source'] ?? null, (string) ($audit['actor_admin_user_id'] ?? '')], 'this admin, from the Mini App');

        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
        $block = $this->screen('?lang=en');
        self::assertSame('running', $block->filter('[data-run-status]')->attr('data-run-status'));
        self::assertSame('2', $block->filter('[data-count-done]')->attr('data-count-done'));
        // The labels stay: a leading "Word:" with a %count% parameter would be taken for a plural form and cut.
        self::assertSame(['Moved: 2 of 2', 'Failed: 0 (0 with no automatic retry left)', 'Skipped: 0'], array_map(static fn (string $t): string => trim($t), $block->filter('[data-count-done], [data-count-failed], [data-count-skipped]')->each(static fn (Crawler $p): string => $p->text())));
        self::assertSame('admin@example.test', trim($block->filter('[data-started-by]')->text()));
    }

    public function testARunMadeInTheMiniAppEqualsOneMadeOnTheWebAndByTheCommand(): void
    {
        array_map(fn (int $n) => $this->movableService($n), [1, 2]);
        $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 3);
        $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED, 4);
        $options = ['include_suspended' => '1', 'grace_hours' => '7'];

        $this->post('/apply-inbounds', $this->fields($this->screen('/apply-inbounds?step=confirm&'.http_build_query($options))->filter('form[data-create-form]')));
        self::assertSame(200, $this->httpStatus());
        $miniApp = $this->creation();
        $this->reset();

        $web = $this->webClient();
        $web->request('GET', '/admin/plan/'.$this->gold->getId().'/apply-inbounds?'.http_build_query(['step' => 'confirm'] + $options));
        $web->submit($web->getCrawler()->filter('form[data-create-form]')->form());
        $fromWeb = $this->creation();
        $this->reset();

        [$code, $output] = $this->command(['--apply' => true, '--include-suspended' => true, '--grace-hours' => '7']);
        self::assertSame(0, $code, $output);
        $cli = $this->creation();

        foreach (['run', 'skipped', 'moved', 'audit'] as $part) {
            self::assertSame($cli[$part], $miniApp[$part], $part.': Mini App = command');
            self::assertSame($fromWeb[$part], $miniApp[$part], $part.': Mini App = web');
        }
        self::assertSame((int) $this->adminUser->getId(), (int) $miniApp['requestedBy']);
    }

    public function testOneOpenRunPerPlanBlocksTheCreateButton(): void
    {
        $this->movableService(1);
        [$code] = $this->command(['--apply' => true, '--keep-old' => true]);
        self::assertSame(0, $code);
        $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'paused', open_plan_id = plan_id, finished_at = NULL, pause_reason = 'test'");
        $runId = (int) $this->runs()[0]['id'];

        $page = $this->screen('/apply-inbounds?lang=en&step=confirm');
        self::assertSame((string) $runId, $page->filter('[data-open-run]')->attr('data-open-run'));
        self::assertCount(0, $page->filter('form[data-create-form]'));
        self::assertStringContainsString('not recorded (command line', $page->filter('[data-started-by]')->text());
        $answer = $this->post('/apply-inbounds?lang=en', []);
        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString(sprintf('already has an open run (#%d)', $runId), (string) $answer['message']);
        self::assertCount(1, $this->runs());
    }

    public function testEveryPostNeedsTheSessionCsrfTokenAndNoGetChangesAnything(): void
    {
        $service = $this->movableService(1);
        $runId = $this->createRun();
        $before = $this->moveChecksums();
        $plan = '/app/api/admin/plans/'.$this->gold->getId();
        foreach ([$plan.'/apply-inbounds', ...array_map(static fn (string $a): string => $plan.'/inbound-move/'.$a, ['pause', 'resume', 'retry', 'cancel', 'undo']), '/app/api/admin/services/'.$service->getId().'/retry-move'] as $path) {
            $answer = $this->appPostJson($path, $this->session, ['run' => $runId], self::newKey(), 'wrong-csrf');
            self::assertSame(403, $this->httpStatus(), $path);
            self::assertSame('csrf', $answer['error'] ?? null, $path);
            $this->appGet($path, $this->session);
            self::assertContains($this->httpStatus(), [404, 405], 'GET '.$path);
        }
        self::assertSame($before, $this->moveChecksums());
    }

    public function testPermissionsAsOnTheWeb(): void
    {
        $service = $this->movableService(1);
        $runId = $this->createRun();
        $plan = (int) $this->gold->getId();
        foreach ([self::SUPPORT => false, self::CUSTOMER_ONLY => false, self::INACTIVE => false, self::SUPER => true, self::ADMIN => true] as $who => $allowed) {
            $session = $this->authenticate((string) $who);
            $this->appGet('/app/admin/plans/'.$plan.'/apply-inbounds', $session);
            self::assertSame($allowed ? 200 : 403, $this->httpStatus(), $who.' apply screen');
            foreach (['/app/api/admin/plans/'.$plan.'/apply-inbounds', '/app/api/admin/plans/'.$plan.'/inbound-move/pause', '/app/api/admin/services/'.$service->getId().'/retry-move'] as $path) {
                $this->appPostJson($path, $session, ['run' => 999999], self::newKey());
                self::assertSame($allowed, 403 !== $this->httpStatus(), $who.' POST '.$path);
            }
        }
        self::assertSame('planned', $this->runs()[0]['status'], 'nothing changed');
        self::assertSame($runId, (int) $this->runs()[0]['id']);
    }

    public function testTheButtonsFollowTheRunStateResumeAndRetryOnlyMakeTheMovesDue(): void
    {
        $this->movableService(1);
        $this->movableService(2);
        $runId = $this->createRun();
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->screen('?lang=en')));

        $answer = $this->act('pause', $runId);
        self::assertStringContainsString('is paused', (string) $answer['message']);
        self::assertSame(['resume', 'cancel', 'undo'], $this->buttons($this->screen('?lang=en')));
        $answer = $this->act('resume', $runId);
        self::assertStringContainsString('continues at the next pass of the scheduled task', (string) $answer['message']);
        self::assertSame('running', $this->runs()[0]['status']);
        self::assertSame([], $this->fake->requestLines(), 'resume only makes the run due: no pass in the request');

        $this->fake->clients['tg_p1_1']['inboundIds'] = [99];
        $this->fake->saveState();
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $block = $this->screen('?lang=en');
        self::assertSame(['pause', 'retry', 'cancel', 'undo'], $this->buttons($block));
        self::assertStringContainsString('the scheduled task works on them at its next pass', $block->filter('[data-due-hint]')->text());

        $this->fake->requests = [];
        $answer = $this->act('retry', $runId);
        self::assertStringContainsString('the scheduled task tries them at its next pass', (string) $answer['message']);
        self::assertSame([0, 'failed'], [(int) $this->moves()[0]['attempts'], $this->moves()[0]['state']], 'due again, not tried in the request');
        self::assertSame([], $this->fake->requestLines());

        $cancel = $this->act('cancel', $runId);
        self::assertStringContainsString('still wait for their old inbound', (string) $cancel['message']);
        self::assertSame(['pause', 'cancel', 'undo'], $this->buttons($this->screen('?lang=en')), 'cancelled with a moved service waiting for its retire: still open');

        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        self::assertSame(['pause', 'cancel'], $this->buttons($this->screen('?lang=en')), 'switch off: no resume, retry or undo');
    }

    public function testUndoIsTheCommandsUndoConfirmedFirstAndRefusedWhileAPassHoldsTheLock(): void
    {
        array_map(fn (int $n) => $this->movableService($n), [1, 2]);
        $runId = $this->createRun();
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $path = '/app/api/admin/plans/'.$this->gold->getId().'/inbound-move/undo?lang=en';

        $held = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($held);
        try {
            $locked = $this->confirmedPost($path, $this->session, ['run' => $runId]);
            self::assertStringContainsString('A scheduled run is in progress', (string) $locked['message']);
            self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
        } finally {
            $held->release();
        }

        $answer = $this->confirmedPost($path, $this->session, ['run' => $runId]);
        self::assertSame(200, $this->httpStatus(), json_encode($answer));
        self::assertStringContainsString(sprintf('Undo of run #%d: 2 put back on the old inbound', $runId), (string) $answer['message']);
        self::assertSame(['undone', 'undone'], array_column($this->moves(), 'state'));
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_undone'], array_column($this->audits(), 'action'), 'the command\'s entries, nothing per service');
    }

    public function testUndoIsRefusedForRetiredServicesWithTheCommandsTextAndARunOfAnotherPlanIsNotFound(): void
    {
        $this->movableService(1);
        $runId = $this->createRun(['grace_hours' => '0']);
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $block = $this->screen('?lang=en');
        self::assertNotContains('undo', $this->buttons($block));
        self::assertSame(InboundMoveControls::alreadyRetiredText(1, (int) $this->gold->getId()), trim($block->filter('[data-undo-retired]')->text()));

        $other = $this->createPlan(1000, $this->managed($this->nl));
        $this->em->flush();
        $this->appPostJson('/app/api/admin/plans/'.$other->getId().'/inbound-move/pause', $this->session, ['run' => $runId], self::newKey());
        self::assertSame(404, $this->httpStatus(), 'a run is acted on through its own plan only');
    }

    public function testThePlanChangedSinceTheRunWasCreated(): void
    {
        $this->movableService(1);
        $this->createRun();
        $this->sellOn($this->gold, $this->de);
        $block = $this->screen('?lang=en');
        self::assertStringContainsString('Cancel it', $block->filter('[data-plan-changed]')->text());
        self::assertSame((string) $this->nl->getId(), $block->filter('[data-run-target]')->attr('data-run-target'));
    }

    public function testTheServiceBlockShowsTheMaskedMoveAndRetriesItWithTheWebsLimiter(): void
    {
        $service = $this->movableService(1);
        $this->createRun();
        $this->fake->clients['tg_p1_1']['inboundIds'] = [99];
        $this->fake->saveState();
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        $uuid = (string) $service->getClientUuid();
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET last_error = ?', [sprintf('panel said vless://%s@h:1 for %s', $uuid, $uuid)]);
        $this->fake->requests = [];

        $block = (new Crawler($this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $this->session)))->filter('[data-inbound-move]');
        self::assertSame(200, $this->httpStatus());
        self::assertSame('failed', $block->attr('data-move-state'));
        self::assertStringContainsString('DE-1 → NL-2', $block->text());
        self::assertStringNotContainsString($uuid, $block->html());
        self::assertStringContainsString('vless://[redacted]', $block->html());
        self::assertCount(1, $block->filter('[data-move-retry]'));
        self::assertSame([], $this->fake->requestLines(), 'no panel call while rendering');

        $path = '/app/api/admin/services/'.$service->getId().'/retry-move?lang=en';
        for ($i = 1; $i <= 6; ++$i) {
            $answer = $this->appPostJson($path, $this->session, [], self::newKey());
            self::assertSame(5 >= $i ? 200 : 429, $this->httpStatus(), 'try '.$i);
            self::assertStringContainsString(5 >= $i ? 'is due again' : 'Too many retries', (string) $answer['message']);
        }
        // The same limiter as the web's "Retry now" (per service): the web is refused too now.
        $web = $this->webClient();
        $web->request('GET', '/admin/vpn-service/'.$service->getId());
        $web->submit($web->getCrawler()->filter('form[data-move-retry]')->form());
        $web->followRedirect();
        self::assertStringContainsString('Too many retries', (string) $web->getResponse()->getContent());
    }

    public function testTheScreensShowIdsAndTitlesOnlyEscaped(): void
    {
        $service = $this->movableService(1);
        $this->em->getConnection()->update('plan', ['title' => 'Gold <b>"&\''], ['id' => $this->gold->getId()]);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'NL <script>alert(1)</script>'], ['id' => $this->nl->getId()]);
        $this->em->getConnection()->update('vpn_inbound', ['title' => 'DE <img src=x>'], ['id' => $this->de->getId()]);
        $runId = $this->createRun();
        $this->fake->requests = [];

        $screens = [
            'apply' => $this->appGet($this->planPath('/apply-inbounds?lang=en'), $this->session),
            'confirm' => $this->appGet($this->planPath('/apply-inbounds?lang=en&step=confirm'), $this->session),
            'plan' => (new Crawler($this->appGet($this->planPath('?lang=en'), $this->session)))->filter('#inbound-move')->outerHtml(),
            'service' => (new Crawler($this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $this->session)))->filter('[data-inbound-move]')->outerHtml(),
            'answer' => json_encode($this->act('pause', $runId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        foreach ($screens as $name => $html) {
            self::assertStringNotContainsString('<script>alert(1)</script>', $name === 'answer' ? '' : (string) $html, $name);
            self::assertStringNotContainsString('<img src=x>', 'answer' === $name ? '' : (string) $html, $name);
            foreach ([...$this->secretsOf($service), 'Owner Kaveh', '8940001'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $html, $name.' must not show '.$secret);
            }
            self::assertDoesNotMatchRegularExpression('/\b(?:inbound_move|admin\.inbound_move)\.[a-z_.]+\b/', strip_tags((string) $html), $name.': a raw key');
        }
        self::assertStringContainsString('NL &lt;script&gt;alert(1)&lt;/script&gt;', (string) $screens['plan']);
        self::assertStringContainsString('Gold &lt;b&gt;&quot;&amp;&#039;', (string) $screens['apply']);
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
            $list = new Crawler($this->appGet('/app/admin/services?moves=failed&lang=en', $this->session));
            $counts[] = $counter->stop();
            self::assertSame(200, $this->httpStatus());
            self::assertCount($failed, $list->filter('[data-service-id]'), 'failed: '.$failed);
            self::assertSame((string) $failed, trim($list->filter('[data-chip="moves_failed"] .chip-count')->text()));
        }
        self::assertSame($counts[0], $counts[1], 'the same number of queries for 1 and 3 rows');
        $moving = new Crawler($this->appGet('/app/admin/services?moves=moving&lang=en', $this->session));
        self::assertSame([(int) $services[3]->getId()], $moving->filter('[data-service-id]')->each(static fn (Crawler $r): int => (int) $r->attr('data-service-id')));
        self::assertSame('true', $moving->filter('[data-chip="moves_moving"]')->attr('aria-current'));

        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        self::assertCount(0, (new Crawler($this->appGet('/app/admin/services?lang=en', $this->session)))->filter('[data-move-filters]'), 'switch off: no move chips');
    }

    public function testNothingSecretInTheLogsOrAnswerOfAnUndoWithAThrowingPanel(): void
    {
        foreach (['0', '1'] as $ignoreArgs) {
            $before = ini_get('zend.exception_ignore_args');
            ini_set('zend.exception_ignore_args', $ignoreArgs);
            try {
                $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
                $service = $this->movableService(10 + (int) $ignoreArgs);
                $email = (string) $service->getClientEmail();
                $runId = $this->createRun(['service' => (string) $service->getId()]);
                self::getContainer()->get(InboundMoveScheduler::class)->run();
                $leak = sprintf('client %s (%s) at %s/secret/x token %s vless://%s@h', $email, $service->getClientUuid(), Fake3xuiPanel::ORIGIN, Fake3xuiPanel::TOKEN, $service->getClientUuid());
                $this->fake->script('GET', 'panel/api/clients/get/'.$email, static function () use ($leak): MockResponse {
                    throw new \RuntimeException($leak);
                });
                $logger = self::getContainer()->get(RecordingLogger::class);
                $logs = count($logger->records);
                $answer = $this->confirmedPost('/app/api/admin/plans/'.$this->gold->getId().'/inbound-move/undo?lang=en', $this->session, ['run' => $runId]);
                $body = (string) $this->client->getResponse()->getContent();
                $block = (new Crawler($this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $this->session)))->filter('[data-inbound-move]')->outerHtml();
                $text = implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), array_slice($logger->records, $logs)));
                self::assertStringContainsString('1 failed', (string) ($answer['message'] ?? ''), 'the undo really failed: the test proves something');
                foreach ([(string) $service->getClientUuid(), Fake3xuiPanel::TOKEN, '/secret/', 'vless://'.$service->getClientUuid()] as $secret) {
                    self::assertStringNotContainsString($secret, $text, 'logs, ignore_args='.$ignoreArgs);
                    self::assertStringNotContainsString($secret, $body, 'answer, ignore_args='.$ignoreArgs);
                    self::assertStringNotContainsString($secret, $block, 'service block, ignore_args='.$ignoreArgs);
                }
            } finally {
                ini_set('zend.exception_ignore_args', (string) $before);
            }
        }
    }

    public function testTheTextsExistInBothLanguagesAndTheP1NoticesNameApply(): void
    {
        $dir = dirname(__DIR__, 3).'/translations/';
        $en = Yaml::parseFile($dir.'miniapp.en.yaml');
        $fa = Yaml::parseFile($dir.'miniapp.fa.yaml');
        $keys = static fn (array $t): array => array_values(array_filter(array_keys($t), static fn (string $k): bool => str_starts_with($k, 'admin.inbound_move.')));
        self::assertSame($keys($en), $keys($fa));
        self::assertGreaterThanOrEqual(10, count($keys($en)));
        $adminEn = Yaml::parseFile($dir.'admin.en.yaml');
        $adminFa = Yaml::parseFile($dir.'admin.fa.yaml');
        self::assertSame(array_keys($adminEn['plan_inbound_drift']), array_keys($adminFa['plan_inbound_drift']));
        foreach (['plan_saved', 'form', 'service'] as $old) {
            self::assertArrayNotHasKey($old, $adminEn['plan_inbound_drift'], 'replaced by '.$old.'_apply on every surface');
        }

        $service = $this->planService($this->gold, $this->de);
        $this->sellOn($this->gold, $this->nl);
        $notice = (new Crawler($this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $this->session)))->filter('[data-inbound-notice]')->text();
        self::assertStringEndsWith('Use "Apply to existing services" on the plan to move them.', $notice);
        $page = $this->appGet($this->planPath(''), $this->session);
        self::assertStringContainsString('«اعمال روی سرویس‌های موجود»', (new Crawler($page))->filter('[data-warning-list]')->text());
        self::assertStringContainsString('اعمال روی سرویس‌های موجود', (new Crawler($page))->filter('[data-apply-link]')->text());
        self::assertStringContainsString('انتقال سرویس‌های موجود', (new Crawler($this->appGet($this->planPath('/apply-inbounds'), $this->session)))->text());
    }

    private function planPath(string $suffix): string
    {
        return '/app/admin/plans/'.$this->gold->getId().$suffix;
    }

    private function screen(string $suffix): Crawler
    {
        $html = $this->appGet($this->planPath($suffix), $this->session);
        self::assertSame(200, $this->httpStatus(), $suffix.': '.mb_substr(strip_tags($html), 0, 1500));
        $this->em->clear();

        return new Crawler($html);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $suffix, array $body, ?string $key = null): array
    {
        $answer = $this->appPostJson('/app/api/admin/plans/'.$this->gold->getId().$suffix, $this->session, $body, $key ?? self::newKey());
        $this->em->clear();

        return $answer;
    }

    /** @return array<string, mixed> */
    private function act(string $action, int $runId): array
    {
        $path = '/app/api/admin/plans/'.$this->gold->getId().'/inbound-move/'.$action.'?lang=en';
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
    private function createRun(array $query = []): int
    {
        $this->post('/apply-inbounds', $this->fields($this->screen('/apply-inbounds?'.http_build_query(['step' => 'confirm'] + $query))->filter('form[data-create-form]')));
        self::assertSame(200, $this->httpStatus());
        $runs = $this->runs();
        self::assertCount(1, $runs);

        return (int) $runs[0]['id'];
    }

    /** @return list<string> */
    private function buttons(Crawler $page): array
    {
        return $page->filter('[data-run-buttons] button[data-run-action]')->each(static fn (Crawler $b): string => (string) $b->attr('data-run-action'));
    }

    /** @return array<string, string> */
    private function skipCounts(Crawler $page): array
    {
        $counts = [];
        $page->filter('[data-summary] [data-skip-reason]')->each(function (Crawler $p) use (&$counts): void {
            $counts[(string) $p->attr('data-skip-reason')] = (string) $p->attr('data-skip-count');
        });
        ksort($counts);

        return $counts;
    }

    /** @return array<string, string> */
    private function moveChecksums(): array
    {
        $sums = [];
        foreach (['inbound_move_run', 'service_inbound_move', 'admin_audit_log', 'vpn_service'] as $table) {
            $sums[$table] = (string) $this->em->getConnection()->fetchAssociative('CHECKSUM TABLE `'.$table.'`')['Checksum'];
        }

        return $sums;
    }

    /**
     * The run's creation as stored (as InboundMoveWebTest compares the web and the command).
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
            'audit' => array_diff_key(array_values(array_filter($this->audits(), static fn (array $a): bool => 'plan.inbounds_apply_started' === $a['action']))[0]['details'] ?? [], ['run_id' => true]),
            'requestedBy' => $requestedBy,
        ];
    }

    private function reset(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
    }

    /** The web panel as a super admin (for the parity and the shared limiter). */
    private function webClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('p9-web@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $client->request('GET', '/admin/locale/en');

        return $client;
    }
}
