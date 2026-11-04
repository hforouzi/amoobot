<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\InboundMoveRun;
use App\Entity\Plan;
use App\Entity\ServiceInboundMove;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The P3 schema on MariaDB (docs/design/plan-inbound-change.md 4.3, 5.1, 5.5): the mapping round-trips; one open run
 * per plan is enforced by the database (unique open_plan_id kept in step with the status by a CHECK, for ORM and SQL
 * writers alike); one move per service per run; a run goes with its plan and its moves with it, a move with its
 * service; deleting an inbound never fails because of a run or a move (SET NULL, the panel's ids are kept).
 */
final class InboundMoveSchemaTest extends IntegrationTestCase
{
    private const NOW = '2026-11-04 10:00:00';

    public function testARunAndAMoveRoundTripAndTheNewServiceColumnStartsNull(): void
    {
        [$a, $b] = $this->inbounds(2);
        $plan = $this->createPlan(100000, $b);
        $service = $this->createActiveService($this->createUser(), $a);
        $run = $this->newRun($plan, $b, ['include_suspended' => true, 'service' => $service->getId()]);
        $move = (new ServiceInboundMove($run, $service, $a, $b, new \DateTimeImmutable(self::NOW)))
            ->setLastError('failed for tg_123456789_order_42');
        $this->em->persist($move);
        $this->em->flush();
        $this->em->clear();

        $run = $this->em->find(InboundMoveRun::class, $run->getId());
        self::assertInstanceOf(InboundMoveRun::class, $run);
        self::assertSame(InboundMoveRun::STATUS_PLANNED, $run->getStatus());
        self::assertSame($plan->getId(), $run->getOpenPlanId());
        self::assertSame(InboundMoveRun::KIND_PRIMARY, $run->getKind());
        self::assertSame($b->getId(), $run->getTargetInbound()?->getId());
        self::assertSame(['include_suspended' => true, 'service' => $service->getId()], $run->getScope());
        self::assertSame(24, $run->getGraceHours());
        self::assertFalse($run->isKeepOld());
        self::assertSame(7, $run->getRequestedBy());
        self::assertSame(['planned' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0], $run->getCounts());

        $move = $this->em->find(ServiceInboundMove::class, $move->getId());
        self::assertInstanceOf(ServiceInboundMove::class, $move);
        self::assertSame(ServiceInboundMove::STATE_PLANNED, $move->getState());
        self::assertSame([$a->getId(), '1', $b->getId(), '2'], [$move->getFromInbound()?->getId(), $move->getFromRemoteInboundId(), $move->getToInbound()?->getId(), $move->getToRemoteInboundId()]);
        self::assertSame(0, $move->getAttempts());
        self::assertNull($move->getDetachAfter());
        self::assertStringNotContainsString('123456789', (string) $move->getLastError(), 'masked as vpn_service_inbound.last_error');

        $service = $this->em->find(VpnService::class, $service->getId());
        self::assertNull($service?->getInboundChangedAt());
        self::assertNull($this->row('vpn_service', (int) $service?->getId())['inbound_changed_at']);
    }

    public function testASecondOpenRunForAPlanIsRefusedByTheDatabase(): void
    {
        [$b] = $this->inbounds(1);
        $plan = $this->createPlan(100000, $b);
        $this->newRun($plan, $b);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->newRun($plan, $b, [], InboundMoveRun::STATUS_PAUSED);
        $this->em->flush();
    }

    public function testEveryOpenStatusHoldsThePlanAndAClosedRunFreesIt(): void
    {
        [$b] = $this->inbounds(1);
        $plan = $this->createPlan(100000, $b);
        $other = $this->createPlan(100000, $b);

        foreach (InboundMoveRun::OPEN_STATUSES as $status) {
            $run = $this->newRun($plan, $b, [], $status);
            $this->em->flush();
            self::assertSame($plan->getId(), $run->getOpenPlanId(), $status);
            $this->newRun($other, $b);
            try {
                $this->em->getConnection()->executeStatement("INSERT INTO inbound_move_run (plan_id, open_plan_id, kind, scope, status, grace_hours, keep_old, created_at, planned_count, done_count, failed_count, skipped_count, remaining_count) VALUES (?, ?, 'primary', '{}', 'planned', 24, 0, ?, 0, 0, 0, 0, 0)", [$plan->getId(), $plan->getId(), self::NOW]);
                self::fail('a second open run next to a '.$status.' one');
            } catch (UniqueConstraintViolationException) {
            }
            $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
            $this->em->clear();
            $plan = $this->plan($plan);
            $other = $this->plan($other);
            $b = $this->inbound($b);
        }

        $done = $this->newRun($plan, $b, [], InboundMoveRun::STATUS_DONE);
        $cancelled = $this->newRun($plan, $b, [], InboundMoveRun::STATUS_CANCELLED);
        $this->em->flush();
        $open = $this->newRun($plan, $b);
        self::assertNull($done->getOpenPlanId());
        self::assertNull($cancelled->getOpenPlanId());
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbound_move_run WHERE plan_id = ?', [$plan->getId()]));
        self::assertSame($plan->getId(), $open->getOpenPlanId());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inconsistentRows(): iterable
    {
        yield 'open without the plan id' => ['running', 'NULL'];
        yield 'open with another plan id' => ['planned', 'plan_id + 1'];
        yield 'closed still holding the plan' => ['done', 'plan_id'];
        yield 'cancelled still holding the plan' => ['cancelled', 'plan_id'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inconsistentRows')]
    public function testTheCheckRefusesAnOpenPlanIdOutOfStepWithTheStatus(string $status, string $openPlanId): void
    {
        [$b] = $this->inbounds(1);
        $plan = $this->createPlan(100000, $b);
        $run = $this->newRun($plan, $b);

        try {
            $this->em->getConnection()->executeStatement(sprintf('UPDATE inbound_move_run SET status = ?, open_plan_id = %s WHERE id = ?', $openPlanId), [$status, $run->getId()]);
            self::fail('the CHECK let an inconsistent row through');
        } catch (DbalException $e) {
            self::assertStringContainsString('chk_inbound_move_run_open_plan', $e->getMessage());
        }
        self::assertSame(['status' => 'planned', 'open_plan_id' => $plan->getId()], array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $this->em->getConnection()->fetchAssociative('SELECT status, open_plan_id FROM inbound_move_run WHERE id = ?', [$run->getId()]) ?: []));
    }

    public function testASqlWriterThatClosesARunBySettingBothFreesThePlan(): void
    {
        [$b] = $this->inbounds(1);
        $plan = $this->createPlan(100000, $b);
        $run = $this->newRun($plan, $b);

        $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'done', open_plan_id = NULL WHERE id = ?", [$run->getId()]);
        $this->em->clear();

        self::assertNotNull($this->newRun($this->plan($plan), $this->inbound($b))->getId());
    }

    public function testOneMovePerServicePerRun(): void
    {
        [$a, $b] = $this->inbounds(2);
        $plan = $this->createPlan(100000, $b);
        $service = $this->createActiveService($this->createUser(), $a);
        $first = $this->newRun($plan, $b);
        $this->move($first, $service, $a, $b);
        $second = $this->newRun($plan, $b, [], InboundMoveRun::STATUS_CANCELLED);
        $first->setStatus(InboundMoveRun::STATUS_DONE);
        $this->em->flush();
        $this->move($second, $service, $a, $b);
        self::assertSame(2, $this->rows('service_inbound_move'), 'the same service in another run');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->move($first, $service, $a, $b);
    }

    public function testDeletingThePlanRemovesItsRunsAndTheirMovesOnly(): void
    {
        [$a, $b] = $this->inbounds(2);
        $plan = $this->createPlan(100000, $b);
        $kept = $this->createPlan(100000, $b);
        $service = $this->createActiveService($this->createUser(), $a);
        $this->move($this->newRun($plan, $b), $service, $a, $b);
        $this->move($this->newRun($plan, $b, [], InboundMoveRun::STATUS_DONE), $this->createActiveService($this->createUser(), $a), $a, $b);
        $this->move($this->newRun($kept, $b), $service, $a, $b);

        $this->em->getConnection()->executeStatement('DELETE FROM plan WHERE id = ?', [$plan->getId()]);

        self::assertSame(1, $this->rows('inbound_move_run'));
        self::assertSame(1, $this->rows('service_inbound_move'));
        self::assertSame($kept->getId(), (int) $this->em->getConnection()->fetchOne('SELECT plan_id FROM inbound_move_run'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service'), 'services are never touched');
    }

    public function testDeletingAServiceRemovesItsMovesAndKeepsTheRun(): void
    {
        [$a, $b] = $this->inbounds(2);
        $plan = $this->createPlan(100000, $b);
        $run = $this->newRun($plan, $b);
        $gone = $this->createActiveService($this->createUser(), $a);
        $this->move($run, $gone, $a, $b);
        $this->move($run, $this->createActiveService($this->createUser(), $a), $a, $b);

        $this->em->getConnection()->executeStatement('DELETE FROM vpn_service WHERE id = ?', [$gone->getId()]);

        self::assertSame(1, $this->rows('service_inbound_move'));
        self::assertSame(1, $this->rows('inbound_move_run'));
    }

    public function testDeletingAnInboundIsNeverBlockedAndThePanelIdsAreKept(): void
    {
        [$a, $b, $from, $to] = $this->inbounds(4);
        $plan = $this->createPlan(100000, $b);
        $run = $this->newRun($plan, $to);
        $move = $this->move($run, $this->createActiveService($this->createUser(), $a), $from, $to);

        $this->em->getConnection()->executeStatement('DELETE FROM vpn_inbound WHERE id IN (?, ?)', [$from->getId(), $to->getId()]);

        self::assertNull($this->row('inbound_move_run', (int) $run->getId())['target_inbound_id']);
        $row = $this->row('service_inbound_move', (int) $move->getId());
        self::assertSame([null, '3', null, '4'], [$row['from_inbound_id'], $row['from_remote_inbound_id'], $row['to_inbound_id'], $row['to_remote_inbound_id']]);
    }

    public function testTheKeysAndIndexesAreAsDesigned(): void
    {
        $connection = $this->em->getConnection();

        self::assertSame([
            ['inbound_move_run', 'plan', 'CASCADE'],
            ['inbound_move_run', 'vpn_inbound', 'SET NULL'],
            ['service_inbound_move', 'inbound_move_run', 'CASCADE'],
            ['service_inbound_move', 'vpn_inbound', 'SET NULL'],
            ['service_inbound_move', 'vpn_inbound', 'SET NULL'],
            ['service_inbound_move', 'vpn_service', 'CASCADE'],
        ], array_map('array_values', $connection->fetchAllAssociative(
            "SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('inbound_move_run', 'service_inbound_move') ORDER BY TABLE_NAME, REFERENCED_TABLE_NAME",
        )));
        self::assertSame([
            ['idx_service_inbound_move_detach', 1, 'state'], ['idx_service_inbound_move_detach', 2, 'detach_after'],
            ['idx_service_inbound_move_due', 1, 'state'], ['idx_service_inbound_move_due', 2, 'next_attempt_at'],
            ['uniq_service_inbound_move_run_service', 1, 'run_id'], ['uniq_service_inbound_move_run_service', 2, 'service_id'],
        ], array_map(static fn (array $r): array => [$r['INDEX_NAME'], (int) $r['SEQ_IN_INDEX'], $r['COLUMN_NAME']], $connection->fetchAllAssociative(
            "SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_inbound_move' AND (INDEX_NAME LIKE BINARY 'idx_%' OR INDEX_NAME LIKE BINARY 'uniq_%') ORDER BY INDEX_NAME, SEQ_IN_INDEX",
        )));
        self::assertSame('0', (string) $connection->fetchOne("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inbound_move_run' AND INDEX_NAME = 'uniq_inbound_move_run_open_plan'"));
        self::assertSame('YES', $connection->fetchOne("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vpn_service' AND COLUMN_NAME = 'inbound_changed_at'"));
        self::assertFalse($connection->fetchOne("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vpn_service' AND COLUMN_NAME = 'inbound_changed_at' AND COLUMN_DEFAULT IS NOT NULL AND COLUMN_DEFAULT <> 'NULL'"), 'no default but NULL');
    }

    public function testTheEntitiesRefuseUnknownValues(): void
    {
        [$b] = $this->inbounds(1);
        $plan = $this->createPlan(100000, $b);

        try {
            new InboundMoveRun($plan, 'swap', $b, [], 24, false, null, new \DateTimeImmutable());
            self::fail('unknown kind');
        } catch (\InvalidArgumentException) {
        }
        try {
            new InboundMoveRun(new Plan(), InboundMoveRun::KIND_PRIMARY, $b, [], 24, false, null, new \DateTimeImmutable());
            self::fail('unsaved plan');
        } catch (\InvalidArgumentException) {
        }
        $run = $this->newRun($plan, $b);
        try {
            $run->setStatus('stopped');
            self::fail('unknown status');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        (new ServiceInboundMove($run, $this->createActiveService($this->createUser(), $b), $b, $b, new \DateTimeImmutable()))->setState('moved', new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function newRun(Plan $plan, ?VpnInbound $target, array $scope = [], string $status = InboundMoveRun::STATUS_PLANNED): InboundMoveRun
    {
        $run = (new InboundMoveRun($plan, InboundMoveRun::KIND_PRIMARY, $target, $scope, 24, false, 7, new \DateTimeImmutable(self::NOW)))->setStatus($status);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function move(InboundMoveRun $run, VpnService $service, VpnInbound $from, VpnInbound $to): ServiceInboundMove
    {
        $move = new ServiceInboundMove($run, $service, $from, $to, new \DateTimeImmutable(self::NOW));
        $this->em->persist($move);
        $this->em->flush();

        return $move;
    }

    /**
     * Inbounds on one panel, remote ids 1, 2, ...
     *
     * @return list<VpnInbound>
     */
    private function inbounds(int $count): array
    {
        $first = $this->createTestInbound();
        $inbounds = [$first];
        for ($i = 2; $i <= $count; ++$i) {
            $inbound = (new VpnInbound())->setPanel($first->getPanel())->setRemoteInboundId((string) $i)->setTitle('inbound '.$i);
            $this->em->persist($inbound);
            $inbounds[] = $inbound;
        }
        $this->em->flush();

        return $inbounds;
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function plan(Plan $plan): Plan
    {
        $found = $this->em->find(Plan::class, $plan->getId());
        self::assertInstanceOf(Plan::class, $found);

        return $found;
    }

    private function inbound(VpnInbound $inbound): VpnInbound
    {
        $found = $this->em->find(VpnInbound::class, $inbound->getId());
        self::assertInstanceOf(VpnInbound::class, $found);

        return $found;
    }
}
