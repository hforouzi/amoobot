<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\TelegramAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUserResolver
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function resolveFromTelegramUser(array $telegramUser): TelegramAccount
    {
        $telegramId = (string) ($telegramUser['id'] ?? '');

        if ('' === $telegramId) {
            throw new \InvalidArgumentException('telegram id is required');
        }

        $account = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId]);

        if (!$account instanceof TelegramAccount) {
            $account = $this->createAccount($telegramId, trim(((string) ($telegramUser['first_name'] ?? '')).' '.((string) ($telegramUser['last_name'] ?? ''))));
        }

        $account
            ->setUsername($telegramUser['username'] ?? null)
            ->setFirstName($telegramUser['first_name'] ?? null)
            ->setLastName($telegramUser['last_name'] ?? null)
            ->setLanguageCode($telegramUser['language_code'] ?? null)
            ->setLastActivityAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $account;
    }

    /**
     * A new User with its TelegramAccount, persisted, not flushed: what a first message from this Telegram id creates,
     * and what app:panel:import-clients creates for a panel client's owner who has no account yet (lastActivityAt
     * stays null until the person writes to the bot; their first message then finds this account).
     */
    public function createAccount(string $telegramId, string $name = ''): TelegramAccount
    {
        $user = new User();
        $user->setName($name);
        $this->entityManager->persist($user);

        $account = (new TelegramAccount())
            ->setUser($user)
            ->setTelegramId($telegramId);
        $user->setTelegramAccount($account);
        $this->entityManager->persist($account);

        return $account;
    }
}
