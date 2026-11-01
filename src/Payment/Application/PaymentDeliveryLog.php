<?php

declare(strict_types=1);

namespace App\Payment\Application;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Whether the customer's delivery message of a new service ("service ready", with its links) was sent (M2e E1), in
 * service_notification_log (one row per order: key "order:<id>", the state in the type column, so a state change is one
 * atomic UPDATE):
 * - expect(): written by the settlement inside its transaction, so it exists exactly when the order was fulfilled;
 * - claim(): pending (or a send that died: "sending" for longer than STALE_SENDING) -> sending; only one process wins,
 *   so two settlements, a retry and the scheduled job never send it twice;
 * - sent() / release(): after the send; a send whose first message failed is released (pending again, attempt + 1),
 *   at most MAX_ATTEMPTS times, then FAILED. A process killed between Telegram's answer and sent() leaves "sending":
 *   that one message may be sent again once stale (the only duplicate this allows).
 * - abandon(): never to be sent (review R1, D5): the service changed owner, is no longer active or is gone, the payment is
 *   no longer confirmed, or the delivery is older than MAX_AGE (a "service ready" message an hour late is no longer
 *   expected and the service may have changed hands meanwhile); ABANDONED, with the reason in the payload.
 * Orders settled before M2e have no row and are never resent. The follow-up after the attach pass is claimed once
 * per order (followUpOnce()).
 */
final class PaymentDeliveryLog
{
    public const PENDING = 'delivery_pending';
    public const SENDING = 'delivery_sending';
    public const SENT = 'delivery_sent';
    public const FAILED = 'delivery_failed';
    public const ABANDONED = 'delivery_abandoned';
    public const FOLLOW_UP = 'delivery_follow_up';
    public const STALE_SENDING = '-5 minutes';
    public const MAX_ATTEMPTS = 3;
    /** A delivery not sent within this time after the sale is abandoned, never sent (review R1). */
    public const MAX_AGE = '-60 minutes';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function key(int $orderId): string
    {
        return 'order:'.$orderId;
    }

    /** Inside the settlement's transaction: this order's delivery message is due. */
    public function expect(int $serviceId, int $userId, int $orderId, int $paymentId): void
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO service_notification_log (service_id, user_id, type, key_name, sent_at, payload) VALUES (?, ?, ?, ?, ?, ?)',
            [$serviceId, $userId, self::PENDING, self::key($orderId), $this->now(), json_encode(['payment_id' => $paymentId, 'attempts' => 0, 'created' => $this->now()])],
        );
    }

    /** The state of this order's delivery (null: no row, an order settled before M2e). */
    public function state(int $serviceId, int $orderId): ?string
    {
        $type = $this->connection->fetchOne(
            'SELECT type FROM service_notification_log WHERE service_id = ? AND key_name = ? AND type IN (?, ?, ?, ?, ?)',
            [$serviceId, self::key($orderId), self::PENDING, self::SENDING, self::SENT, self::FAILED, self::ABANDONED],
        );

        return false === $type ? null : (string) $type;
    }

    /** pending (or a stale send) -> sending; true for the one process that may send now. */
    public function claim(int $serviceId, int $orderId): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE service_notification_log SET type = ?, sent_at = ? WHERE service_id = ? AND key_name = ? AND (type = ? OR (type = ? AND sent_at < ?))',
            [self::SENDING, $this->now(), $serviceId, self::key($orderId), self::PENDING, self::SENDING, $this->clock->now()->modify(self::STALE_SENDING)->format('Y-m-d H:i:s')],
        );
    }

    public function sent(int $serviceId, int $orderId): void
    {
        $this->move($serviceId, $orderId, self::SENT);
    }

    /** Nothing reached the customer: pending again for the retry or the scheduled job, or FAILED after MAX_ATTEMPTS. */
    public function release(int $serviceId, int $orderId): void
    {
        $payload = $this->connection->fetchOne('SELECT payload FROM service_notification_log WHERE service_id = ? AND key_name = ? AND type = ?', [$serviceId, self::key($orderId), self::SENDING]);
        $data = is_string($payload) ? (array) json_decode($payload, true) : [];
        $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
        $this->connection->executeStatement(
            'UPDATE service_notification_log SET type = ?, payload = ?, sent_at = ? WHERE service_id = ? AND key_name = ? AND type = ?',
            [$data['attempts'] >= self::MAX_ATTEMPTS ? self::FAILED : self::PENDING, json_encode($data), $this->now(), $serviceId, self::key($orderId), self::SENDING],
        );
    }

    /**
     * Deliveries to send again: pending (never claimed, or released), or a send that died (stale "sending").
     *
     * @return list<array{service_id: int, order_id: int, payment_id: int}>
     */
    public function due(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT service_id, key_name, payload FROM service_notification_log WHERE type = ? OR (type = ? AND sent_at < ?) ORDER BY id LIMIT '.max(1, $limit),
            [self::PENDING, self::SENDING, $this->clock->now()->modify(self::STALE_SENDING)->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $row): array => [
            'service_id' => (int) $row['service_id'],
            'order_id' => (int) substr((string) $row['key_name'], strlen('order:')),
            'payment_id' => (int) ((array) json_decode((string) $row['payload'], true))['payment_id'],
        ], $rows);
    }

    /** Never to be sent (see the class comment): ABANDONED with its reason; true when this call abandoned it. */
    public function abandon(int $serviceId, int $orderId, string $reason): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE service_notification_log SET type = ?, sent_at = ?, payload = JSON_SET(COALESCE(payload, \'{}\'), \'$.reason\', ?) WHERE service_id = ? AND key_name = ? AND type IN (?, ?)',
            [self::ABANDONED, $this->now(), $reason, $serviceId, self::key($orderId), self::PENDING, self::SENDING],
        );
    }

    /** True when the sale that made this delivery due is older than MAX_AGE. */
    public function tooOld(int $serviceId, int $orderId): bool
    {
        $row = $this->connection->fetchAssociative('SELECT payload, sent_at FROM service_notification_log WHERE service_id = ? AND key_name = ? AND type IN (?, ?)', [$serviceId, self::key($orderId), self::PENDING, self::SENDING]);
        if (false === $row) {
            return false;
        }
        $created = ((array) json_decode((string) $row['payload'], true))['created'] ?? $row['sent_at'];

        return (string) $created < $this->clock->now()->modify(self::MAX_AGE)->format('Y-m-d H:i:s');
    }

    /** True once per order: the follow-up after the attach pass may be sent. */
    public function followUpOnce(int $serviceId, int $userId, int $orderId, int $attached): bool
    {
        return 1 === $this->connection->executeStatement(
            'INSERT IGNORE INTO service_notification_log (service_id, user_id, type, key_name, sent_at, payload) VALUES (?, ?, ?, ?, ?, ?)',
            [$serviceId, $userId, self::FOLLOW_UP, self::key($orderId), $this->now(), json_encode(['attached' => $attached])],
        );
    }

    private function move(int $serviceId, int $orderId, string $to): void
    {
        $this->connection->executeStatement(
            'UPDATE service_notification_log SET type = ?, sent_at = ? WHERE service_id = ? AND key_name = ? AND type = ?',
            [$to, $this->now(), $serviceId, self::key($orderId), self::SENDING],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
