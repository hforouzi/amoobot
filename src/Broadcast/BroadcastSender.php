<?php

declare(strict_types=1);

namespace App\Broadcast;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use Doctrine\ORM\EntityManagerInterface;

final class BroadcastSender
{
    /**
     * A broadcast stops itself when this many deliveries failed because Telegram could not parse its text and none was
     * sent: the text is the same for everyone, so the rest would fail too.
     */
    public const FORMAT_ERROR_STOP_AFTER = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BroadcastRecipientResolver $recipientResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly BroadcastStopAlert $stopAlert,
    ) {
    }

    public function prepareDeliveries(BroadcastMessage $broadcast, bool $dryRun = false): BroadcastSummary
    {
        $this->assertValidBody($broadcast);

        $recipients = $this->recipientResolver->resolveRecipients($broadcast);
        $prepared = 0;
        $duplicates = 0;

        foreach ($recipients as $recipient) {
            $existing = $this->entityManager->getRepository(BroadcastDelivery::class)->findOneBy([
                'broadcast' => $broadcast,
                'chatId' => $recipient->chatId,
            ]);
            if ($existing instanceof BroadcastDelivery) {
                ++$duplicates;
                continue;
            }

            ++$prepared;
            if ($dryRun) {
                continue;
            }

            $delivery = (new BroadcastDelivery())
                ->setBroadcast($broadcast)
                ->setChatId($recipient->chatId)
                ->setTelegramAccount($recipient->telegramAccount)
                ->setUser($recipient->user);
            $this->entityManager->persist($delivery);
        }

        if (!$dryRun) {
            $broadcast
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->refreshCounts($broadcast);
            $this->entityManager->flush();
        }

        return new BroadcastSummary(prepared: $prepared, duplicates: $duplicates);
    }

    public function sendPending(BroadcastMessage $broadcast, int $limit = 50, bool $dryRun = false): BroadcastSummary
    {
        $this->assertValidBody($broadcast);
        $limit = max(1, $limit);

        if (BroadcastMessage::STATUS_CANCELLED === $broadcast->getStatus()) {
            return new BroadcastSummary();
        }

        if (!$dryRun && null === $broadcast->getStartedAt()) {
            $broadcast->setStartedAt(new \DateTimeImmutable());
        }

        if (!$dryRun && in_array($broadcast->getStatus(), [BroadcastMessage::STATUS_DRAFT, BroadcastMessage::STATUS_SCHEDULED, BroadcastMessage::STATUS_FAILED], true)) {
            $broadcast->setStatus(BroadcastMessage::STATUS_SENDING);
        }

        /** @var list<BroadcastDelivery> $deliveries */
        $deliveries = $this->entityManager->getRepository(BroadcastDelivery::class)->findBy(
            ['broadcast' => $broadcast, 'status' => BroadcastDelivery::STATUS_PENDING],
            ['id' => 'ASC'],
            $limit
        );

        $sent = 0;
        $failed = 0;
        $parseFailures = null;
        $parseError = null;
        $now = new \DateTimeImmutable();

        foreach ($deliveries as $delivery) {
            if ($dryRun) {
                ++$sent;
                continue;
            }

            try {
                $messageId = $this->telegramApiClient->sendBroadcastMessageStrict(
                    $delivery->getChatId(),
                    $broadcast->getBody(),
                    $broadcast->getParseMode(),
                    $broadcast->isDisableWebPagePreview()
                );
                $delivery
                    ->setStatus(BroadcastDelivery::STATUS_SENT)
                    ->setTelegramMessageId($messageId)
                    ->setErrorMessage(null)
                    ->setSentAt($now)
                    ->setUpdatedAt($now);
                ++$sent;
            } catch (\Throwable $e) {
                $delivery
                    ->setStatus(BroadcastDelivery::STATUS_FAILED)
                    ->setErrorMessage($e->getMessage())
                    ->setUpdatedAt($now);
                ++$failed;
                if (BroadcastFormat::isParseError($e->getMessage())) {
                    $parseFailures = ($parseFailures ?? $this->parseFailures($broadcast)) + 1;
                    $parseError = $e->getMessage();
                    if (0 === $broadcast->getSentCount() + $sent && $parseFailures >= self::FORMAT_ERROR_STOP_AFTER) {
                        break;
                    }
                }
            }

            usleep(50000);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $this->refreshCounts($broadcast);
            $stop = null !== $parseError && 0 === $broadcast->getSentCount() && $parseFailures >= self::FORMAT_ERROR_STOP_AFTER;
            if ($stop) {
                $this->stopForFormat($broadcast, $parseError);
            } else {
                $this->finishIfComplete($broadcast);
            }
            $this->entityManager->flush();
            if ($stop && !isset($broadcast->getMetadata()['stopAlertedAt'])) {
                $this->stopAlert->formatError($broadcast, $parseError);
                $broadcast->setMetadata(['stopAlertedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)] + ($broadcast->getMetadata() ?? []));
                $this->entityManager->flush();
            }
        }

        $pending = $dryRun
            ? max(0, $this->deliveryCount($broadcast) - $sent)
            : (int) $this->entityManager->getRepository(BroadcastDelivery::class)->count([
                'broadcast' => $broadcast,
                'status' => BroadcastDelivery::STATUS_PENDING,
            ]);

        return new BroadcastSummary(pending: $pending, sent: $sent, failed: $failed);
    }

    public function retryFailed(BroadcastMessage $broadcast, int $limit = 50, bool $dryRun = false): BroadcastSummary
    {
        $limit = max(1, $limit);

        /** @var list<BroadcastDelivery> $deliveries */
        $deliveries = $this->entityManager->getRepository(BroadcastDelivery::class)->findBy(
            ['broadcast' => $broadcast, 'status' => BroadcastDelivery::STATUS_FAILED],
            ['id' => 'ASC'],
            $limit
        );

        if (!$dryRun) {
            $now = new \DateTimeImmutable();
            foreach ($deliveries as $delivery) {
                $delivery
                    ->setStatus(BroadcastDelivery::STATUS_PENDING)
                    ->setErrorMessage(null)
                    ->setUpdatedAt($now);
            }
            $broadcast
                ->setStatus(BroadcastMessage::STATUS_SENDING)
                ->setFinishedAt(null)
                ->setUpdatedAt($now);
            $this->entityManager->flush();
        }

        return $this->sendPending($broadcast, $limit, $dryRun);
    }

    public function runDue(int $limit = 50, bool $dryRun = false): BroadcastSummary
    {
        $now = new \DateTimeImmutable();

        /** @var list<BroadcastMessage> $broadcasts */
        $broadcasts = $this->entityManager->getRepository(BroadcastMessage::class)
            ->createQueryBuilder('broadcast')
            ->andWhere('broadcast.status = :sending OR (broadcast.status = :scheduled AND (broadcast.sendAt IS NULL OR broadcast.sendAt <= :now))')
            ->setParameter('sending', BroadcastMessage::STATUS_SENDING)
            ->setParameter('scheduled', BroadcastMessage::STATUS_SCHEDULED)
            ->setParameter('now', $now)
            ->orderBy('broadcast.sendAt', 'ASC')
            ->addOrderBy('broadcast.id', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $summary = new BroadcastSummary(broadcasts: count($broadcasts));
        foreach ($broadcasts as $broadcast) {
            if (0 === $this->deliveryCount($broadcast)) {
                $summary = $summary->merge($this->prepareDeliveries($broadcast, $dryRun));
            }

            $summary = $summary->merge($this->sendPending($broadcast, $limit, $dryRun));
        }

        return $summary;
    }

    public function refreshCounts(BroadcastMessage $broadcast): void
    {
        $repository = $this->entityManager->getRepository(BroadcastDelivery::class);
        $broadcast
            ->setTotalRecipients($this->deliveryCount($broadcast))
            ->setSentCount((int) $repository->count(['broadcast' => $broadcast, 'status' => BroadcastDelivery::STATUS_SENT]))
            ->setFailedCount((int) $repository->count(['broadcast' => $broadcast, 'status' => BroadcastDelivery::STATUS_FAILED]))
            ->setSkippedCount((int) $repository->count(['broadcast' => $broadcast, 'status' => BroadcastDelivery::STATUS_SKIPPED]))
            ->setUpdatedAt(new \DateTimeImmutable());
    }

    public function deliveryCount(BroadcastMessage $broadcast): int
    {
        return (int) $this->entityManager->getRepository(BroadcastDelivery::class)->count(['broadcast' => $broadcast]);
    }

    /** Failed deliveries of the broadcast that Telegram refused because it could not parse the text. */
    public function parseFailures(BroadcastMessage $broadcast): int
    {
        return (int) $this->entityManager->createQueryBuilder()->select('COUNT(d.id)')->from(BroadcastDelivery::class, 'd')
            ->where('d.broadcast = :broadcast AND d.status = :failed AND LOWER(d.errorMessage) LIKE :parse')
            ->setParameter('broadcast', $broadcast)->setParameter('failed', BroadcastDelivery::STATUS_FAILED)->setParameter('parse', "%can't parse entities%")
            ->getQuery()->getSingleScalarResult();
    }

    /** A digest of what was sent (text and parse mode): a format error stays until this changes. */
    public static function formatFingerprint(BroadcastMessage $broadcast): string
    {
        return hash('sha256', (string) $broadcast->getParseMode()."\0".$broadcast->getBody());
    }

    /**
     * The rest of the recipients are not tried (their deliveries stay pending); the reason is kept for the admin pages
     * and the retry refusal.
     */
    private function stopForFormat(BroadcastMessage $broadcast, string $error): void
    {
        $now = new \DateTimeImmutable();
        $broadcast
            ->setStatus(BroadcastMessage::STATUS_STOPPED)
            ->setFinishedAt($now)
            ->setUpdatedAt($now)
            ->setMetadata([
                'stopReason' => 'format_error',
                'formatError' => ['error' => BroadcastStopAlert::reason($error), 'fingerprint' => self::formatFingerprint($broadcast)],
            ] + ($broadcast->getMetadata() ?? []));
    }

    private function finishIfComplete(BroadcastMessage $broadcast): void
    {
        $pending = (int) $this->entityManager->getRepository(BroadcastDelivery::class)->count([
            'broadcast' => $broadcast,
            'status' => BroadcastDelivery::STATUS_PENDING,
        ]);

        if ($pending > 0 || BroadcastMessage::STATUS_CANCELLED === $broadcast->getStatus()) {
            return;
        }

        $broadcast
            ->setStatus($broadcast->getFailedCount() > 0 ? BroadcastMessage::STATUS_FAILED : BroadcastMessage::STATUS_SENT)
            ->setFinishedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());
    }

    private function assertValidBody(BroadcastMessage $broadcast): void
    {
        $body = $broadcast->getBody();
        if ('' === trim($body)) {
            throw new \InvalidArgumentException('Broadcast body must not be empty.');
        }

        if ($this->messageLength($body) > BroadcastMessage::TELEGRAM_MESSAGE_LIMIT) {
            throw new \InvalidArgumentException('Broadcast body exceeds Telegram sendMessage limit.');
        }
    }

    private function messageLength(string $body): int
    {
        $matches = [];
        if (1 === preg_match('//u', $body)) {
            $length = preg_match_all('/./us', $body, $matches);

            return false === $length ? strlen($body) : $length;
        }

        return strlen($body);
    }
}
