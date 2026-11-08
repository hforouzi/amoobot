<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * The panel clients the bot already has a service for (PanelClientImporter): by client UUID (any panel), by email on
 * the same panel (3x-ui emails are unique per panel only), or by subscription id. Compared case-insensitively except
 * the subId, which the panel treats as it is.
 */
final class PanelClientIdentities
{
    /** @var array<string, true> */
    private array $uuids = [];
    /** @var array<int, array<string, true>> */
    private array $emails = [];
    /** @var array<string, true> */
    private array $subIds = [];

    public function add(?string $uuid, ?string $email, ?string $subId, ?int $panelId): void
    {
        if (null !== $uuid && '' !== trim($uuid)) {
            $this->uuids[strtolower(trim($uuid))] = true;
        }
        if (null !== $email && '' !== trim($email) && null !== $panelId) {
            $this->emails[$panelId][strtolower(trim($email))] = true;
        }
        if (null !== $subId && '' !== trim($subId)) {
            $this->subIds[trim($subId)] = true;
        }
    }

    public function has(?string $uuid, string $email, ?string $subId, int $panelId): bool
    {
        return (null !== $uuid && isset($this->uuids[strtolower(trim($uuid))]))
            || isset($this->emails[$panelId][strtolower(trim($email))])
            || (null !== $subId && isset($this->subIds[trim($subId)]));
    }
}
