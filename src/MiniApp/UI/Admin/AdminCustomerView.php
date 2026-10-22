<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\User;

/**
 * Who a customer is, as the admin pages show it (cards, detail headers).
 */
final class AdminCustomerView
{
    /**
     * @return array{id: int, name: string, username: ?string, telegramId: ?string, mobile: ?string, status: string, language: ?string, lastActivity: ?\DateTimeImmutable}
     */
    public static function summary(User $user): array
    {
        $account = $user->getTelegramAccount();
        $name = trim(($account?->getFirstName() ?? '').' '.($account?->getLastName() ?? ''));

        return [
            'id' => (int) $user->getId(),
            'name' => '' !== $name ? $name : ($user->getName() ?? ''),
            'username' => $account?->getUsername() ?: null,
            'telegramId' => $account?->getTelegramId(),
            'mobile' => $user->getMobile(),
            'status' => $user->getStatus(),
            'language' => $account?->getLanguageCode(),
            'lastActivity' => $account?->getLastActivityAt(),
        ];
    }
}
