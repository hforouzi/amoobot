<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * How the bot talks to a 3x-ui (Sanaei) panel.
 *
 * AUTO (default): the last capability probe decides (modern when the token can read /panel/api/clients, legacy when
 * only the cookie login works); a panel that was never probed stays legacy, as every panel was before modes existed.
 * LEGACY / MODERN: forced by the admin.
 */
enum SanaeiIntegrationMode: string
{
    case AUTO = 'auto';
    case LEGACY = 'legacy';
    case MODERN = 'modern';

    public static function fromStored(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::AUTO;
    }
}
