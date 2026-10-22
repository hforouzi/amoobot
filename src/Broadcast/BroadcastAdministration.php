<?php

declare(strict_types=1);

namespace App\Broadcast;

use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's broadcast actions (BROADCAST_SEND), for the web panel, the bot and the Mini App: send a draft now (its
 * recipients prepared and the first batch sent; BroadcastSender and app:broadcast:run deliver the rest), or prepare
 * it first and send exactly the recipients prepared (the Mini App), cancel, retry the failed deliveries. Sending is
 * BroadcastSender's (one batch at a time, paced for Telegram). The caller audits.
 */
final class BroadcastAdministration
{
    /** Batch size of an admin's send / retry, as the web and the bot used. */
    public const BATCH = 50;

    public const CANCELLABLE = [BroadcastMessage::STATUS_DRAFT, BroadcastMessage::STATUS_SCHEDULED, BroadcastMessage::STATUS_SENDING];

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

    /**
     * Lists a draft's recipients (one delivery row each; people already listed are kept).
     *
     * @return BroadcastSummary|null null: not a draft, nothing done
     */
    public function prepare(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        return $broadcast->isEditable() ? $this->broadcastSender->prepareDeliveries($broadcast) : null;
    }

    /**
     * Sends a prepared draft to exactly the recipients listed (no one is added since the admin confirmed their count):
     * the first batch now, the rest by app:broadcast:run.
     *
     * @return BroadcastSummary|null null: not a draft or not prepared, nothing done
     */
    public function sendPrepared(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        if (!$broadcast->isEditable() || 0 === $this->broadcastSender->deliveryCount($broadcast)) {
            return null;
        }

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
     */
    public function sendNow(BroadcastMessage $broadcast): ?BroadcastSummary
    {
        if (BroadcastMessage::STATUS_DRAFT !== $broadcast->getStatus()) {
            return null;
        }

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

    public function retryFailed(BroadcastMessage $broadcast): BroadcastSummary
    {
        return $this->broadcastSender->retryFailed($broadcast, self::BATCH);
    }
}
