<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one authorization check for admins, used by the web voter (AdminPermissionVoter) and the bot.
 */
final class AdminAccessPolicy
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $adminChatId = null,
    ) {
    }

    /**
     * The admin behind a Telegram user: the AdminUser linked to that id (only while active), else the
     * TELEGRAM_ADMIN_CHAT_ID user as a super admin (fallback until admins are linked), else nobody.
     * A linked but deactivated admin is refused even when their id is also TELEGRAM_ADMIN_CHAT_ID.
     */
    public function botActor(string $telegramUserId): ?AdminActor
    {
        $telegramUserId = trim($telegramUserId);
        if ('' === $telegramUserId) {
            return null;
        }

        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['telegramUserId' => $telegramUserId]);
        if ($admin instanceof AdminUser) {
            return $admin->isActive() ? AdminActor::fromAdminUser($admin, AdminActor::SOURCE_BOT) : null;
        }

        return $telegramUserId === trim((string) $this->adminChatId) ? AdminActor::environmentBotAdmin($telegramUserId) : null;
    }

    /**
     * Telegram chats to alert about something that needs this permission: TELEGRAM_ADMIN_CHAT_ID (a super admin, or the
     * admin linked to that id if any) and every active linked admin who has it, each once.
     *
     * @return list<string>
     */
    public function telegramRecipients(AdminPermission $permission): array
    {
        $chats = [];
        $env = trim((string) $this->adminChatId);
        if ('' !== $env && $this->can($this->botActor($env), $permission)) {
            $chats[] = $env;
        }

        $linked = $this->entityManager->getRepository(AdminUser::class)->createQueryBuilder('a')
            ->where('a.telegramUserId IS NOT NULL')
            ->andWhere('a.isActive = true')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($linked as $admin) {
            if ($this->can(AdminActor::fromAdminUser($admin, AdminActor::SOURCE_BOT), $permission)) {
                $chats[] = (string) $admin->getTelegramUserId();
            }
        }

        return array_values(array_unique($chats));
    }

    public function can(?AdminActor $actor, AdminPermission $permission): bool
    {
        if (null === $actor || !$actor->active) {
            return false;
        }

        foreach ($actor->roles as $role) {
            if (in_array($permission, AdminRolePermissions::forRole($role), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything this actor may do (empty for nobody / a deactivated admin), e.g. to build a keyboard.
     *
     * @return list<AdminPermission>
     */
    public function permissions(?AdminActor $actor): array
    {
        return array_values(array_filter(AdminPermission::cases(), fn (AdminPermission $permission): bool => $this->can($actor, $permission)));
    }

    /**
     * @param list<AdminPermission> $permissions
     */
    public function canAny(?AdminActor $actor, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($actor, $permission)) {
                return true;
            }
        }

        return false;
    }
}
