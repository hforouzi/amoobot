<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\PlanAdditionalInbound;
use App\Entity\VpnInbound;
use App\Entity\VpnServiceInbound;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Migrations Version20261101090000 (plans with several inbounds, M1) and Version20261102090000 (M2b: retry schedule,
 * panel check and alert state of vpn_service_inbound). Both additive:
 * - M1 goes down and up again on a database without such rows, recreating both tables with their keys; with a plan's
 *   additional inbound or a service's inbound row, down() refuses and changes nothing; while M2b is applied it refuses
 *   too ("roll back M2b first"), so M2b is never left recorded without its columns;
 * - M2b goes down and up again with rows in place: they keep everything M1 gave them.
 */
#[Group('subprocess')]
final class MultiInboundMigrationTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private const M1 = 'DoctrineMigrations\\Version20261101090000';
    private const M2B = 'DoctrineMigrations\\Version20261102090000';
    private const M2B_COLUMNS = ['next_attempt_at', 'checked_at', 'alert_state', 'alerted_at'];

    public function testWithoutRowsItGoesDownAndUpAgainWithItsKeys(): void
    {
        try {
            $this->assertRuns(self::M2B, 'down');
            $down = $this->migrate(self::M1, 'down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertFalse($this->tableExists('plan_additional_inbound'));
            self::assertFalse($this->tableExists('vpn_service_inbound'));
            self::assertTrue($this->columnExists('plan', 'inbound_id'), 'the primary inbound is untouched');

            $up = $this->migrate(self::M1, 'up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
            $this->assertRuns(self::M2B, 'up');
            $this->assertSchemaIntact();
        } finally {
            $this->restore();
        }
    }

    public function testM1DownIsRefusedWhileM2bIsAppliedAndNothingChanges(): void
    {
        try {
            $down = $this->migrate(self::M1, 'down');

            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Roll back Version20261102090000 (retry schedule of additional inbounds) first. Nothing was changed.', $down->getOutput().$down->getErrorOutput());
            $this->assertSchemaIntact();
        } finally {
            $this->restore();
        }
    }

    public function testM2bGoesDownAndUpAgainAndTheRowsKeepWhatM1GaveThem(): void
    {
        try {
            [$primary, $other] = $this->twoInbounds();
            $service = $this->createActiveService($this->createUser(), $primary);
            $this->em->persist((new VpnServiceInbound($service, $other, new \DateTimeImmutable('2026-10-01 10:00:00')))->recordAttempt(false, 'not attached', new \DateTimeImmutable('2026-10-01 10:00:00')));
            $this->em->flush();

            $this->assertRuns(self::M2B, 'down');
            foreach (self::M2B_COLUMNS as $column) {
                self::assertFalse($this->columnExists('vpn_service_inbound', $column), $column);
            }
            self::assertFalse($this->indexExists('vpn_service_inbound', 'idx_vpn_service_inbound_due'));
            $this->assertRuns(self::M2B, 'up');

            $this->assertSchemaIntact();
            self::assertSame(['status' => 'failed', 'attempts' => 1, 'last_error' => 'not attached', 'next_attempt_at' => null, 'alert_state' => null],
                array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $this->em->getConnection()->fetchAssociative('SELECT status, attempts, last_error, next_attempt_at, alert_state FROM vpn_service_inbound') ?: []));
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileAPlanHasAnAdditionalInboundAndNothingChanges(): void
    {
        try {
            [$primary, $other] = $this->twoInbounds();
            $plan = $this->createPlan(100000, $primary);
            $this->em->persist(new PlanAdditionalInbound($plan, $other, 1, new \DateTimeImmutable()));
            $this->em->flush();
            $this->assertRuns(self::M2B, 'down');

            $down = $this->migrate(self::M1, 'down');

            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Irreversible: 1 additional plan inbound(s) exist', $down->getOutput().$down->getErrorOutput());
            self::assertConsoleContains('Nothing was changed', $down->getOutput().$down->getErrorOutput());
            self::assertTrue($this->tableExists('plan_additional_inbound'));
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound'));
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileAServiceHasAnInboundRowAndNothingChanges(): void
    {
        try {
            [$primary, $other] = $this->twoInbounds();
            $service = $this->createActiveService($this->createUser(), $primary);
            $this->em->persist(new VpnServiceInbound($service, $other, new \DateTimeImmutable()));
            $this->em->flush();
            $this->assertRuns(self::M2B, 'down');

            $down = $this->migrate(self::M1, 'down');

            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Irreversible: 1 service inbound attachment(s) exist', $down->getOutput().$down->getErrorOutput());
            self::assertTrue($this->tableExists('vpn_service_inbound'));
        } finally {
            $this->restore();
        }
    }

    /**
     * @return array{VpnInbound, VpnInbound}
     */
    private function twoInbounds(): array
    {
        $primary = $this->createTestInbound();
        $other = (new VpnInbound())->setPanel($primary->getPanel())->setRemoteInboundId('2')->setTitle('second');
        $this->em->persist($other);
        $this->em->flush();

        return [$primary, $other];
    }

    private function assertSchemaIntact(): void
    {
        self::assertTrue($this->tableExists('plan_additional_inbound'));
        self::assertTrue($this->tableExists('vpn_service_inbound'));
        self::assertTrue($this->indexExists('plan_additional_inbound', 'uniq_plan_additional_inbound'));
        self::assertTrue($this->indexExists('vpn_service_inbound', 'uniq_vpn_service_inbound'));
        self::assertTrue($this->indexExists('vpn_service_inbound', 'idx_vpn_service_inbound_due'));
        foreach (self::M2B_COLUMNS as $column) {
            self::assertTrue($this->columnExists('vpn_service_inbound', $column), $column);
        }
        self::assertSame([
            ['plan_additional_inbound', 'plan', 'CASCADE'],
            ['plan_additional_inbound', 'vpn_inbound', 'RESTRICT'],
            ['vpn_service_inbound', 'vpn_inbound', 'RESTRICT'],
            ['vpn_service_inbound', 'vpn_service', 'CASCADE'],
        ], array_map('array_values', $this->em->getConnection()->fetchAllAssociative(
            "SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('plan_additional_inbound', 'vpn_service_inbound') ORDER BY TABLE_NAME, REFERENCED_TABLE_NAME",
        )));
    }

    private function assertRuns(string $version, string $direction): void
    {
        $process = $this->migrate($version, $direction);
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /** Runs the migration in its own console process (Doctrine Migrations freezes its configuration after one run). */
    private function migrate(string $version, string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', $version, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
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

    private function indexExists(string $table, string $index): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index]);
    }

    /** Whatever happened above, leave the schema and the migration table as the rest of the suite expects (M1 and M2b up). */
    private function restore(): void
    {
        $connection = $this->em->getConnection();
        if ($this->tableExists('plan_additional_inbound')) {
            $connection->executeStatement('DELETE FROM plan_additional_inbound');
        }
        if ($this->tableExists('vpn_service_inbound')) {
            $connection->executeStatement('DELETE FROM vpn_service_inbound');
        }
        if (!$this->tableExists('plan_additional_inbound')) {
            $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::M1]);
            $this->assertRuns(self::M1, 'up');
        }
        if (!$this->columnExists('vpn_service_inbound', 'next_attempt_at')) {
            $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::M2B]);
            $this->assertRuns(self::M2B, 'up');
        }
        foreach ([self::M1, self::M2B] as $version) {
            if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [$version])) {
                $connection->insert('doctrine_migration_versions', ['version' => $version, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
            }
        }
    }
}
