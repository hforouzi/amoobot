<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Domain\ModuleRegistryException;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SpyProductModule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * PM1d: the public subscription page (/s/{token}) and the import bridge (/s/{token}/open/{appId}) show what the
 * service's product module's customerView() gives, asked once per request with the service the token found. An unknown
 * token never reaches the module (the same neutral 404 as before); a service whose module type is unknown is the
 * resolver's typed ModuleRegistryException, not a page. What the page shows is pinned by SubscriptionPageSnapshotTest.
 */
final class SubscriptionPageViaModuleTest extends IntegrationTestCase
{
    use CustomerViewFixtures;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCustomerView();
    }

    protected function tearDown(): void
    {
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    private function startBrowser(): void
    {
        $this->browser = new KernelBrowser(self::$kernel);
        $this->browser->disableReboot();
        $this->browser->setServerParameter('REMOTE_ADDR', '10.94.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->browser->setServerParameter('HTTP_USER_AGENT', self::ANDROID);
    }

    public function testThePageAndTheBridgeAskTheModuleOnceForTheView(): void
    {
        $spy = SpyProductModule::install(self::getContainer());
        $this->startBrowser();
        $service = $this->stateService('active');
        $id = (int) $service->getId();
        $token = (string) $service->getPublicToken();

        $this->browser->request('GET', '/s/'.$token);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['customerView'], $spy->methods());
        $this->assertService($id, $spy->calls[0]['args']);

        // An app id that has no bridge: the view is built (once more), then the same 404 as before.
        $this->browser->request('GET', '/s/'.$token.'/open/99999999');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['customerView', 'customerView'], $spy->methods());
        $this->assertService($id, $spy->calls[1]['args']);
    }

    public function testAnUnknownTokenNeverReachesTheModule(): void
    {
        $spy = SpyProductModule::install(self::getContainer());
        $this->startBrowser();
        $this->stateService('active');
        $unknown = str_repeat('A', 43);

        foreach (['/s/'.$unknown, '/s/'.$unknown.'/open/1'] as $path) {
            $this->browser->request('GET', $path);
            self::assertSame(404, $this->browser->getResponse()->getStatusCode(), $path);
        }

        self::assertSame([], $spy->methods());
    }

    public function testAServiceOfAnUnknownModuleTypeIsATypedFailure(): void
    {
        // No module registered at all: the resolver's 'vpn' lookup fails as an unknown type would.
        self::getContainer()->set(ProductModuleRegistry::class, new ProductModuleRegistry([]));
        $this->startBrowser();
        $this->browser->catchExceptions(false);
        $service = $this->stateService('active');

        try {
            $this->browser->request('GET', '/s/'.$service->getPublicToken());
            self::fail('no exception');
        } catch (ModuleRegistryException $e) {
            self::assertSame(ModuleRegistryException::UNKNOWN_MODULE, $e->reason);
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function assertService(int $id, array $args): void
    {
        self::assertCount(1, $args);
        self::assertInstanceOf(VpnService::class, $args[0]);
        self::assertSame($id, $args[0]->getId());
    }
}
