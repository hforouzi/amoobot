<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run that moves a plan's existing services to another inbound (docs/design/plan-inbound-change.md 5.1, PR P3:
 * schema only). Nothing creates, reads or processes runs yet: the runner and its CLI come in P4, the pages in P7/P9.
 *
 * - kind: KIND_PRIMARY (a service's primary A → the target B, 4.3), KIND_ADD / KIND_REMOVE (an additional inbound, 6.4);
 * - targetInbound: the inbound frozen when the run was created (a later plan edit never changes a run). SET NULL: a
 *   run never blocks deleting an inbound; a run whose target is gone cannot go on;
 * - scope: the options it was created with (6.2), as given;
 * - status: STATUS_*; pauseReason when paused;
 * - graceHours, keepOld: phase 2 (4.2); requestedBy: the AdminUser id, NULL for the CLI or the bot admin (as
 *   AdminAuditLog::actorAdminUserId; no foreign key);
 * - the counters are cached for the run page; the move rows (ServiceInboundMove) are the record.
 *
 * One open run per plan (5.1): openPlanId is the plan's id while the status is open (STATUS_PLANNED, STATUS_RUNNING,
 * STATUS_PAUSED) and NULL otherwise, under a unique index, and a CHECK constraint of the migration keeps it in step
 * with the status for any writer (setStatus() here; SQL writers set both). A second open run for a plan is refused by
 * the database. Removed with its plan (ON DELETE CASCADE), and with it its move rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbound_move_run')]
#[ORM\UniqueConstraint(name: 'uniq_inbound_move_run_open_plan', columns: ['open_plan_id'])]
#[ORM\Index(name: 'idx_inbound_move_run_status', columns: ['status'])]
class InboundMoveRun
{
    public const KIND_PRIMARY = 'primary';
    public const KIND_ADD = 'add';
    public const KIND_REMOVE = 'remove';
    public const KINDS = [self::KIND_PRIMARY, self::KIND_ADD, self::KIND_REMOVE];

    public const STATUS_PLANNED = 'planned';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_PLANNED, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_DONE, self::STATUS_CANCELLED];
    /** A plan has at most one run in these (5.1). */
    public const OPEN_STATUSES = [self::STATUS_PLANNED, self::STATUS_RUNNING, self::STATUS_PAUSED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    /** The plan's id while the run is open, else NULL: unique, so one open run per plan (class comment). */
    #[ORM\Column(nullable: true)]
    private ?int $openPlanId;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnInbound $targetInbound;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $scope;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PLANNED;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pauseReason = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $graceHours;

    #[ORM\Column]
    private bool $keepOld;

    #[ORM\Column(nullable: true)]
    private ?int $requestedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $plannedCount = 0;

    #[ORM\Column]
    private int $doneCount = 0;

    #[ORM\Column]
    private int $failedCount = 0;

    #[ORM\Column]
    private int $skippedCount = 0;

    #[ORM\Column]
    private int $remainingCount = 0;

    /**
     * @param array<string, mixed> $scope
     */
    public function __construct(Plan $plan, string $kind, ?VpnInbound $targetInbound, array $scope, int $graceHours, bool $keepOld, ?int $requestedBy, \DateTimeImmutable $createdAt)
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown inbound move kind "%s".', $kind));
        }
        if (null === $plan->getId()) {
            throw new \InvalidArgumentException('An inbound move run needs a saved plan.');
        }
        $this->plan = $plan;
        $this->openPlanId = $plan->getId();
        $this->kind = $kind;
        $this->targetInbound = $targetInbound;
        $this->scope = $scope;
        $this->graceHours = $graceHours;
        $this->keepOld = $keepOld;
        $this->requestedBy = $requestedBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getOpenPlanId(): ?int
    {
        return $this->openPlanId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTargetInbound(): ?VpnInbound
    {
        return $this->targetInbound;
    }

    /** @return array<string, mixed> */
    public function getScope(): array
    {
        return $this->scope;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** The status, and openPlanId with it (the plan's id while open, NULL once done or cancelled). */
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown inbound move run status "%s".', $status));
        }
        $this->status = $status;
        $this->openPlanId = in_array($status, self::OPEN_STATUSES, true) ? $this->plan->getId() : null;

        return $this;
    }

    public function getPauseReason(): ?string
    {
        return $this->pauseReason;
    }

    public function setPauseReason(?string $pauseReason): self
    {
        $this->pauseReason = $pauseReason;

        return $this;
    }

    public function getGraceHours(): int
    {
        return $this->graceHours;
    }

    public function isKeepOld(): bool
    {
        return $this->keepOld;
    }

    public function getRequestedBy(): ?int
    {
        return $this->requestedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /**
     * @return array{planned: int, done: int, failed: int, skipped: int, remaining: int}
     */
    public function getCounts(): array
    {
        return ['planned' => $this->plannedCount, 'done' => $this->doneCount, 'failed' => $this->failedCount, 'skipped' => $this->skippedCount, 'remaining' => $this->remainingCount];
    }

    public function setCounts(int $planned, int $done, int $failed, int $skipped, int $remaining): self
    {
        $this->plannedCount = $planned;
        $this->doneCount = $done;
        $this->failedCount = $failed;
        $this->skippedCount = $skipped;
        $this->remainingCount = $remaining;

        return $this;
    }
}
