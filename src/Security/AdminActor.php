<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;

/**
 * Who is acting as an admin: a web AdminUser, the same AdminUser in the bot or the Mini App (linked by
 * telegramUserId), or the bot admin from TELEGRAM_ADMIN_CHAT_ID (a super admin without an AdminUser row).
 */
final class AdminActor
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_BOT = 'bot';
    public const SOURCE_CLI = 'cli';
    public const SOURCE_MINIAPP = 'miniapp';

    /**
     * @param list<string> $roles the assigned roles (not AdminUser::getRoles(), which always adds ROLE_ADMIN)
     */
    private function __construct(
        public readonly ?int $adminUserId,
        public readonly string $label,
        public readonly array $roles,
        public readonly bool $active,
        public readonly string $source,
        public readonly ?string $telegramUserId,
    ) {
    }

    public static function fromAdminUser(AdminUser $admin, string $source = self::SOURCE_WEB): self
    {
        return new self($admin->getId(), $admin->getEmail(), $admin->getAssignedRoles(), $admin->isActive(), $source, $admin->getTelegramUserId());
    }

    /** The same admin acting from another place (the Mini App resolves admins like the bot does). */
    public function withSource(string $source): self
    {
        return new self($this->adminUserId, $this->label, $this->roles, $this->active, $source, $this->telegramUserId);
    }

    public static function environmentBotAdmin(string $telegramUserId): self
    {
        return new self(null, 'TELEGRAM_ADMIN_CHAT_ID', [AdminUser::ROLE_SUPER_ADMIN], true, self::SOURCE_BOT, $telegramUserId);
    }
}
