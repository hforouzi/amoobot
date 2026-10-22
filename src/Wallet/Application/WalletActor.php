<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Security\AdminActor;

/**
 * Who wrote a ledger row: the system (settlement, checkout) or an admin, and from where (web, bot, Mini App, CLI).
 */
final class WalletActor
{
    public const SOURCE_SYSTEM = 'system';

    private function __construct(
        public readonly string $source,
        public readonly ?int $adminId,
        public readonly ?string $label,
    ) {
    }

    public static function system(): self
    {
        return new self(self::SOURCE_SYSTEM, null, null);
    }

    public static function admin(AdminActor $admin): self
    {
        return new self($admin->source, $admin->adminUserId, mb_substr($admin->label, 0, 190));
    }

    public function isAdmin(): bool
    {
        return self::SOURCE_SYSTEM !== $this->source;
    }
}
