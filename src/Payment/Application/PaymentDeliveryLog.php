<?php

declare(strict_types=1);

namespace App\Payment\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Whether the customer's delivery message of a new service ("service ready", with its links; one or more Telegram
 * messages) was sent (M2e E1), in service_notification_log (one row per order: key "order:<id>", the state in the type
 * column, so a state change is one atomic UPDATE; the payload holds payment_id, attempts, accepted, created, claim):
 * - expect(): written by the settlement inside its transaction, so it exists exactly when the order was fulfilled;
 * - claim(): pending (or a send that died: "sending" for longer than STALE_SENDING) -> sending, attempts + 1; only one
 *   process wins, so two settlements, a retry and the scheduled job never send at once. Every claim is an attempt, a
 *   stale reclaim too: at most MAX_ATTEMPTS, then the delivery is FAILED (exhaust(), finish()) and the admins alerted
 *   once by the one process that moved it;
 * - accepted(): one autocommit UPDATE right after Telegram accepted message n (review R3), independent of the bot
 *   message log and of any EntityManager: a later attempt sends only the messages not accepted yet;
 * - finish(): every message accepted -> SENT; otherwise pending again for a later attempt (FAILED after the last one).
 * Bounds: a message whose acceptance was recorded is never sent again. A message whose outcome is unknown (timeout, or
 * the process died between Telegram's acceptance and accepted()) is sent again by the next attempt: at least once, so
 * at most MAX_ATTEMPTS copies of it. A message Telegram refused every time is lost, and the admins are alerted.
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

    private const ATTEMPTS = "CAST(COALESCE(JSON_VALUE(payload, '$.attempts'), '0') AS UNSIGNED)";

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
            [$serviceId, $userId, self::PENDING, self::key($orderId), $this->now(), json_encode(['payment_id' => $paymentId, 'attempts' => 0, 'accepted' => [], 'created' => $this->now()])],
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

    /**
     * pending (or a stale send) -> sending, one more attempt; null unless this process may send now.
     *
     * @return array{token: string, attempt: int, accepted: list<int>}|null
     */
    public function claim(int $serviceId, int $orderId): ?array
    {
        $token = bin2hex(random_bytes(8));
        $claimed = $this->connection->executeStatement(
            'UPDATE service_notification_log SET type = ?, sent_at = ?, payload = JSON_SET(payload, \'$.attempts\', '.self::ATTEMPTS.' + 1, \'$.claim\', ?)
             WHERE service_id = ? AND key_name = ? AND (type = ? OR (type = ? AND sent_at < ?)) AND '.self::ATTEMPTS.' < ?',
            [self::SENDING, $this->now(), $token, $serviceId, self::key($orderId), self::PENDING, self::SENDING, $this->staleBefore(), self::MAX_ATTEMPTS],
        );
        if (1 !== $claimed) {
            return null;
        }
        $data = (array) json_decode((string) $this->connection->fetchOne('SELECT payload FROM service_notification_log WHERE service_id = ? AND key_name = ? AND type = ?', [$serviceId, self::key($orderId), self::SENDING]), true);

        return ['token' => $token, 'attempt' => (int) ($data['attempts'] ?? 1), 'accepted' => array_values(array_unique(array_map('intval', (array) ($data['accepted'] ?? []))))];
    }

    /** Telegram accepted message $index (0-based): recorded at once, never sent again. */
    public function accepted(int $serviceId, int $orderId, int $index): void
    {
        $this->connection->executeStatement(
            'UPDATE service_notification_log SET payload = JSON_ARRAY_APPEND(payload, \'$.accepted\', ?) WHERE service_id = ? AND key_name = ? AND type IN (?, ?)',
            [$index, $serviceId, self::key($orderId), self::PENDING, self::SENDING],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    /**
     * The end of the attempt that holds $token: SENT when every message was accepted, else pending again, or FAILED
     * after the last attempt. Returns the new state, or null when another process reclaimed it meanwhile.
     */
    public function finish(int $serviceId, int $orderId, string $token, bool $complete): ?string
    {
        $mine = 'service_id = ? AND key_name = ? AND type = ? AND JSON_VALUE(payload, \'$.claim\') = ?';
        $params = [$serviceId, self::key($orderId), self::SENDING, $token];
        if ($complete) {
            return 1 === $this->connection->executeStatement('UPDATE service_notification_log SET type = ?, sent_at = ? WHERE '.$mine, [self::SENT, $this->now(), ...$params]) ? self::SENT : null;
        }
        if (1 === $this->connection->executeStatement('UPDATE service_notification_log SET type = ?, sent_at = ? WHERE '.$mine.' AND '.self::ATTEMPTS.' >= ?', [self::FAILED, $this->now(), ...$params, self::MAX_ATTEMPTS])) {
            return self::FAILED;
        }

        return 1 === $this->connection->executeStatement('UPDATE service_notification_log SET type = ?, sent_at = ? WHERE '.$mine, [self::PENDING, $this->now(), ...$params]) ? self::PENDING : null;
    }

    /** A due delivery whose attempts are used up (the last one died): FAILED; true for the one process that moved it. */
    public function exhaust(int $serviceId, int $orderId): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE service_notification_log SET type = ?, sent_at = ? WHERE service_id = ? AND key_name = ? AND (type = ? OR (type = ? AND sent_at < ?)) AND '.self::ATTEMPTS.' >= ?',
            [self::FAILED, $this->now(), $serviceId, self::key($orderId), self::PENDING, self::SENDING, $this->staleBefore(), self::MAX_ATTEMPTS],
        );
    }

    /**
     * Deliveries to send again: pending (never claimed, or not complete), or a send that died (stale "sending").
     *
     * @return list<array{service_id: int, order_id: int, payment_id: int}>
     */
    public function due(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT service_id, key_name, payload FROM service_notification_log WHERE type = ? OR (type = ? AND sent_at < ?) ORDER BY id LIMIT '.max(1, $limit),
            [self::PENDING, self::SENDING, $this->staleBefore()],
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

    private function staleBefore(): string
    {
        return $this->clock->now()->modify(self::STALE_SENDING)->format('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
