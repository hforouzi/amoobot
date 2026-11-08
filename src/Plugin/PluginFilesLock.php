<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * One exclusive, non-blocking lock for everything that moves plugin directories in var/plugins (install and upgrade),
 * so two of them never overlap. flock() on var/plugin_uploads/plugin-files.lock: released by release() or when the
 * process ends, so a crash never leaves it held. Never through a symbolic link.
 */
final class PluginFilesLock
{
    public const BUSY = 'Another plugin install or upgrade is running. Try again when it has finished.';

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function path(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'plugin_uploads'.\DIRECTORY_SEPARATOR.'plugin-files.lock';
    }

    /**
     * @return resource
     *
     * @throws PluginInstallException when another process (or another handle) holds it
     */
    public function acquire()
    {
        $path = $this->path();
        $dir = dirname($path);
        foreach ([dirname($dir), $dir, $path] as $candidate) {
            if (is_link($candidate)) {
                throw new PluginInstallException(sprintf('%s is a symbolic link; refusing to follow it.', $this->relative($candidate)));
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PluginInstallException('Could not create plugin storage directory.');
        }

        $handle = @fopen($path, 'c');
        if (false === $handle) {
            throw new PluginInstallException('Could not open the plugin lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new PluginInstallException(self::BUSY);
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function relative(string $path): string
    {
        $prefix = rtrim($this->projectDir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
