<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Provisioning\Domain\VpnServiceStatus;

/**
 * Which services of a plan a move run looks at (docs/design/plan-inbound-change.md 6.2): one service, only those on one
 * old inbound, and the opt-in statuses (active always; suspended, expired and trial services only when asked; deleted
 * never). limit / afterId page through a large plan (CLI). Stored as the run's scope (inbound_move_run.scope) and frozen
 * with it.
 */
final readonly class InboundMoveScope
{
    public function __construct(
        public ?int $serviceId = null,
        public ?int $fromInboundId = null,
        public bool $includeSuspended = false,
        public bool $includeExpired = false,
        public bool $includeTrials = false,
        public ?int $limit = null,
        public int $afterId = 0,
    ) {
    }

    /** Whether a service with this status is in scope; deleted never is. */
    public function statusInScope(string $status): bool
    {
        return match ($status) {
            VpnServiceStatus::ACTIVE => true,
            VpnServiceStatus::SUSPENDED => $this->includeSuspended,
            VpnServiceStatus::EXPIRED => $this->includeExpired,
            default => false,
        };
    }

    /**
     * @return array{service: int|null, from_inbound: int|null, include_suspended: bool, include_expired: bool, include_trials: bool, limit: int|null, after_id: int}
     */
    public function toArray(): array
    {
        return [
            'service' => $this->serviceId,
            'from_inbound' => $this->fromInboundId,
            'include_suspended' => $this->includeSuspended,
            'include_expired' => $this->includeExpired,
            'include_trials' => $this->includeTrials,
            'limit' => $this->limit,
            'after_id' => $this->afterId,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    public static function fromArray(array $scope): self
    {
        $int = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return new self(
            $int($scope['service'] ?? null),
            $int($scope['from_inbound'] ?? null),
            true === ($scope['include_suspended'] ?? false),
            true === ($scope['include_expired'] ?? false),
            true === ($scope['include_trials'] ?? false),
            $int($scope['limit'] ?? null),
            (int) ($int($scope['after_id'] ?? null) ?? 0),
        );
    }
}
