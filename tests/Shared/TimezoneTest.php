<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The application runs in UTC whatever the host's php.ini says (src/Kernel.php::boot()). Each case first gives PHP another
 * default timezone, as a host php.ini with date.timezone = Asia/Tehran would.
 */
final class TimezoneTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private string $hostTimezone;

    protected function setUp(): void
    {
        $this->hostTimezone = date_default_timezone_get();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set($this->hostTimezone);
    }

    public function testKernelBootSetsUtcOverAnotherHostTimezone(): void
    {
        self::ensureKernelShutdown();
        date_default_timezone_set('Asia/Tehran');

        self::bootKernel();

        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testEveryWebRequestRunsInUtc(): void
    {
        $client = new KernelBrowser(self::$kernel);
        date_default_timezone_set('Asia/Tehran');

        $client->request('GET', '/healthz');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('UTC', date_default_timezone_get(), 'handle() boots the kernel, which sets UTC again');
    }

    public function testConsoleRunsInUtcWhenTheHostIniSaysOtherwise(): void
    {
        $php = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
        $process = new Process([$php, '-d', 'date.timezone=Asia/Tehran', 'bin/console', 'about', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        self::assertMatchesRegularExpression('/^\s*Timezone\s+UTC\b/m', self::assertSubprocessClean($process, 'bin/console about'));
    }

    public function testStoredTimestampsAreUtcWallClockAsBefore(): void
    {
        date_default_timezone_set('Asia/Tehran');
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $before = time();
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $after = time();

        // The DATETIME column holds the UTC wall clock, exactly what PHP's default (UTC) produced before this change.
        $stored = (string) $this->row('`order`', (int) $order->getId())['created_at'];
        $storedTs = (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
        self::assertGreaterThanOrEqual($before, $storedTs);
        self::assertLessThanOrEqual($after + 1, $storedTs);
    }
}
