<?php

declare(strict_types=1);

namespace App\Tests\Shared\Brand;

use App\Tests\Support\BrandDomainEnvironment;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The real kernel: the framework reads trusted_hosts through the env processor, and no cookie is shared between hosts. */
final class BrandTrustedHostsTest extends WebTestCase
{
    use BrandDomainEnvironment;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    public function testAnActiveBrandTrustsExactlyBothHosts(): void
    {
        $this->useBrandDomain();
        $client = self::createClient();

        self::assertSame(['{^cloud\.example\.net$}i', '{^brand\.example\.org$}i'], Request::getTrustedHosts());

        $client->request('GET', 'https://cloud.example.net/healthz');
        self::assertLessThan(500, $client->getResponse()->getStatusCode());
        $client->request('GET', 'https://evil.example.com/healthz');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $client->request('GET', 'https://sub.brand.example.org/app');
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNoBrandAndAnInvalidBrandKeepTheHostCheckOff(): void
    {
        foreach ([null, 'http://brand.example.org'] as $value) {
            $this->useBrandDomain($value);
            $client = self::createClient();

            self::assertSame([], Request::getTrustedHosts());
            $client->request('GET', 'https://any.example.com/healthz');
            self::assertNotSame(400, $client->getResponse()->getStatusCode());

            self::ensureKernelShutdown();
            $this->restoreBrandDomain();
        }
    }

    public function testNoCookieDomainIsShared(): void
    {
        self::bootKernel();
        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertIsArray($options);
        self::assertEmpty($options['cookie_domain'] ?? null);
    }
}
