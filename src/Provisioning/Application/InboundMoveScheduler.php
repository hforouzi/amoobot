<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Shared\Infrastructure\TransactionRollback;
use App\Shop\Application\InboundMoveSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The scheduled task inbound-moves (docs/design/plan-inbound-change.md 5.2, PR P8; app:automation:run
 * --only=inbound-moves): one pass over the open runs, in order of id. All the work is InboundMoveRunner::process(), the
 * same code as --resume, so a service gets the same steps, limits, retries, alerts and automatic pause. What is due is
 * the runner's own rule: phase 1 leftovers, retries past their next_attempt_at, retirements past their detach_after,
 * links still to refresh.
 *
 * - Nothing at all while plans.inbound_move_enabled is off: no lock, no panel call, no write, success.
 * - Only runs that are planned or running. A paused run waits for an admin's --resume (the runner is told so, which also
 *   covers a pause that arrives between this query and the runner's first write), and a run that is not open has no
 *   business here, whatever its moves look like.
 * - InboundMoveLock: a pass of the foreground command holds it: this one is skipped with one log line, and is not a
 *   failure.
 * - TIME_BUDGET_SECONDS: no new service is started after it. The rest stays due for the next pass. A panel that does not
 *   answer pauses its run (an alert, not a failure of the job), so only a run that throws makes the job fail.
 *
 * It never reads or writes an order, payment, wallet, user or reseller row itself, and it reports counts only.
 */
final class InboundMoveScheduler
{
    /**
     * The job's timeout in deploy.sh is 600 s (tests/ops/scheduled-jobs.test.sh ties the two together). No service starts
     * after this; the one in hand can still take two steps of PanelCallBudget with their refreshes (120 s), and the pause
     * between batches is cut to what is left, so a pass ends within the timeout.
     */
    public const TIME_BUDGET_SECONDS = 420;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMoveRunner $runner,
        private readonly InboundMoveLock $lock,
        private readonly InboundMovePlanner $planner,
        private readonly InboundMoveSettings $settings,
        private readonly TransactionRollback $transactionRollback,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(bool $dryRun = false, ?int $budgetSeconds = null): InboundMoveTaskRun
    {
        if (!$this->settings->enabled()) {
            return new InboundMoveTaskRun(InboundMoveTaskRun::SWITCHED_OFF);
        }
        if ($dryRun) {
            $task = new InboundMoveTaskRun();
            foreach ($this->openRunIds() as $runId) {
                ++$task->runs;
                $task->due += $this->runner->dueCount($runId);
            }

            return $task;
        }

        $held = $this->lock->acquire();
        if (null === $held) {
            $this->logger->info('inbound_move_task_skipped', ['reason' => 'another pass holds the lock']);

            return new InboundMoveTaskRun(InboundMoveTaskRun::LOCKED);
        }
        try {
            return $this->pass(microtime(true) + ($budgetSeconds ?? self::TIME_BUDGET_SECONDS));
        } finally {
            $held->release();
        }
    }

    private function pass(float $stopAt): InboundMoveTaskRun
    {
        $task = new InboundMoveTaskRun();
        foreach ($this->openRunIds() as $runId) {
            if (microtime(true) >= $stopAt) {
                $task->outOfTime = true;
                break;
            }
            try {
                $pass = $this->runner->process($runId, leavePaused: true, stopAt: $stopAt);
            } catch (\Throwable $e) {
                ++$task->errors;
                $this->transactionRollback->rollBack();
                $this->transactionRollback->resetEntityManager();
                $this->logger->error('inbound_move_task_run_failed', ['run_id' => $runId, 'class' => $e::class, 'error' => SafeText::of($e->getMessage(), '', 255, $this->planner->panelSecrets())]);
                continue;
            }
            if (InboundMovePass::RAN !== $pass->outcome) {
                continue;
            }
            ++$task->runs;
            $task->moved += $pass->switched;
            $task->retired += $pass->retired;
            $task->failed += $pass->failed;
            $task->skipped += $pass->skipped;
            $task->alertsSent += $pass->alertsSent;
            $task->paused += $pass->paused ? 1 : 0;
            if ($pass->outOfTime) {
                $task->outOfTime = true;
                break;
            }
        }

        return $task;
    }

    /** @return list<int> */
    private function openRunIds(): array
    {
        return array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM inbound_move_run WHERE status IN (?, ?) AND kind = ? AND open_plan_id IS NOT NULL ORDER BY id',
            [InboundMoveRun::STATUS_PLANNED, InboundMoveRun::STATUS_RUNNING, InboundMoveRun::KIND_PRIMARY],
        ));
    }
}
