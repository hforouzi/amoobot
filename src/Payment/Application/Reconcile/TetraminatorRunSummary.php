<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

/**
 * What one Tetraminator timer run did (TetraminatorReconciler::runDue()). Counts only: no pay_id, token, URL or key.
 */
final class TetraminatorRunSummary
{
    /** Why the run stopped before the end of its due list. */
    public const STOP_LIMIT = 'limit';
    public const STOP_TIME_BUDGET = 'time_budget';
    public const STOP_ERRORS = 'consecutive_errors';
    public const STOP_ACCOUNT_REFUSED = 'account_refused';

    public int $candidates = 0;
    public int $due = 0;
    public int $asked = 0;
    public int $settled = 0;
    public int $review = 0;
    public int $notPaid = 0;
    public int $expired = 0;
    /** Expired at 24 h without any well-formed answer. */
    public int $expiredUnanswered = 0;
    public int $verifyFailed = 0;
    public int $errors = 0;
    /** Due payments left for the next run (limit, time budget, a stop). */
    public int $leftOver = 0;
    public ?string $stoppedBy = null;
    public bool $accountRefused = false;
    /** A gateway's driver could not be resolved while it has open payments. */
    public bool $driverUnavailable = false;
    /** Open payments still without a well-formed answer an hour past their expiry. */
    public int $overdueUnanswered = 0;
    public bool $dryRun = false;

    /**
     * The run needs a person: exit 1 (design.md decision 4, "Exit code"). Transport or 5xx errors alone do not: they
     * are warnings, and the next tick asks again.
     */
    public function needsAttention(): bool
    {
        return $this->accountRefused || $this->driverUnavailable || $this->overdueUnanswered > 0;
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
