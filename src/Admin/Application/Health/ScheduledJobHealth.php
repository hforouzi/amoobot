<?php

declare(strict_types=1);

namespace App\Admin\Application\Health;

use Psr\Clock\ClockInterface;

/**
 * The scheduled jobs' health, read from the files the jobs already write (docs/design/admin-redesign.md 4.4): the rules
 * of bin/check-scheduled-jobs.sh, in PHP, without systemd.
 *
 *   OK       last success within the job's max age (3 x interval + timeout, from the manifest)
 *   STALE    last success too long ago (failures, skips, or a stopped timer once the job is overdue)
 *   NEVER    no successful run recorded
 *   NEW      no successful run yet, but installed less than its max age ago: the first run is still due
 *   FAILED   the latest run's rc (in <job>.last-run) was not 0, even when an older success is still recent
 *   PAUSED   STALE or NEVER while a deploy (deploy-in-progress younger than 30 min) pauses the jobs on purpose
 *   UNKNOWN  a file exists but cannot be read: never shown as healthy, never an error page
 *
 * The script's INACTIVE (timer stopped) and its "unit failed" need systemctl, which PHP never calls: a stopped timer
 * shows as STALE once the job is overdue, a failed unit as FAILED through rc=.
 *
 * @phpstan-type Job array{name: string, status: string, schedule: string, maxAge: int, lastSuccess: int|null, lastRc: int|null}
 * @phpstan-type Report array{state: string, reason: string|null, jobs: list<Job>, problems: int, deploy: string|null}
 */
final class ScheduledJobHealth
{
    public const OK = 'ok';
    public const STALE = 'stale';
    public const NEVER = 'never';
    public const NEW = 'new';
    public const FAILED = 'failed';
    public const PAUSED = 'paused';
    public const UNKNOWN = 'unknown';

    /** Statuses that are not a problem (the script's exit 0). */
    public const HEALTHY = [self::OK, self::NEW, self::PAUSED];

    /** As AMOOBOT_DEPLOY_MAX_AGE in bin/check-scheduled-jobs.sh. */
    public const DEPLOY_MAX_AGE = 1800;

    /** Overall states. */
    public const STATE_OK = 'ok';
    public const STATE_PROBLEM = 'problem';
    public const STATE_UNKNOWN = 'unknown';

    private const MAX_MANIFEST_BYTES = 65536;

    public function __construct(
        private readonly SystemStateFiles $files,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return Report
     */
    public function report(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $manifest = $this->files->path(SystemStateFiles::MANIFEST);
        clearstatcache(true, $manifest);
        if (!file_exists($manifest)) {
            return $this->unknown('no_manifest');
        }
        $content = is_file($manifest) && is_readable($manifest) ? @file_get_contents($manifest, false, null, 0, self::MAX_MANIFEST_BYTES) : false;
        if (false === $content) {
            return $this->unknown('unreadable');
        }

        $deploy = null;
        $marker = $this->files->mtime($this->files->path(SystemStateFiles::DEPLOY_MARKER));
        if (null !== $marker) {
            $deploy = $now - $marker > self::DEPLOY_MAX_AGE ? 'stuck' : 'running';
        }

        $jobsDir = $this->files->path(SystemStateFiles::JOBS_DIR);
        clearstatcache(true, $jobsDir);
        // A directory PHP cannot search hides every file in it: that is "cannot tell", not "never ran".
        // (POSIX: read and search bits; Windows has no search bit, is_executable() is false for every directory there.)
        $blind = is_dir($jobsDir) && (!is_readable($jobsDir) || ('/' === \DIRECTORY_SEPARATOR && !is_executable($jobsDir)));

        $jobs = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if ('' === ($fields[0] ?? '') || str_starts_with($fields[0], '#')) {
                continue;
            }
            if (1 !== preg_match('/^[a-z0-9-]+$/', $fields[0]) || 1 !== preg_match('/^\d{1,9}$/', $fields[1] ?? '')) {
                return $this->unknown('malformed', $deploy);
            }
            $jobs[] = $blind
                ? ['name' => $fields[0], 'status' => self::UNKNOWN, 'schedule' => $fields[2] ?? '?', 'maxAge' => (int) $fields[1], 'lastSuccess' => null, 'lastRc' => null]
                : $this->job($fields[0], (int) $fields[1], $fields[2] ?? '?', $now, $deploy);
        }
        if ([] === $jobs) {
            return $this->unknown('empty', $deploy);
        }

        $problems = count(array_filter($jobs, static fn (array $job): bool => !in_array($job['status'], self::HEALTHY, true)));
        if ('stuck' === $deploy) {
            ++$problems;
        }

        return [
            'state' => $problems > 0 ? self::STATE_PROBLEM : self::STATE_OK,
            'reason' => $blind ? 'unreadable' : null,
            'jobs' => $jobs,
            'problems' => $problems,
            'deploy' => $deploy,
        ];
    }

    /**
     * @return Job
     */
    private function job(string $name, int $maxAge, string $schedule, int $now, ?string $deploy): array
    {
        $lastSuccess = $this->files->mtime($this->files->jobFile($name, 'last-success'));
        if (null !== $lastSuccess) {
            $status = $now - $lastSuccess > $maxAge ? self::STALE : self::OK;
        } else {
            $status = self::NEVER;
            $installed = $this->files->mtime($this->files->jobFile($name, 'installed'));
            if (null !== $installed && $now - $installed <= $maxAge) {
                $status = self::NEW;
            }
        }

        $lastRc = null;
        $lastRun = $this->files->firstLine($this->files->jobFile($name, 'last-run'));
        if (false === $lastRun) {
            $status = self::UNKNOWN;
        } elseif (null !== $lastRun && 1 === preg_match('/(?:^|\s)rc=(\d+)/', $lastRun, $match)) {
            $lastRc = (int) $match[1];
            if (0 !== $lastRc) {
                $status = self::FAILED;
            }
        }

        if ('running' === $deploy && in_array($status, [self::STALE, self::NEVER], true)) {
            $status = self::PAUSED;
        }

        return ['name' => $name, 'status' => $status, 'schedule' => $schedule, 'maxAge' => $maxAge, 'lastSuccess' => $lastSuccess, 'lastRc' => $lastRc];
    }

    /**
     * @return Report
     */
    private function unknown(string $reason, ?string $deploy = null): array
    {
        return ['state' => self::STATE_UNKNOWN, 'reason' => $reason, 'jobs' => [], 'problems' => 0, 'deploy' => $deploy];
    }
}
