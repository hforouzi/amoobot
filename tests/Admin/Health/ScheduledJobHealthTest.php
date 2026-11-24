<?php

declare(strict_types=1);

namespace App\Tests\Admin\Health;

use App\Admin\Application\Health\ScheduledJobHealth;
use App\Admin\Application\Health\SystemStateFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * AD3 (docs/design/admin-redesign.md 4.4): ScheduledJobHealth applies bin/check-scheduled-jobs.sh's rules to the files
 * the jobs write, on fixture directories: every status, and "unknown" (never "ok", never an exception) for anything
 * PHP cannot read.
 */
final class ScheduledJobHealthTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ad3-jobs-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/jobs', 0755, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir.'/jobs', 0755);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            @chmod($file->getPathname(), 0755);
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
    }

    private function reader(): ScheduledJobHealth
    {
        return new ScheduledJobHealth(new SystemStateFiles($this->dir), new MockClock((new \DateTimeImmutable())->setTimestamp(self::NOW)));
    }

    private function manifest(string ...$lines): void
    {
        file_put_contents($this->dir.'/scheduled-jobs.conf', "# job max_age schedule\n".implode("\n", $lines)."\n");
    }

    /** A job file whose mtime is $ago seconds before NOW. */
    private function stamp(string $file, int $ago, string $content = ''): void
    {
        $path = $this->dir.'/jobs/'.$file;
        file_put_contents($path, $content);
        touch($path, self::NOW - $ago);
    }

    /**
     * @return array<string, string> job => status
     */
    private function statuses(): array
    {
        return array_column($this->reader()->report()['jobs'], 'status', 'name');
    }

    public function testEveryStatusFromTheFiles(): void
    {
        $this->manifest('ok 3600 *:0/5', 'stale 3600 hourly', 'never 3600 daily', 'new 3600 daily', 'old-install 3600 daily', 'failed 3600 *:0/5', 'recovered 3600 *:0/5');
        $this->stamp('ok.last-success', 60);
        $this->stamp('ok.last-run', 60, "2026-11-24T10:00:00Z rc=0 duration=3s\n");
        $this->stamp('stale.last-success', 3601);
        $this->stamp('new.installed', 600);
        $this->stamp('old-install.installed', 7200);
        $this->stamp('failed.last-success', 60);
        $this->stamp('failed.last-run', 30, "2026-11-24T10:00:00Z rc=1 duration=3s\n");
        $this->stamp('recovered.last-success', 30);
        $this->stamp('recovered.last-run', 30, "2026-11-24T10:00:00Z rc=0 duration=3s\n");

        self::assertSame([
            'ok' => ScheduledJobHealth::OK,
            'stale' => ScheduledJobHealth::STALE,
            'never' => ScheduledJobHealth::NEVER,
            'new' => ScheduledJobHealth::NEW,
            'old-install' => ScheduledJobHealth::NEVER,
            'failed' => ScheduledJobHealth::FAILED,
            'recovered' => ScheduledJobHealth::OK,
        ], $this->statuses());

        $report = $this->reader()->report();
        self::assertSame(ScheduledJobHealth::STATE_PROBLEM, $report['state']);
        self::assertSame(4, $report['problems'], 'stale, never, old-install, failed; NEW is not a problem');
        $failed = array_column($report['jobs'], null, 'name')['failed'];
        self::assertSame(1, $failed['lastRc']);
        self::assertSame(self::NOW - 60, $failed['lastSuccess']);
        self::assertSame('*:0/5', $failed['schedule']);
    }

    public function testAllHealthyIsOk(): void
    {
        $this->manifest('sync 5700 *:25/30', 'orders 900 *:0/5');
        $this->stamp('sync.last-success', 5700);
        $this->stamp('orders.installed', 10);

        $report = $this->reader()->report();
        self::assertSame(ScheduledJobHealth::STATE_OK, $report['state']);
        self::assertSame(0, $report['problems']);
        self::assertSame(['sync' => 'ok', 'orders' => 'new'], $this->statuses());
    }

    public function testADeployPausesOverdueJobsAndAStuckOneIsAProblem(): void
    {
        $this->manifest('stale 60 x', 'never 60 x', 'failed 60 x');
        $this->stamp('stale.last-success', 600);
        $this->stamp('failed.last-run', 5, 'rc=2');
        file_put_contents($this->dir.'/deploy-in-progress', '');
        touch($this->dir.'/deploy-in-progress', self::NOW - 120);

        $report = $this->reader()->report();
        self::assertSame(['stale' => 'paused', 'never' => 'paused', 'failed' => 'failed'], array_column($report['jobs'], 'status', 'name'));
        self::assertSame('running', $report['deploy']);
        self::assertSame(1, $report['problems'], 'a failed run still counts during a deploy');

        touch($this->dir.'/deploy-in-progress', self::NOW - ScheduledJobHealth::DEPLOY_MAX_AGE - 1);
        $report = $this->reader()->report();
        self::assertSame('stuck', $report['deploy']);
        self::assertSame(['stale' => 'stale', 'never' => 'never', 'failed' => 'failed'], array_column($report['jobs'], 'status', 'name'));
        self::assertSame(4, $report['problems'], 'three jobs and the stuck deploy');
    }

    public function testNoManifestIsUnknownNotHealthy(): void
    {
        $report = $this->reader()->report();
        self::assertSame(ScheduledJobHealth::STATE_UNKNOWN, $report['state']);
        self::assertSame('no_manifest', $report['reason']);
        self::assertSame([], $report['jobs']);
    }

    public function testAnUnreadableManifestIsUnknown(): void
    {
        mkdir($this->dir.'/scheduled-jobs.conf');
        $report = $this->reader()->report();
        self::assertSame(ScheduledJobHealth::STATE_UNKNOWN, $report['state']);
        self::assertSame('unreadable', $report['reason']);
    }

    public function testAMalformedOrEmptyManifestIsUnknown(): void
    {
        $this->manifest('sync not-a-number x');
        self::assertSame(['unknown', 'malformed'], [$this->reader()->report()['state'], $this->reader()->report()['reason']]);
        $this->manifest('../etc 60 x');
        self::assertSame('malformed', $this->reader()->report()['reason']);
        $this->manifest('# nothing');
        self::assertSame(['unknown', 'empty'], [$this->reader()->report()['state'], $this->reader()->report()['reason']]);
    }

    public function testAnUnreadableLastRunMakesThatJobUnknown(): void
    {
        $this->manifest('ok 3600 x', 'blind 3600 x');
        $this->stamp('ok.last-success', 10);
        $this->stamp('blind.last-success', 10);
        // Something PHP cannot read as a file (works on every OS and as root).
        mkdir($this->dir.'/jobs/blind.last-run');

        $report = $this->reader()->report();
        self::assertSame(['ok' => 'ok', 'blind' => 'unknown'], array_column($report['jobs'], 'status', 'name'));
        self::assertSame(ScheduledJobHealth::STATE_PROBLEM, $report['state']);
    }

    public function testAFileWithoutReadPermissionIsUnknown(): void
    {
        $this->skipUnlessPermissionsApply();
        $this->manifest('locked 3600 x');
        $this->stamp('locked.last-success', 10);
        $this->stamp('locked.last-run', 10, 'rc=0');
        chmod($this->dir.'/jobs/locked.last-run', 0000);

        self::assertSame(['locked' => 'unknown'], $this->statuses());
    }

    public function testAJobsDirectoryPhpCannotSearchMakesEveryJobUnknownNotNever(): void
    {
        $this->skipUnlessPermissionsApply();
        $this->manifest('a 3600 x', 'b 3600 x');
        $this->stamp('a.last-success', 10);
        chmod($this->dir.'/jobs', 0000);

        $report = $this->reader()->report();
        self::assertSame(['a' => 'unknown', 'b' => 'unknown'], array_column($report['jobs'], 'status', 'name'));
        self::assertSame('unreadable', $report['reason']);
        self::assertSame(2, $report['problems']);
    }

    public function testAManifestWithoutReadPermissionIsUnknown(): void
    {
        $this->skipUnlessPermissionsApply();
        $this->manifest('a 3600 x');
        chmod($this->dir.'/scheduled-jobs.conf', 0000);

        self::assertSame(['unknown', 'unreadable'], [$this->reader()->report()['state'], $this->reader()->report()['reason']]);
    }

    public function testTheStateDirDefaultsToTheServerPathAndTakesTheOverride(): void
    {
        self::assertSame('/var/lib/amoobot/deployed-version', (new SystemStateFiles())->path(SystemStateFiles::DEPLOYED_VERSION));
        self::assertSame('/var/lib/amoobot/jobs/sync.last-run', (new SystemStateFiles(''))->jobFile('sync', 'last-run'));
        self::assertSame('/tmp/x/backup-last-success', (new SystemStateFiles('/tmp/x/'))->path(SystemStateFiles::BACKUP_LAST_SUCCESS));
    }

    private function skipUnlessPermissionsApply(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR || (function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('File modes do not restrict this user (Windows or root).');
        }
    }
}
