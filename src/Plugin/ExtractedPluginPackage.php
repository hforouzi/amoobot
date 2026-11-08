<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * A plugin ZIP extracted by PluginManager::extractPackage(): the workspace to remove afterwards, the plugin root inside
 * it (the folder holding plugin.json) and the validated manifest.
 */
final class ExtractedPluginPackage
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly string $workspace,
        public readonly string $root,
        public readonly array $manifest,
    ) {
    }
}
