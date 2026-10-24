<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs a state-changing checkout call once per client-generated Idempotency-Key.
 *
 * In one database transaction the (user, key) row of checkout_request is inserted first, then the action runs and its
 * answer is stored on the row. A second call with the key gets that stored answer: sequentially it finds the row; in
 * parallel its insert waits on the unique index until the first call commits, then fails and reads the row. So a
 * double tap or a retry after a lost response can never create a second order or payment. An action that throws
 * rolls everything back, its own changes included, and the key can be used again.
 */
final class CheckoutIdempotency
{
    public const HEADER = 'Idempotency-Key';
    // /D: no trailing newline (it would be another key for the same request).
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param string                       $scope  what the key is for; the same key for another scope is refused
     * @param callable(): array<string, mixed> $action
     *
     * @return array<string, mixed> the action's answer (the first call's, for a repeat)
     *
     * @throws CheckoutFailure idempotency_key_required, idempotency_conflict, or the action's own
     */
    public function once(User $user, ?string $key, string $scope, callable $action): array
    {
        $key = trim((string) $key);
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new CheckoutFailure('idempotency_key_required', 400);
        }

        $previous = $this->previous($user, $key, $scope);
        if (null !== $previous) {
            return $previous;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $connection->insert('checkout_request', [
                'user_id' => $user->getId(),
                'idempotency_key' => $key,
                'scope' => $scope,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel call with this key committed first: answer like it did.
            $connection->rollBack();

            return $this->previous($user, $key, $scope) ?? throw new CheckoutFailure('idempotency_conflict', 409);
        }

        try {
            $result = $action();
            $connection->update('checkout_request', ['result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['user_id' => $user->getId(), 'idempotency_key' => $key]);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Like once(), for an action that must not run inside an outer transaction (it locks and commits on its own, e.g.
     * a payment settlement, or calls a panel): the key is claimed in its own short transaction first, then the action
     * runs, then its answer is stored. A repeat gets the stored answer; a parallel duplicate arriving while the first
     * call still runs is refused (idempotency_in_progress); an action that throws frees the key again.
     *
     * @param callable(): array<string, mixed> $action
     *
     * @return array<string, mixed>
     *
     * @throws CheckoutFailure idempotency_key_required, idempotency_conflict, idempotency_in_progress, or the action's own
     */
    public function claimOnce(User $user, ?string $key, string $scope, callable $action): array
    {
        $key = trim((string) $key);
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new CheckoutFailure('idempotency_key_required', 400);
        }

        $previous = $this->previous($user, $key, $scope);
        if (null !== $previous) {
            return $previous;
        }

        $connection = $this->entityManager->getConnection();
        try {
            $connection->insert('checkout_request', [
                'user_id' => $user->getId(),
                'idempotency_key' => $key,
                'scope' => $scope,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->previous($user, $key, $scope) ?? throw new CheckoutFailure('idempotency_conflict', 409);
        }

        try {
            $result = $action();
        } catch (\Throwable $e) {
            $connection->delete('checkout_request', ['user_id' => $user->getId(), 'idempotency_key' => $key]);

            throw $e;
        }
        $connection->update('checkout_request', ['result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['user_id' => $user->getId(), 'idempotency_key' => $key]);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previous(User $user, string $key, string $scope): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT scope, result FROM checkout_request WHERE user_id = ? AND idempotency_key = ?',
            [$user->getId(), $key]
        );
        if (false === $row) {
            return null;
        }
        if ($row['scope'] !== $scope) {
            throw new CheckoutFailure('idempotency_conflict', 409);
        }
        if (null === $row['result']) {
            // Claimed by claimOnce() and still running (once() never commits a row without its answer).
            throw new CheckoutFailure('idempotency_in_progress', 409);
        }
        $result = json_decode((string) $row['result'], true);

        return is_array($result) ? $result : throw new CheckoutFailure('idempotency_conflict', 409);
    }
}
