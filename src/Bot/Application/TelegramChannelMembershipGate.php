<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\RequiredChannel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TelegramChannelMembershipGate
{
    public const CONTEXT_PURCHASE = 'purchase';
    public const CONTEXT_TRIAL = 'trial';

    private const ALLOWED_STATUSES = ['creator', 'administrator', 'member'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(int|string $telegramUserId, string $context): MembershipGateResult
    {
        $channels = $this->requiredChannels($context);
        if ([] === $channels) {
            return MembershipGateResult::allowed();
        }

        $missing = [];
        $failedChecks = [];
        foreach ($channels as $channel) {
            try {
                $member = $this->telegramApiClient->getChatMember($channel->getChatId(), $telegramUserId);
                $status = strtolower(trim((string) ($member['status'] ?? '')));
                if (in_array($status, self::ALLOWED_STATUSES, true)) {
                    continue;
                }

                $missing[] = $channel;
            } catch (\Throwable $e) {
                $missing[] = $channel;
                $failedChecks[] = sprintf('%d:%s', (int) ($channel->getId() ?? 0), $e->getMessage());
                $this->logger->warning('Telegram required channel membership check failed', [
                    'exception' => $e,
                    'required_channel_id' => $channel->getId(),
                    'chat_id' => $channel->getChatId(),
                    'context' => $context,
                ]);
            }
        }

        if ([] === $missing) {
            return MembershipGateResult::allowed();
        }

        return new MembershipGateResult(false, $missing, $failedChecks);
    }

    /**
     * @return list<RequiredChannel>
     */
    private function requiredChannels(string $context): array
    {
        $field = match ($context) {
            self::CONTEXT_TRIAL => 'requireForTrial',
            default => 'requireForPurchase',
        };

        /** @var list<RequiredChannel> $channels */
        $channels = $this->entityManager->getRepository(RequiredChannel::class)
            ->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->andWhere(sprintf('c.%s = :required', $field))
            ->setParameter('active', true)
            ->setParameter('required', true)
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $channels;
    }
}
