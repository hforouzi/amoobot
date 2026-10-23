<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\Reseller;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Migration Version20261027090000 (resellers, S1): additive, so it goes down and up again on a database without reseller
 * data; with any (a profile, an attributed order or service, a plan floor price) down() refuses and changes nothing.
 */
#[Group('subprocess')]
final class ResellerMigrationTest extends IntegrationTestCase
{
    private const VERSION = 'DoctrineMigrations\\Version20261027090000';

    public function testDownIsRefusedWhileResellerDataExistsAndNothingChanges(): void
    {
        $plan = $this->createPlan()->setResellerFloorAmount(50000);
        $this->em->flush();
        $refusals = [
            'reseller profile' => fn () => $this->em->persist(new Reseller($this->createUser())),
            'plan floor price' => fn () => null,
        ];

        try {
            foreach ($refusals as $what => $create) {
                $create();
                $this->em->flush();
                $down = $this->migrate('down');
                self::assertStringContainsString('Irreversible', $down->getOutput().$down->getErrorOutput(), $what);
                self::assertTrue($this->tableExists('reseller'), $what.': nothing was dropped');
                self::assertTrue($this->columnExists('order', 'reseller_id'), $what);
                self::assertTrue($this->columnExists('plan', 'reseller_floor_amount'), $what);
                $this->em->getConnection()->executeStatement('DELETE FROM reseller');
            }
            self::assertNotNull($plan->getId());
        } finally {
            $this->restore();
        }
    }

    public function testWithoutResellerDataItGoesDownAndUpAgain(): void
    {
        try {
            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertFalse($this->tableExists('reseller'));
            self::assertFalse($this->columnExists('order', 'reseller_id'));
            self::assertFalse($this->columnExists('vpn_service', 'sold_by_reseller_id'));

            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
            self::assertTrue($this->tableExists('reseller_plan_price'));
            self::assertTrue($this->columnExists('order', 'wholesale_amount'));
            self::assertTrue($this->columnExists('vpn_service', 'sold_by_reseller_id'));
        } finally {
            $this->restore();
        }
    }

    /** Runs the migration in its own console process (Doctrine Migrations freezes its configuration after one run). */
    private function migrate(string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', self::VERSION, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
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

    /** Whatever happened above, leave the schema and the migration table as the rest of the suite expects. */
    private function restore(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM reseller');
        $connection->executeStatement('UPDATE plan SET reseller_floor_amount = NULL');
        if (!$this->tableExists('reseller')) {
            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
        }
        if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION])) {
            $connection->insert('doctrine_migration_versions', ['version' => self::VERSION, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
        }
    }
}
