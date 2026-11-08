<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * What an upgrade changes: names only (versions, classes, permissions, config field names, gateway ids), never a config
 * value, so it is safe to print and to log.
 */
final class PluginUpgradePlan
{
    /**
     * @param list<string>              $addedPermissions
     * @param list<string>              $removedPermissions
     * @param list<string>              $addedFields
     * @param list<string>              $removedFields
     * @param list<string>              $newlyRequiredFields required now, without a default, and not required before
     * @param list<int>                 $gatewayIds          every payment gateway that uses this plugin code
     * @param array<int, list<string>>  $obsoleteKeys        gateway id => stored keys the new schema no longer has (kept)
     * @param array<int, list<string>>  $missingRequired     gateway id => newly required fields it lacks (blocks the upgrade)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $status,
        public readonly string $oldVersion,
        public readonly string $newVersion,
        public readonly string $oldMainClass,
        public readonly string $newMainClass,
        public readonly array $addedPermissions,
        public readonly array $removedPermissions,
        public readonly array $addedFields,
        public readonly array $removedFields,
        public readonly array $newlyRequiredFields,
        public readonly array $gatewayIds,
        public readonly array $obsoleteKeys,
        public readonly array $missingRequired,
    ) {
    }
}
