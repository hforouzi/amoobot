<?php

declare(strict_types=1);

namespace App\Broadcast;

use App\Entity\BroadcastMessage;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;

final class BroadcastRecipientResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $adminChatId = null,
    ) {
    }

    /**
     * @return list<BroadcastRecipient>
     */
    public function resolveRecipients(BroadcastMessage $broadcast): array
    {
        $recipients = match ($broadcast->getTargetType()) {
            BroadcastMessage::TARGET_ACTIVE_USERS => $this->telegramAccounts(),
            BroadcastMessage::TARGET_USERS_WITH_ACTIVE_SERVICE => $this->usersWithActiveServices(),
            BroadcastMessage::TARGET_USERS_WITH_EXPIRING_SERVICE => $this->usersWithExpiringServices($broadcast),
            BroadcastMessage::TARGET_SELECTED_USERS => $this->selectedUsers($broadcast),
            BroadcastMessage::TARGET_ADMINS => $this->admins(),
            BroadcastMessage::TARGET_ALL => $this->telegramAccounts(),
            default => [],
        };

        $deduplicated = [];
        foreach ($recipients as $recipient) {
            if ('' === trim($recipient->chatId)) {
                continue;
            }

            $deduplicated[$recipient->chatId] = $recipient;
        }

        return array_values($deduplicated);
    }

    /**
     * @return list<BroadcastRecipient>
     */
    private function telegramAccounts(): array
    {
        /** @var list<TelegramAccount> $accounts */
        $accounts = $this->entityManager->getRepository(TelegramAccount::class)
            ->createQueryBuilder('telegram')
            ->andWhere('telegram.telegramId IS NOT NULL')
            ->andWhere("telegram.telegramId != ''")
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (TelegramAccount $account): BroadcastRecipient => new BroadcastRecipient($account->getTelegramId(), $account, $account->getUser()),
            $accounts
        );
    }

    /**
     * @return list<BroadcastRecipient>
     */
    private function usersWithActiveServices(): array
    {
        /** @var list<VpnService> $services */
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->innerJoin('service.user', 'user')
            ->innerJoin('user.telegramAccount', 'telegram')
            ->addSelect('user', 'telegram')
            ->andWhere('service.status = :status')
            ->setParameter('status', VpnServiceStatus::ACTIVE)
            ->getQuery()
            ->getResult();

        return $this->recipientsFromServices($services);
    }

    /**
     * @return list<BroadcastRecipient>
     */
    private function usersWithExpiringServices(BroadcastMessage $broadcast): array
    {
        $metadata = $broadcast->getMetadata() ?? [];
        $days = max(1, (int) ($metadata['expiringDays'] ?? 3));
        $now = new \DateTimeImmutable();
        $deadline = $now->modify(sprintf('+%d days', $days));

        /** @var list<VpnService> $services */
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->innerJoin('service.user', 'user')
            ->innerJoin('user.telegramAccount', 'telegram')
            ->addSelect('user', 'telegram')
            ->andWhere('service.status = :status')
            ->andWhere('service.expiresAt IS NOT NULL')
            ->andWhere('service.expiresAt >= :now')
            ->andWhere('service.expiresAt <= :deadline')
            ->setParameter('status', VpnServiceStatus::ACTIVE)
            ->setParameter('now', $now)
            ->setParameter('deadline', $deadline)
            ->getQuery()
            ->getResult();

        return $this->recipientsFromServices($services);
    }

    /**
     * @return list<BroadcastRecipient>
     */
    private function selectedUsers(BroadcastMessage $broadcast): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $broadcast->getTargetUserIds() ?? []))));
        if ([] === $ids) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('user')
            ->innerJoin('user.telegramAccount', 'telegram')
            ->addSelect('telegram')
            ->andWhere('user.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $recipients = [];
        foreach ($users as $user) {
            $account = $user->getTelegramAccount();
            if ($account instanceof TelegramAccount) {
                $recipients[] = new BroadcastRecipient($account->getTelegramId(), $account, $user);
            }
        }

        return $recipients;
    }

    /**
     * @return list<BroadcastRecipient>
     */
    private function admins(): array
    {
        $chatId = trim((string) $this->adminChatId);
        if ('' === $chatId) {
            return [];
        }

        $account = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $chatId]);

        return [new BroadcastRecipient($chatId, $account instanceof TelegramAccount ? $account : null, $account instanceof TelegramAccount ? $account->getUser() : null)];
    }

    /**
     * @param list<VpnService> $services
     *
     * @return list<BroadcastRecipient>
     */
    private function recipientsFromServices(array $services): array
    {
        $recipients = [];
        foreach ($services as $service) {
            $user = $service->getUser();
            $account = $user->getTelegramAccount();
            if (!$account instanceof TelegramAccount) {
                continue;
            }

            $recipients[] = new BroadcastRecipient($account->getTelegramId(), $account, $user);
        }

        return $recipients;
    }
}
