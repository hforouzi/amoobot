<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Migration Version20261030090000 (claim links, S2): additive, so it goes down and up again while no claim link exists.
 * With one (or an ownership event) down() refuses and changes nothing.
 */
#[Group('subprocess')]
final class ClaimMigrationTest extends IntegrationTestCase
{
    use ClaimFixtures;
    use ConsoleOutputAssertions;

    private const CLAIMS = 'DoctrineMigrations\\Version20261030090000';

    public function testDownIsRefusedWhileClaimDataExists(): void
    {
        $this->setUpClaims();
        try {
            $this->claimWith($this->makeLink()->token, '8701');
            $down = $this->migrate('down');
            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Irreversible', $down->getOutput().$down->getErrorOutput());
            self::assertTrue($this->tableExists('service_claim'));
            self::assertTrue($this->tableExists('service_ownership_event'));
            self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));

            // Only an ownership event left (e.g. a service returned by an admin): still refused.
            $this->em->getConnection()->executeStatement('UPDATE service_ownership_event SET claim_id = NULL');
            $this->em->getConnection()->executeStatement('DELETE FROM service_claim');
            $down = $this->migrate('down');
            self::assertConsoleContains('Irreversible', $down->getOutput().$down->getErrorOutput());
            self::assertTrue($this->tableExists('service_claim'));
        } finally {
            $this->restore();
        }
    }

    public function testWithoutClaimDataItGoesDownAndUpAgain(): void
    {
        try {
            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertFalse($this->tableExists('service_claim'));
            self::assertFalse($this->tableExists('service_ownership_event'));

            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
            self::assertTrue($this->tableExists('service_claim'));
            self::assertTrue($this->tableExists('service_ownership_event'));
        } finally {
            $this->restore();
        }
    }

    private function migrate(string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', self::CLAIMS, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
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
        if ($this->tableExists('service_claim')) {
            $connection->executeStatement('DELETE FROM service_ownership_event');
            $connection->executeStatement('DELETE FROM service_claim');
        }
        if (!$this->tableExists('service_claim')) {
            $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::CLAIMS]);
            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
        }
        if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::CLAIMS])) {
            $connection->insert('doctrine_migration_versions', ['version' => self::CLAIMS, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
        }
    }
}
