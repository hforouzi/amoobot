<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Provisioning\Application\InboundAttachAlerts;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * PR P4, T7 of docs/design/plan-inbound-change.md 8.2 and 5.4: retries with the attach retry settings (plans.attach_*),
 * give-up with one alert per service, one alert per run (finished with failures, or paused), and the auto-pause after
 * 5 failed panel reads in a row, resumed only with --resume.
 */
final class InboundMoveRetryTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
    }

    public function testAFailedMoveBacksOffGivesUpAndIsAlertedOnce(): void
    {
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '2', 'number');
        $service = $this->movableService(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [3];
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(1, $code, $output);
        $runId = (int) $this->runs()[0]['id'];
        $move = $this->moves()[0];
        self::assertSame(['failed', 1], [$move['state'], (int) $move['attempts']]);
        $delay = strtotime((string) $move['next_attempt_at']) - time();
        self::assertGreaterThan(4 * 60, $delay, 'plans.attach_retry_delay_minutes (5)');
        self::assertLessThanOrEqual(5 * 60 + 5, $delay);
        self::assertSame('running', $this->runs()[0]['status']);
        self::assertSame([], $this->alerts($before), 'no alert while an attempt is left');
        self::assertConsoleContains(sprintf('--resume=%d', $runId), $output);

        // Not due yet: --resume reads nothing.
        $this->fake->requests = [];
        [$code] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(1, $code, 'exit 1: the run still holds a failed service');
        self::assertSame([], $this->fake->requests);
        self::assertSame(1, (int) $this->moves()[0]['attempts']);

        // Due: the second (last) attempt fails too: given up, one alert for the service, one for the run.
        $this->due();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(1, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['failed', 2, null, 'exhausted'], [$move['state'], (int) $move['attempts'], $move['next_attempt_at'], $move['alert_state']]);
        self::assertSame('done', $this->runs()[0]['status'], 'nothing left that can change');
        $gaveUp = $this->alerts($before, InboundAttachAlerts::MOVE_GAVE_UP_TITLE);
        self::assertCount(1, $gaveUp);
        self::assertStringContainsString(sprintf('Service ID: %d', $service->getId()), $gaveUp[0]);
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::MOVE_FINISHED_FAILED_TITLE));
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'));
        self::assertSame(['run_id' => $runId, 'planned' => 1, 'done' => 0, 'failed' => 1, 'skipped' => 0, 'remaining' => 0], $this->audits()[1]['details']);
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id'], 'keeps its old inbound');

        // The run is closed: --resume refuses (exit 2), nothing is sent again.
        $this->due();
        $this->fake->requests = [];
        [$code] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(2, $code);
        self::assertSame([], $this->fake->requests);
        self::assertCount(2, $this->alerts($before));
        foreach ($this->alerts($before) as $text) {
            foreach ($this->secretsOf($service) as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
    }

    public function testFiveFailedPanelReadsInARowPauseTheRunWithOneAlertUntilResume(): void
    {
        $services = array_map(fn (int $n) => $this->movableService($n), range(1, 6));
        $this->fake->script('GET', null, Fake3xuiPanel::networkError('Could not resolve host'), times: 100);
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(1, $code, $output);
        $run = $this->runs()[0];
        self::assertSame(['paused', 'panel not answering: 5 failed panel reads in a row'], [$run['status'], $run['pause_reason']]);
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'failed', 'planned'], array_column($this->moves(), 'state'), 'the sixth is not touched');
        self::assertSame([], $this->panelWrites());
        $paused = $this->alerts($before, InboundAttachAlerts::MOVE_PAUSED_TITLE);
        self::assertCount(1, $paused);
        self::assertStringContainsString(sprintf('--resume=%d', $run['id']), $paused[0]);
        self::assertCount(1, $this->alerts($before), 'one alert for the run, none per service');

        // A new --apply is refused while it is open; a dry run still works.
        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(2, $code);
        self::assertConsoleContains(sprintf('open run (#%d)', $run['id']), $output);

        // The panel answers again: --resume continues it (the failed ones once due).
        $this->fake->clearScripts();
        $this->due();
        [$code, $output] = $this->command(['--resume' => (string) $run['id']]);
        self::assertSame(0, $code, $output);
        self::assertSame(array_fill(0, 6, 'switched'), array_column($this->moves(), 'state'));
        self::assertSame('done', $this->runs()[0]['status']);
        foreach ($services as $service) {
            self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);
        }
        self::assertCount(1, $this->alerts($before), 'no finished alert: nothing failed in the end');
    }

    private function due(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = '2000-01-01 00:00:00' WHERE state = 'failed' AND next_attempt_at IS NOT NULL");
    }

    /**
     * Admin alerts sent since $from, of one kind when $title is given: each alert once, however many admin chats it
     * was delivered to (an earlier test may leave a linked admin behind).
     *
     * @return list<string>
     */
    private function alerts(int $from, ?string $title = null): array
    {
        return array_values(array_unique(array_filter(array_slice($this->http->telegramTexts(), $from), static fn (string $text): bool => null === $title ? 1 === preg_match('/Run ID: \d+/', $text) : str_starts_with($text, $title))));
    }
}
