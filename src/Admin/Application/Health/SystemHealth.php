<?php

declare(strict_types=1);

namespace App\Admin\Application\Health;

use App\Payment\Application\GatewayFailureMemory;
use Psr\Clock\ClockInterface;

/**
 * The health block and version chip of the modern dashboard (docs/design/admin-redesign.md 4.3, 4.4). No query, no
 * remote call, no systemd: the job files, two one-line files root writes for PHP, and two cache reads.
 *
 * A line is "ok", "problem", "unknown" (a file is missing or unreadable) or, for the webhook, "unchecked" (nobody
 * pressed "check now" in the last day; not counted). The chip is green when no line is a problem or unknown.
 *
 * @phpstan-import-type Report from ScheduledJobHealth
 * @phpstan-import-type Info from TelegramWebhookStatus
 * @phpstan-import-type Failure from GatewayFailureMemory
 * @phpstan-type Version array{state: string, commit: string|null, deployedAt: int|null, ref: string|null}
 * @phpstan-type Health array{
 *     attention: int,
 *     jobs: array{state: string, report: Report},
 *     backup: array{state: string, at: int|null, reason: string|null},
 *     gateways: array{state: string, failures: list<Failure>},
 *     webhook: array{state: string, info: Info|null, expected: bool},
 *     version: Version,
 * }
 */
final class SystemHealth
{
    /** As AMOOBOT_BACKUP_MAX_AGE in bin/check-scheduled-jobs.sh (26 h). */
    public const BACKUP_MAX_AGE = 93600;

    public const OK = 'ok';
    public const PROBLEM = 'problem';
    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly SystemStateFiles $files,
        private readonly ScheduledJobHealth $jobs,
        private readonly GatewayFailureMemory $gatewayFailures,
        private readonly TelegramWebhookStatus $webhook,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return Health
     */
    public function summary(): array
    {
        $report = $this->jobs->report();
        $jobs = ['state' => match ($report['state']) {
            ScheduledJobHealth::STATE_OK => self::OK,
            ScheduledJobHealth::STATE_PROBLEM => self::PROBLEM,
            default => self::UNKNOWN,
        }, 'report' => $report];
        // One unreadable job file makes the line "unknown", not "ok".
        if (self::OK === $jobs['state'] && null !== $report['reason']) {
            $jobs['state'] = self::UNKNOWN;
        }

        $failures = array_values($this->gatewayFailures->recent());
        $info = $this->webhook->cached();
        $health = [
            'jobs' => $jobs,
            'backup' => $this->backup(),
            'gateways' => ['state' => [] === $failures ? self::OK : self::PROBLEM, 'failures' => $failures],
            'webhook' => ['state' => $this->webhook->state($info), 'info' => $info, 'expected' => $this->webhook->expectsWebhook()],
            'version' => $this->version(),
        ];
        $attention = 0;
        foreach (['jobs', 'backup', 'gateways', 'webhook'] as $line) {
            if (in_array($health[$line]['state'], [self::PROBLEM, self::UNKNOWN], true)) {
                ++$attention;
            }
        }

        return ['attention' => $attention] + $health;
    }

    /**
     * deployed-version: "<short commit> <UTC time> <ref>". No file: a development install ("dev").
     *
     * @return Version
     */
    public function version(): array
    {
        $line = $this->files->firstLine($this->files->path(SystemStateFiles::DEPLOYED_VERSION));
        if (null === $line) {
            return ['state' => 'dev', 'commit' => null, 'deployedAt' => null, 'ref' => null];
        }
        if (false === $line || 1 !== preg_match('/^([0-9a-f]{7,40}) (\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ) ([A-Za-z0-9._\/-]{1,100})$/', $line, $m)) {
            return ['state' => self::UNKNOWN, 'commit' => null, 'deployedAt' => null, 'ref' => null];
        }

        return ['state' => self::OK, 'commit' => substr($m[1], 0, 12), 'deployedAt' => self::utc($m[2]), 'ref' => $m[3]];
    }

    /**
     * @return array{state: string, at: int|null, reason: string|null}
     */
    private function backup(): array
    {
        $line = $this->files->firstLine($this->files->path(SystemStateFiles::BACKUP_LAST_SUCCESS));
        if (null === $line || false === $line) {
            return ['state' => self::UNKNOWN, 'at' => null, 'reason' => null === $line ? 'none' : 'unreadable'];
        }
        $at = 1 === preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $line) ? self::utc($line) : null;
        if (null === $at) {
            return ['state' => self::UNKNOWN, 'at' => null, 'reason' => 'unreadable'];
        }
        $stale = $this->clock->now()->getTimestamp() - $at > self::BACKUP_MAX_AGE;

        return ['state' => $stale ? self::PROBLEM : self::OK, 'at' => $at, 'reason' => $stale ? 'stale' : null];
    }

    private static function utc(string $time): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $time, new \DateTimeZone('UTC'));

        return false === $date ? null : $date->getTimestamp();
    }
}
