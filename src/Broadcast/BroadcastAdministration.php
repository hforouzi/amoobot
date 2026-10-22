<?php

declare(strict_types=1);

namespace App\Broadcast;

use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's broadcast actions (BROADCAST_SEND), for the web panel, the bot and the Mini App: send a draft now (its
 * recipients prepared and the first batch sent; BroadcastSender and app:broadcast:run deliver the rest), or prepare
 * it first and send exactly the recipients prepared (the Mini App), cancel, retry the failed deliveries, duplicate a
 * finished one as a new draft. A text Telegram could not parse in its format (BroadcastFormat) is neither prepared nor
 * sent (BroadcastFormatException), and failures for that reason are not retried while the text is the same. Sending is
 * BroadcastSender's (one batch at a time, paced for Telegram; it stops a broadcast whose text Telegram cannot parse).
 * The caller audits.
 */
final class BroadcastAdministration
{
    /** Batch size of an admin's send / retry, as the web and the bot used. */
    public const BATCH = 50;

    public const CANCELLABLE = [BroadcastMessage::STATUS_DRAFT, BroadcastMessage::STATUS_SCHEDULED, BroadcastMessage::STATUS_SENDING];

    /** Finished broadcasts, which can be duplicated as a new draft (a draft is edited instead). */
    public const DUPLICABLE = [BroadcastMessage::STATUS_SENT, BroadcastMessage::STATUS_FAILED, BroadcastMessage::STATUS_STOPPED, BroadcastMessage::STATUS_CANCELLED];

    /** What a duplicate keeps of the metadata: the audience's options, not the sending state. */
    private const DUPLICATED_METADATA = ['expiringDays'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BroadcastSender $broadcastSender,
        private readonly BroadcastRecipientResolver $recipientResolver,
    ) {
    }

    /** How many people the broadcast's audience reaches now (nothing is stored). */
    public function audienceCount(BroadcastMessage $broadcast): int
    {
        return count($this->recipientResolver->resolveRecipients($broadcast));
    }

    /** Why Telegram would refuse the text in its parse mode, or null. */
    public function formatProblem(BroadcastMessage $broadcast): ?BroadcastFormatProblem
    {
        return BroadcastFormat::problem($broadcast->getBody(), $broadcast->getParseMode());
    }

    /**
     * Lists a draft's recipients (one delivery row each; people already listed are kept).
     *
     * @return BroadcastSummary|null null: not a draft, nothing done
     *
     * @throws BroadcastFormatException the text does not parse in its format
     */
    public function prepare(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        if (!$broadcast->isEditable()) {
            return null;
        }
        $this->assertFormat($broadcast);

        return $this->broadcastSender->prepareDeliveries($broadcast);
    }

    /**
     * Sends a prepared draft to exactly the recipients listed (no one is added since the admin confirmed their count):
     * the first batch now, the rest by app:broadcast:run.
     *
     * @return BroadcastSummary|null null: not a draft or not prepared, nothing done
     *
     * @throws BroadcastFormatException the text does not parse in its format
     */
    public function sendPrepared(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        if (!$broadcast->isEditable() || 0 === $this->broadcastSender->deliveryCount($broadcast)) {
            return null;
        }
        $this->assertFormat($broadcast);

        $broadcast->setStatus(BroadcastMessage::STATUS_SENDING)->setSendAt(null);

        return $this->broadcastSender->sendPending($broadcast, self::BATCH);
    }

    /**
     * Forgets a draft's prepared recipients (after its text or audience changed it must be prepared again).
     *
     * @return int how many were dropped
     */
    public function dropRecipients(BroadcastMessage $broadcast): int
    {
        if (!$broadcast->isEditable()) {
            return 0;
        }

        $dropped = (int) $this->entityManager->createQueryBuilder()->delete(BroadcastDelivery::class, 'd')
            ->where('d.broadcast = :broadcast AND d.status = :pending')
            ->setParameter('broadcast', $broadcast)->setParameter('pending', BroadcastDelivery::STATUS_PENDING)
            ->getQuery()->execute();
        $this->broadcastSender->refreshCounts($broadcast);
        $this->entityManager->flush();

        return $dropped;
    }

    public static function isCancellable(BroadcastMessage $broadcast): bool
    {
        return in_array($broadcast->getStatus(), self::CANCELLABLE, true);
    }

    /**
     * Was BroadcastMessageCrudController::sendNow(): only a draft is sent.
     *
     * @return BroadcastSummary|null null: not a draft, nothing done
     *
     * @throws BroadcastFormatException the text does not parse in its format
     */
    public function sendNow(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        if (BroadcastMessage::STATUS_DRAFT !== $broadcast->getStatus()) {
            return null;
        }
        $this->assertFormat($broadcast);

        $broadcast->setStatus(BroadcastMessage::STATUS_SENDING)->setSendAt(null);
        $this->broadcastSender->prepareDeliveries($broadcast);

        return $this->broadcastSender->sendPending($broadcast, self::BATCH);
    }

    /**
     * Was in BroadcastMessageCrudController::cancelBroadcast() and AdminBroadcastsHandler::cancel().
     *
     * @return bool false: not cancellable (already sent, failed or cancelled), nothing done
     */
    public function cancel(BroadcastMessage $broadcast): bool
    {
        if (!self::isCancellable($broadcast)) {
            return false;
        }

        $broadcast
            ->setStatus(BroadcastMessage::STATUS_CANCELLED)
            ->setFinishedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /**
     * Some deliveries failed because Telegram could not parse the text, and it is still the same text: a retry would
     * fail again (the broadcast is duplicated as a new draft and corrected instead).
     */
    public function retryBlocked(BroadcastMessage $broadcast): bool
    {
        if (0 === $this->broadcastSender->parseFailures($broadcast)) {
            return false;
        }
        $fingerprint = BroadcastSender::formatFingerprint($broadcast);
        $failedWith = $broadcast->getMetadata()['formatError']['fingerprint'] ?? $fingerprint;

        return $failedWith === $fingerprint || null !== $this->formatProblem($broadcast);
    }

    /**
     * @return BroadcastSummary|null null: refused, the failures are parse errors of the same text (retryBlocked)
     */
    public function retryFailed(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        if ($this->retryBlocked($broadcast)) {
            return null;
        }

        return $this->broadcastSender->retryFailed($broadcast, self::BATCH);
    }

    public static function isDuplicable(BroadcastMessage $broadcast): bool
    {
        return in_array($broadcast->getStatus(), self::DUPLICABLE, true);
    }

    /**
     * A new draft with a finished broadcast's text, title, format, link preview and audience (not its schedule, counts
     * or recipients), to be corrected and sent again.
     *
     * @return BroadcastMessage|null null: not finished (a draft is edited, one being sent is cancelled first)
     */
    public function duplicate(BroadcastMessage $source, ?AdminUser $by): ?BroadcastMessage
    {
        if (!self::isDuplicable($source)) {
            return null;
        }

        $metadata = array_intersect_key($source->getMetadata() ?? [], array_flip(self::DUPLICATED_METADATA)) + ['duplicatedFrom' => $source->getId()];
        $copy = (new BroadcastMessage())
            ->setTitle($source->getTitle())
            ->setBody($source->getBody())
            ->setParseMode($source->getParseMode())
            ->setDisableWebPagePreview($source->isDisableWebPagePreview())
            ->setTargetType($source->getTargetType())
            ->setTargetUserIds($source->getTargetUserIds())
            ->setMetadata($metadata)
            ->setCreatedBy($by)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    private function assertFormat(BroadcastMessage $broadcast): void
    {
        $problem = $this->formatProblem($broadcast);
        if (null !== $problem) {
            throw new BroadcastFormatException($problem);
        }
    }
}
