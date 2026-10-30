<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Entity\ResellerCustomer;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Migration Version20261031090000 (reseller Mini App, S3): additive, so it goes down and up again while no customer
 * label exists. With a label (or an assignment) down() refuses and changes nothing.
 */
#[Group('subprocess')]
final class ResellerMiniAppMigrationTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;
    use ResellerFixtures;

    private const VERSION = 'DoctrineMigrations\\Version20261031090000';

    public function testDownIsRefusedWhileLabelsExist(): void
    {
        $this->setUpReseller();
        $this->em->getConnection()->executeStatement('DELETE FROM reseller_customer_service');
        $this->em->getConnection()->executeStatement('DELETE FROM reseller_customer');
        try {
            $label = new ResellerCustomer($this->reseller, 'Ali', null, null, new \DateTimeImmutable());
            $this->em->persist($label);
            $this->em->flush();

            $down = $this->migrate('down');
            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Irreversible', $down->getOutput().$down->getErrorOutput());
            self::assertTrue($this->tableExists('reseller_customer'));
            self::assertTrue($this->tableExists('reseller_customer_service'));
            self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
        } finally {
            $this->restore();
        }
    }

    public function testWithoutLabelsItGoesDownAndUpAgain(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM reseller_customer_service');
        $this->em->getConnection()->executeStatement('DELETE FROM reseller_customer');
        try {
            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertFalse($this->tableExists('reseller_customer'));
            self::assertFalse($this->tableExists('reseller_customer_service'));

            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
            self::assertTrue($this->tableExists('reseller_customer'));
            self::assertTrue($this->tableExists('reseller_customer_service'));
        } finally {
            $this->restore();
        }
    }

    private function migrate(string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', self::VERSION, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        return $process;
    }

    private function tableExists(string $table): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Leave the schema and the migration table as the rest of the suite expects. */
    private function restore(): void
    {
        $connection = $this->em->getConnection();
        if ($this->tableExists('reseller_customer')) {
            $connection->executeStatement('DELETE FROM reseller_customer_service');
            $connection->executeStatement('DELETE FROM reseller_customer');
            $this->em->clear();
        }
        if (!$this->tableExists('reseller_customer')) {
            $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::VERSION]);
            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
        }
        if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION])) {
            $connection->insert('doctrine_migration_versions', ['version' => self::VERSION, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
        }
    }
}
