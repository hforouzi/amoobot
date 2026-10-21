<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Entity\TelegramAccount;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminPermission;

final class ServiceActionContext
{
    /**
     * @param AdminActor|null $admin the admin behind the Telegram user (AdminAccessPolicy::botActor), null for a customer
     */
    public function __construct(
        public readonly TelegramAccount $account,
        public readonly string $actorId,
        public readonly string $chatId,
        public readonly string $callbackId,
        public readonly string $data,
        public readonly ?AdminActor $admin,
        private readonly AdminAccessPolicy $policy,
    ) {
    }

    public function can(AdminPermission $permission): bool
    {
        return $this->policy->can($this->admin, $permission);
    }
}
