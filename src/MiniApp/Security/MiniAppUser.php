<?php

declare(strict_types=1);

namespace App\MiniApp\Security;

use App\Entity\MiniAppSession;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Security\AdminActor;
use App\Security\AdminActorUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who is using the Mini App on this request: the customer (TelegramAccount -> User, as in the bot), the session, and
 * the admin identity resolved for this very request (AdminAccessPolicy::botActor), null for a customer.
 */
final class MiniAppUser implements UserInterface, AdminActorUser
{
    public const ROLE = 'ROLE_MINIAPP_USER';

    public function __construct(
        public readonly TelegramAccount $account,
        public readonly MiniAppSession $session,
        public readonly ?AdminActor $admin,
    ) {
    }

    public function adminActor(): ?AdminActor
    {
        return $this->admin;
    }

    public function customer(): User
    {
        return $this->account->getUser();
    }

    public function telegramUserId(): string
    {
        return $this->account->getTelegramId();
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return $this->account->getTelegramId();
    }

    public function eraseCredentials(): void
    {
    }
}
