<?php

declare(strict_types=1);

namespace App\Tests\Clock;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the settlement and expiry test files under libfaketime with the clock starting 2 s and 1 s before a UTC midnight
 * boundary (end of day, end of month, into and out of 29 February). Each file takes about a second, so the date changes
 * while its tests run (a start 3 s early would finish before midnight). The app reads the system clock directly
 * (new \DateTimeImmutable() / time()), so faking the process clock is the only way to exercise these instants without
 * changing production code.
 *
 * The app runs in UTC (src/Kernel.php), so the boundaries are UTC midnights; the host php.ini is varied (UTC,
 * Europe/Berlin, Asia/Tehran via -d date.timezone) to show it changes nothing. libfaketime reads the instants in TZ=UTC.
 *
 * Needs libfaketime (Debian/Ubuntu: apt-get install faketime); skipped when it is missing. CI installs it.
 * Tests that start PHP child processes (#[Group('subprocess')]) are excluded: each child would restart the fake clock
 * from the same instant, so parent and child clocks disagree and lock/timeout assertions fail for reasons unrelated
 * to the date.
 */
#[Group('clock')]
final class MidnightCrossingTest extends TestCase
{
    private const FILES = [
        'tests/Payment/PaymentSettlementServiceTest.php',
        'tests/Payment/SettlementStatusesTest.php',
        'tests/Payment/LatePaymentAfterExpiryTest.php',
        'tests/Payment/LatePaymentAfterCancelTest.php',
        'tests/Payment/RepairStuckPaymentsCommandTest.php',
        'tests/Shop/IncompleteOrderExpiryServiceTest.php',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instants(): iterable
    {
        foreach (['UTC', 'Europe/Berlin', 'Asia/Tehran'] as $timezone) {
            foreach (['58', '59'] as $second) {
                yield "end of day :{$second}, {$timezone}" => ["2026-10-14 23:59:{$second}", $timezone];
                yield "end of month :{$second}, {$timezone}" => ["2026-01-31 23:59:{$second}", $timezone];
                yield "into 29 February :{$second}, {$timezone}" => ["2028-02-28 23:59:{$second}", $timezone];
                yield "out of 29 February :{$second}, {$timezone}" => ["2028-02-29 23:59:{$second}", $timezone];
            }
        }
    }

    #[DataProvider('instants')]
    public function testSettlementAndExpiryFlowsAcrossMidnight(string $instant, string $timezone): void
    {
        $library = self::libfaketime();
        if (null === $library) {
            self::markTestSkipped('libfaketime not installed (apt-get install faketime); CI runs this.');
        }
        if (false !== getenv('FAKETIME')) {
            self::markTestSkipped('already running under libfaketime');
        }

        $root = dirname(__DIR__, 2);
        foreach (self::FILES as $file) {
            $process = new Process(
                [PHP_BINARY, '-d', 'date.timezone='.$timezone, 'bin/phpunit', '--no-progress', '--exclude-group', 'subprocess', $file],
                $root,
                [
                    'APP_ENV' => 'test',
                    'TZ' => 'UTC',
                    'FAKETIME' => '@'.$instant,
                    'FAKETIME_DONT_RESET' => '1',
                    'FAKETIME_DONT_FAKE_MONOTONIC' => '1',
                    'LD_PRELOAD' => $library,
                ],
                null,
                300,
            );
            $process->run();

            self::assertTrue(
                $process->isSuccessful(),
                sprintf("%s failed with the clock starting at %s UTC (host date.timezone %s):\n%s", $file, $instant, $timezone, self::tail($process->getOutput().$process->getErrorOutput()))
            );
        }
    }

    private static function libfaketime(): ?string
    {
        $candidates = array_merge(
            false !== getenv('AMOOBOT_LIBFAKETIME') ? [(string) getenv('AMOOBOT_LIBFAKETIME')] : [],
            glob('/usr/lib/*/faketime/libfaketime.so.1') ?: [],
            ['/usr/lib/faketime/libfaketime.so.1', '/usr/local/lib/faketime/libfaketime.so.1'],
        );
        foreach ($candidates as $candidate) {
            if ('' !== $candidate && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function tail(string $output): string
    {
        return implode("\n", array_slice(explode("\n", trim($output)), -40));
    }
}
