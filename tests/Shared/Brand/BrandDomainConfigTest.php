<?php

declare(strict_types=1);

namespace App\Tests\Shared\Brand;

use App\Shared\Application\BrandDomain;
use App\Shared\Domain\BrandDomainConfig;
use App\Shared\Infrastructure\BrandTrustedHostsEnvProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class BrandDomainConfigTest extends TestCase
{
    private const DEFAULT_URI = 'https://cloud.mr13.win';

    public function testEmptyIsNoBrandAndNoProblem(): void
    {
        foreach ([null, '', '   '] as $value) {
            $config = BrandDomainConfig::parse($value, self::DEFAULT_URI);

            self::assertFalse($config->isActive());
            self::assertNull($config->brandBase);
            self::assertNull($config->problem);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function valid(): iterable
    {
        yield 'host' => ['https://stacktunnel.net', 'https://stacktunnel.net', 'stacktunnel.net'];
        yield 'subdomain' => ['https://app.stacktunnel.net', 'https://app.stacktunnel.net', 'app.stacktunnel.net'];
        yield 'port' => ['https://stacktunnel.net:8443', 'https://stacktunnel.net:8443', 'stacktunnel.net'];
        yield 'default port is dropped' => ['https://stacktunnel.net:443', 'https://stacktunnel.net', 'stacktunnel.net'];
        yield 'case and spaces' => ['  HTTPS://StackTunnel.NET ', 'https://stacktunnel.net', 'stacktunnel.net'];
    }

    #[DataProvider('valid')]
    public function testValid(string $value, string $base, string $host): void
    {
        $config = BrandDomainConfig::parse($value, self::DEFAULT_URI);

        self::assertTrue($config->isActive());
        self::assertSame($base, $config->brandBase);
        self::assertSame($host, $config->brandHost);
        self::assertSame(self::DEFAULT_URI, $config->operationalBase);
        self::assertSame('cloud.mr13.win', $config->operationalHost);
        self::assertNull($config->problem);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'http' => ['http://stacktunnel.net'];
        yield 'no scheme' => ['stacktunnel.net'];
        yield 'scheme relative' => ['//stacktunnel.net'];
        yield 'other scheme' => ['ftp://stacktunnel.net'];
        yield 'path' => ['https://stacktunnel.net/app'];
        yield 'trailing slash is a path' => ['https://stacktunnel.net/'];
        yield 'query' => ['https://stacktunnel.net?x=1'];
        yield 'empty query' => ['https://stacktunnel.net?'];
        yield 'fragment' => ['https://stacktunnel.net#top'];
        yield 'credentials' => ['https://user:secret@stacktunnel.net'];
        yield 'same host as DEFAULT_URI' => ['https://cloud.mr13.win'];
        yield 'same host, other case' => ['https://CLOUD.mr13.WIN'];
        yield 'same host, other port' => ['https://cloud.mr13.win:8443'];
        yield 'port out of range' => ['https://stacktunnel.net:70000'];
        yield 'port zero' => ['https://stacktunnel.net:0'];
        yield 'empty port' => ['https://stacktunnel.net:'];
        yield 'localhost' => ['https://localhost'];
        yield 'local name' => ['https://shop.local'];
        yield 'private address' => ['https://192.168.1.10'];
        yield 'space inside' => ['https://stack tunnel.net'];
        yield 'second line' => ["https://stacktunnel.net\nhttps://evil.example"];
        yield 'underscore' => ['https://stack_tunnel.net'];
        yield 'trailing dot' => ['https://stacktunnel.net.'];
    }

    #[DataProvider('invalid')]
    public function testInvalidIsIgnoredWholeWithAReason(string $value): void
    {
        $config = BrandDomainConfig::parse($value, self::DEFAULT_URI);

        self::assertFalse($config->isActive());
        self::assertNull($config->brandBase);
        self::assertNull($config->brandHost);
        self::assertNull($config->operationalBase);
        self::assertNotNull($config->problem);
        self::assertStringNotContainsString('secret', (string) $config->problem);
    }

    public function testADefaultUriWithoutAHostLeavesNothingToCompareWith(): void
    {
        foreach ([null, '', 'not a uri'] as $defaultUri) {
            self::assertFalse(BrandDomainConfig::parse('https://stacktunnel.net', $defaultUri)->isActive());
        }
    }

    public function testAnInvalidValueWarnsOnceAcrossRequestsAndNeverLogsTheValue(): void
    {
        $logger = $this->logger();
        $cache = new ArrayAdapter();

        for ($i = 0; $i < 3; ++$i) {
            $brand = new BrandDomain('https://user:secret@stacktunnel.net', self::DEFAULT_URI, new RequestStack(), $logger, $cache);
            self::assertFalse($brand->isActive());
            self::assertNull($brand->base());
            self::assertNull($brand->operationalBase());
        }

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertStringContainsString('BRAND_PUBLIC_BASE_URL', $logger->records[0][1]);
        self::assertStringNotContainsString('secret', $logger->records[0][1]);
        self::assertStringNotContainsString('stacktunnel', $logger->records[0][1]);
    }

    public function testValidAndEmptyValuesDoNotWarn(): void
    {
        $logger = $this->logger();

        new BrandDomain('', self::DEFAULT_URI, new RequestStack(), $logger, new ArrayAdapter());
        new BrandDomain(null, self::DEFAULT_URI, new RequestStack(), $logger, new ArrayAdapter());
        new BrandDomain('https://stacktunnel.net', self::DEFAULT_URI, new RequestStack(), $logger, new ArrayAdapter());

        self::assertSame([], $logger->records);
    }

    public function testTheBrandRequestIsDecidedByHostAndOnlyWithAnActiveBrand(): void
    {
        $stack = new RequestStack();
        $brand = new BrandDomain('https://stacktunnel.net:8443', self::DEFAULT_URI, $stack, $this->logger(), new ArrayAdapter());
        self::assertFalse($brand->isBrandRequest(), 'outside a request');

        $stack->push(Request::create('https://cloud.mr13.win/app'));
        self::assertFalse($brand->isBrandRequest());
        $stack->pop();

        $stack->push(Request::create('https://STACKTUNNEL.net:8443/app'));
        self::assertTrue($brand->isBrandRequest());

        $none = new BrandDomain('', self::DEFAULT_URI, $stack, $this->logger(), new ArrayAdapter());
        self::assertFalse($none->isBrandRequest());
    }

    public function testTrustedHostsListBothHostsOnlyWithAnActiveBrand(): void
    {
        self::assertSame('^cloud\.mr13\.win$,^stacktunnel\.net$', $this->trustedHosts('https://stacktunnel.net:8443'));
        self::assertSame('', $this->trustedHosts(null));
        self::assertSame('', $this->trustedHosts(''));
        self::assertSame('', $this->trustedHosts('http://stacktunnel.net'));
    }

    private function trustedHosts(?string $brand): string
    {
        return (new BrandTrustedHostsEnvProcessor())->getEnv('brand_trusted_hosts', 'BRAND', static fn (string $name): ?string => 'BRAND' === $name ? $brand : self::DEFAULT_URI);
    }

    private function logger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }
}
