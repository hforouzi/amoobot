<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * The shape of a panel client's UUID (RFC 4122 text form, any version, any case). A panel answer whose client "id" is
 * not one (3x-ui's database row id, an empty value) must never be written back as the client's credential.
 */
final class ClientUuid
{
    public static function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }
}
