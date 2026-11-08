<?php

declare(strict_types=1);

namespace App\Plugin;

final class PluginUpgradeResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public readonly bool $success,
        public readonly bool $dryRun,
        public readonly ?PluginUpgradePlan $plan,
        public readonly array $errors,
        public readonly ?string $backupDir,
    ) {
    }

    public static function upgraded(PluginUpgradePlan $plan, string $backupDir): self
    {
        return new self(true, false, $plan, [], $backupDir);
    }

    public static function planned(PluginUpgradePlan $plan): self
    {
        return new self(true, true, $plan, [], null);
    }

    /**
     * @param list<string> $errors
     */
    public static function refused(array $errors, ?PluginUpgradePlan $plan = null, bool $dryRun = false): self
    {
        return new self(false, $dryRun, $plan, $errors, null);
    }
}
