<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\Reseller;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Migrations Version20261027090000 (resellers, S1) and Version20261029090000 (order.reseller_nonce): additive, so they
 * go down (newest first, after the claim links of S2, Version20261030090000) and up again on a database without
 * reseller data. With any (a profile, a plan floor price, an order with a confirmation nonce) down() refuses and changes
 * nothing; S1's down() also refuses while the nonce or the claim-links migration is still applied (it would drop half
 * of that unique key / fail on their foreign key).
 */
#[Group('subprocess')]
final class ResellerMigrationTest extends IntegrationTestCase
{
    private const S1 = 'DoctrineMigrations\\Version20261027090000';
    private const NONCE = 'DoctrineMigrations\\Version20261029090000';
    private const CLAIMS = 'DoctrineMigrations\\Version20261030090000';

    public function testDownIsRefusedWhileResellerDataExistsAndNothingChanges(): void
    {
        try {
            $refused = $this->migrate(self::S1, 'down');
            self::assertStringContainsString('Roll back Version20261029090000', $refused->getOutput().$refused->getErrorOutput(), 'not before the newer migration');
            $this->assertSchemaIntact();

            $reseller = new Reseller($this->createUser());
            $this->em->persist($reseller);
            $this->em->flush();
            $this->em->getConnection()->executeStatement('INSERT INTO `order` (user_id, plan_id, amount, type, status, created_at, reseller_id, reseller_nonce) VALUES (?, ?, 1000, ?, ?, NOW(), ?, ?)', [
                $reseller->getUser()->getId(), $this->createPlan()->getId(), 'new_service', 'cancelled', $reseller->getId(), 'nonce-of-a-tap',
            ]);
            $down = $this->migrate(self::NONCE, 'down');
            self::assertStringContainsString('Irreversible', $down->getOutput().$down->getErrorOutput(), 'an order carries a nonce');
            $this->assertSchemaIntact();

            $this->em->getConnection()->executeStatement('UPDATE `order` SET reseller_nonce = NULL');
            self::assertSame(0, $this->migrate(self::NONCE, 'down')->getExitCode());
            $down = $this->migrate(self::S1, 'down');
            self::assertStringContainsString('Irreversible', $down->getOutput().$down->getErrorOutput(), 'a reseller profile exists');
            self::assertTrue($this->tableExists('reseller'));
            self::assertTrue($this->columnExists('order', 'reseller_id'));

            // Without reseller data, S1 still waits for the claim links to be rolled back first.
            $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $this->em->getConnection()->executeStatement('DELETE FROM `order`');
            $this->em->getConnection()->executeStatement('DELETE FROM reseller');
            $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $down = $this->migrate(self::S1, 'down');
            self::assertStringContainsString('Roll back Version20261030090000', $down->getOutput().$down->getErrorOutput(), 'not before the claim links');
            self::assertTrue($this->tableExists('reseller'));
            self::assertTrue($this->tableExists('service_claim'));
        } finally {
            $this->restore();
        }
    }

    public function testWithoutResellerDataBothGoDownAndUpAgain(): void
    {
        try {
            foreach ([self::CLAIMS, self::NONCE, self::S1] as $version) {
                $down = $this->migrate($version, 'down');
                self::assertSame(0, $down->getExitCode(), $version.': '.$down->getOutput().$down->getErrorOutput());
            }
            self::assertFalse($this->tableExists('reseller'));
            self::assertFalse($this->columnExists('order', 'reseller_id'));
            self::assertFalse($this->columnExists('order', 'reseller_nonce'));
            self::assertFalse($this->tableExists('service_claim'));

            foreach ([self::S1, self::NONCE, self::CLAIMS] as $version) {
                $up = $this->migrate($version, 'up');
                self::assertSame(0, $up->getExitCode(), $version.': '.$up->getOutput().$up->getErrorOutput());
            }
            $this->assertSchemaIntact();
        } finally {
            $this->restore();
        }
    }

    private function assertSchemaIntact(): void
    {
        self::assertTrue($this->tableExists('reseller_plan_price'));
        self::assertTrue($this->columnExists('order', 'wholesale_amount'));
        self::assertTrue($this->columnExists('order', 'reseller_nonce'));
        self::assertTrue($this->columnExists('vpn_service', 'sold_by_reseller_id'));
        self::assertTrue($this->indexExists('order', 'uniq_order_reseller_nonce'));
        self::assertTrue($this->tableExists('service_claim'));
        self::assertNotFalse($this->em->getConnection()->fetchOne("SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'FK_F529939891E6A19D'"), 'order -> reseller key');
    }

    /** Runs a migration in its own console process (Doctrine Migrations freezes its configuration after one run). */
    private function migrate(string $version, string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', $version, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
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

    /** Whatever happened above, leave the schema and the migration table as the rest of the suite expects. */
    private function restore(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->columnExists('order', 'reseller_id')) {
            $connection->executeStatement('DELETE FROM `order` WHERE reseller_id IS NOT NULL');
        }
        if ($this->tableExists('reseller')) {
            $connection->executeStatement('DELETE FROM reseller');
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach ([[self::S1, !$this->tableExists('reseller')], [self::NONCE, !$this->columnExists('order', 'reseller_nonce')], [self::CLAIMS, !$this->tableExists('service_claim')]] as [$version, $missing]) {
            if ($missing) {
                $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [$version]);
                $up = $this->migrate($version, 'up');
                self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
            }
            if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [$version])) {
                $connection->insert('doctrine_migration_versions', ['version' => $version, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
            }
        }
    }
}
