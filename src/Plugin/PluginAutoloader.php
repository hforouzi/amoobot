<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;

final class PluginAutoloader
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Prepared for Phase 1.10.2. Do not call from the kernel in this phase.
     */
    public function registerEnabledPlugins(): void
    {
        foreach ($this->registry->enabled() as $plugin) {
            $this->register($plugin);
        }
    }

    public function namespaceFor(Plugin $plugin): string
    {
        $mainClass = trim((string) $plugin->getMainClass());
        $lastSeparator = strrpos($mainClass, '\\');
        if (false !== $lastSeparator) {
            return substr($mainClass, 0, $lastSeparator + 1);
        }

        return 'Amoobot\\Plugin\\'.$this->studlyCode($plugin->getCode()).'\\';
    }

    public function sourcePathFor(Plugin $plugin): string
    {
        $path = $plugin->getPath();
        if (str_starts_with($path, \DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path.\DIRECTORY_SEPARATOR.'src';
        }

        return $this->projectDir.\DIRECTORY_SEPARATOR.$path.\DIRECTORY_SEPARATOR.'src';
    }

    public function expectedFilePathFor(Plugin $plugin): string
    {
        $mainClass = trim((string) $plugin->getMainClass());
        $namespace = $this->namespaceFor($plugin);
        $sourcePath = $this->sourcePathFor($plugin);

        if ('' === $mainClass || !str_starts_with($mainClass, $namespace)) {
            return $sourcePath;
        }

        $relative = substr($mainClass, strlen($namespace));

        return $sourcePath.\DIRECTORY_SEPARATOR.str_replace('\\', \DIRECTORY_SEPARATOR, $relative).'.php';
    }

    public function register(Plugin $plugin): void
    {
        $namespace = $this->namespaceFor($plugin);
        $sourcePath = $this->sourcePathFor($plugin);

        spl_autoload_register(static function (string $class) use ($namespace, $sourcePath): void {
            if (!str_starts_with($class, $namespace)) {
                return;
            }

            $relative = substr($class, strlen($namespace));
            $path = $sourcePath.\DIRECTORY_SEPARATOR.str_replace('\\', \DIRECTORY_SEPARATOR, $relative).'.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    private function studlyCode(string $code): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $code)));
    }
}
