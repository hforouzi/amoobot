<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The race tests' check of their subprocesses: ordinary log lines (on stdout or stderr, as CI's php.ini prints them)
 * pass; a PHP warning, an uncaught exception, an SQL error or a non-zero exit code fail.
 */
final class SubprocessAssertionsTest extends TestCase
{
    use SubprocessAssertions;

    private static function php(string $code): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $code]);
        $process->run();

        return $process;
    }

    public function testOrdinaryLogLinesAreNotProblems(): void
    {
        $process = self::php('error_log("[info] Wallet movement written."); fwrite(STDERR, "[info] Payment settled.\n[VpnProvisioningService] created\n"); echo "confirmed\n";');

        self::assertSame('confirmed', self::assertSubprocessClean($process));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function problems(): iterable
    {
        yield 'php warning' => ['trigger_error("boom", E_USER_WARNING); echo "confirmed";'];
        yield 'uncaught exception' => ['throw new RuntimeException("boom");'];
        yield 'sql error' => ['fwrite(STDERR, "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found\n"); echo "confirmed";'];
        yield 'non-zero exit' => ['echo "confirmed"; exit(3);'];
    }

    #[DataProvider('problems')]
    public function testRealProblemsFail(string $code): void
    {
        $process = self::php($code);

        $this->expectException(AssertionFailedError::class);
        self::assertSubprocessClean($process);
    }
}
