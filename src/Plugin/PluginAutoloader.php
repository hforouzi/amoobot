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
        return 'Amoobot\\Plugin\\'.$this->studlyCode($plugin->getCode()).'\\';
    }

    public function sourcePathFor(Plugin $plugin): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.$plugin->getPath().\DIRECTORY_SEPARATOR.'src';
    }

    private function register(Plugin $plugin): void
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
