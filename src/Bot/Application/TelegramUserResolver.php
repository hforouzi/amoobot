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
            $user = new User();
            $user->setName(trim(((string) ($telegramUser['first_name'] ?? '')).' '.((string) ($telegramUser['last_name'] ?? ''))));
            $this->entityManager->persist($user);

            $account = (new TelegramAccount())
                ->setUser($user)
                ->setTelegramId($telegramId);
            $this->entityManager->persist($account);
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
}
