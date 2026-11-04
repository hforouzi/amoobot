<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\InboundMoveRun;
use App\Entity\ServiceInboundMove;
use App\Entity\VpnInbound;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * T13 (docs/design/plan-inbound-change.md 8.2): migration Version20261104090000 (P3) goes down and up again on a
 * database without moves, recreating both tables with their keys, the CHECK and the column; down() refuses and
 * changes nothing while a run, a move or a service's inbound_changed_at exists.
 */
#[Group('subprocess')]
final class InboundMoveMigrationTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private const P3 = 'DoctrineMigrations\\Version20261104090000';

    public function testWithoutDataItGoesDownAndUpAgainWithItsKeys(): void
    {
        try {
            $this->createActiveService($this->createUser(), $this->createTestInbound());

            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertFalse($this->tableExists('inbound_move_run'));
            self::assertFalse($this->tableExists('service_inbound_move'));
            self::assertFalse($this->columnExists('vpn_service', 'inbound_changed_at'));
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service'), 'existing rows untouched');

            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
            $this->assertSchemaIntact();
            self::assertNull($this->em->getConnection()->fetchOne('SELECT inbound_changed_at FROM vpn_service'), 'the column starts NULL for existing services');
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileARunExistsAndNothingChanges(): void
    {
        try {
            $this->em->persist(new InboundMoveRun($this->createPlan(), InboundMoveRun::KIND_PRIMARY, null, [], 24, false, null, new \DateTimeImmutable()));
            $this->em->flush();

            $this->assertRefused('Irreversible: 1 inbound move run(s) exist');
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileAMoveExistsAndNothingChanges(): void
    {
        try {
            $inbound = $this->createTestInbound();
            $run = (new InboundMoveRun($this->createPlan(100000, $inbound), InboundMoveRun::KIND_PRIMARY, $inbound, [], 24, false, null, new \DateTimeImmutable()))->setStatus(InboundMoveRun::STATUS_DONE);
            $this->em->persist($run);
            $this->em->persist(new ServiceInboundMove($run, $this->createActiveService($this->createUser(), $inbound), $inbound, $inbound, new \DateTimeImmutable()));
            $this->em->flush();
            // Only the move row left: its own refusal is reached.
            $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
            $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

            $this->assertRefused('Irreversible: 1 service inbound move(s) exist');
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileAServiceHasInboundChangedAtAndNothingChanges(): void
    {
        try {
            $service = $this->createActiveService($this->createUser(), $this->createTestInbound());
            $this->em->getConnection()->executeStatement("UPDATE vpn_service SET inbound_changed_at = '2026-11-04 10:00:00' WHERE id = ?", [$service->getId()]);

            $this->assertRefused('Irreversible: 1 service(s) have vpn_service.inbound_changed_at set');
            self::assertSame('2026-11-04 10:00:00', $this->em->getConnection()->fetchOne('SELECT inbound_changed_at FROM vpn_service'));
        } finally {
            $this->restore();
        }
    }

    private function assertRefused(string $message): void
    {
        $down = $this->migrate('down');

        self::assertNotSame(0, $down->getExitCode());
        self::assertConsoleContains($message, $down->getOutput().$down->getErrorOutput());
        self::assertConsoleContains('Nothing was changed', $down->getOutput().$down->getErrorOutput());
        $this->assertSchemaIntact();
    }

    private function assertSchemaIntact(): void
    {
        self::assertTrue($this->tableExists('inbound_move_run'));
        self::assertTrue($this->tableExists('service_inbound_move'));
        self::assertTrue($this->columnExists('vpn_service', 'inbound_changed_at'));
        foreach (['uniq_inbound_move_run_open_plan' => 'inbound_move_run', 'uniq_service_inbound_move_run_service' => 'service_inbound_move', 'idx_service_inbound_move_due' => 'service_inbound_move', 'idx_service_inbound_move_detach' => 'service_inbound_move'] as $index => $table) {
            self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index]), $index);
        }
        self::assertNotFalse($this->em->getConnection()->fetchOne("SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inbound_move_run' AND CONSTRAINT_NAME = 'chk_inbound_move_run_open_plan'"));
        self::assertSame([
            ['inbound_move_run', 'plan', 'CASCADE'],
            ['inbound_move_run', 'vpn_inbound', 'SET NULL'],
            ['service_inbound_move', 'inbound_move_run', 'CASCADE'],
            ['service_inbound_move', 'vpn_inbound', 'SET NULL'],
            ['service_inbound_move', 'vpn_inbound', 'SET NULL'],
            ['service_inbound_move', 'vpn_service', 'CASCADE'],
        ], array_map('array_values', $this->em->getConnection()->fetchAllAssociative(
            "SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('inbound_move_run', 'service_inbound_move') ORDER BY TABLE_NAME, REFERENCED_TABLE_NAME",
        )));
    }

    /** Runs the migration in its own console process (Doctrine Migrations freezes its configuration after one run). */
    private function migrate(string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', self::P3, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        return $process;
    }

    private function tableExists(string $table): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    private function columnExists(string $table, string $column): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    }

    /** Whatever happened above, leave the schema and the migration table as the rest of the suite expects (P3 up). */
    private function restore(): void
    {
        $connection = $this->em->getConnection();
        if ($this->tableExists('service_inbound_move')) {
            $connection->executeStatement('DELETE FROM service_inbound_move');
        }
        if ($this->tableExists('inbound_move_run')) {
            $connection->executeStatement('DELETE FROM inbound_move_run');
        }
        if ($this->columnExists('vpn_service', 'inbound_changed_at')) {
            $connection->executeStatement('UPDATE vpn_service SET inbound_changed_at = NULL');
        }
        if (!$this->tableExists('inbound_move_run')) {
            $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::P3]);
            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
        }
        if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::P3])) {
            $connection->insert('doctrine_migration_versions', ['version' => self::P3, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
        }
    }
}
