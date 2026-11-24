<?php

declare(strict_types=1);

namespace App\Admin\Application\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every file the admin reads about the server, in one place (docs/design/admin-redesign.md 4.4, decision 13). PHP-FPM
 * gets no new right: it reads the job state the jobs write as www-data, and two one-line 0644 files root writes for it
 * on purpose. It never reads the deploy record (deployed-commit), the backups, logs or locks.
 *
 *   <state>/scheduled-jobs.conf   root:root 0644, the job manifest (deploy.sh)
 *   <state>/jobs/<job>.*          www-data, written by bin/scheduled-job.sh and deploy.sh (.installed)
 *   <state>/deploy-in-progress    root, its mtime only (a deploy pauses the jobs)
 *   <state>/deployed-version      root:root 0644, "<short commit> <UTC time> <ref>" (deploy.sh)
 *   <state>/backup-last-success   root:root 0644, "<UTC time>" of the last nightly dump (bin/backup-database.sh)
 *
 * <state> is /var/lib/amoobot; AMOOBOT_STATE_DIR overrides it for tests, as AMOOBOT_JOBS_STATE_DIR does for the scripts.
 */
final class SystemStateFiles
{
    public const DEFAULT_STATE_DIR = '/var/lib/amoobot';
    public const MANIFEST = 'scheduled-jobs.conf';
    public const JOBS_DIR = 'jobs';
    public const DEPLOY_MARKER = 'deploy-in-progress';
    public const DEPLOYED_VERSION = 'deployed-version';
    public const BACKUP_LAST_SUCCESS = 'backup-last-success';

    /** The one-line files are tiny; anything longer is not ours. */
    private const MAX_LINE = 256;

    private readonly string $stateDir;

    public function __construct(
        #[Autowire('%env(default::AMOOBOT_STATE_DIR)%')]
        ?string $stateDir = null,
    ) {
        $stateDir = trim((string) $stateDir);
        $this->stateDir = rtrim('' === $stateDir ? self::DEFAULT_STATE_DIR : $stateDir, '/\\');
    }

    public function path(string $name): string
    {
        return $this->stateDir.'/'.$name;
    }

    public function jobFile(string $job, string $suffix): string
    {
        return $this->stateDir.'/'.self::JOBS_DIR.'/'.$job.'.'.$suffix;
    }

    /** The modification time, or null when there is no such file (or it cannot be seen). */
    public function mtime(string $path): ?int
    {
        clearstatcache(true, $path);
        $time = @filemtime($path);

        return false === $time ? null : $time;
    }

    /**
     * The first line of a small file: null when the file does not exist, false when it exists but cannot be read.
     */
    public function firstLine(string $path): string|false|null
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $content = @file_get_contents($path, false, null, 0, self::MAX_LINE);
        if (false === $content) {
            return false;
        }

        return trim(explode("\n", $content, 2)[0]);
    }
}
