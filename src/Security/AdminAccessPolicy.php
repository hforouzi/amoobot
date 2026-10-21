<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The one authorization check for admins, used by the web voter (AdminPermissionVoter) and the bot.
 */
final class AdminAccessPolicy
{
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
