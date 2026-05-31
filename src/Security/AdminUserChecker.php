<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdminUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof AdminUser) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Admin account is inactive.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
