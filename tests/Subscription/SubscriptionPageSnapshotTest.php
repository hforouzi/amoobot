<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Entity\ClientApp;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\CustomerViewSnapshot;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Golden files of the public subscription page (/s/{token}) for every service state, and of the app import bridge
 * (/s/{token}/open/{appId}) with its automatic open and with ?noauto=1, in Persian and English, recorded before PM1d
 * (docs/design/product-modules.md 9). The client app catalogue is the test's own (the seeded apps are switched off).
 */
final class SubscriptionPageSnapshotTest extends IntegrationTestCase
{
    use CustomerViewFixtures;
    use GuideClientAppFixtures;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';
    private const LOCALES = ['fa' => '', 'en' => 'lang=en'];

    private KernelBrowser $browser;
    private ClientApp $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCustomerView();
        $this->browser = new KernelBrowser(self::$kernel);
        $this->browser->disableReboot();
        $this->browser->setServerParameter('REMOTE_ADDR', '10.93.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->browser->setServerParameter('HTTP_USER_AGENT', self::ANDROID);
        $this->app = $this->addClientApp('v2rayNG', ['android'], 1, 'https://play.google.com/store/apps/details?id=com.v2ray.ang', true, 'v2rayng://install-sub?url={url_encoded}#{name}');
        $this->addClientApp('Hiddify', ['android', 'ios'], 2, null, true, 'hiddify://import/{url}#{name}');
        $this->opaque['/open/'.$this->app->getId()] = '/open/APP_V2RAYNG';
        $this->opaque['open%2F'.$this->app->getId()] = 'open%2FAPP_V2RAYNG';
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function states(): iterable
    {
        foreach (self::STATES as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('states')]
    public function testTheSubscriptionPage(string $state): void
    {
        $service = $this->stateService($state);
        $this->registerOtherApps();

        foreach (self::LOCALES as $locale => $query) {
            CustomerViewSnapshot::assertMatches('Subscription', 'page-'.$state.'.'.$locale.'.html', $this->get('/s/'.$service->getPublicToken(), $query));
        }
    }

    public function testTheImportBridge(): void
    {
        $service = $this->stateService('active');
        $this->registerOtherApps();

        foreach (['auto' => '', 'noauto' => 'noauto=1'] as $mode => $noauto) {
            foreach (self::LOCALES as $locale => $query) {
                $html = $this->get('/s/'.$service->getPublicToken().'/open/'.$this->app->getId(), implode('&', array_filter([$noauto, $query])));
                CustomerViewSnapshot::assertMatches('Subscription', 'bridge-'.$mode.'.'.$locale.'.html', $html);
            }
        }
    }

    private function get(string $path, string $query): string
    {
        $this->browser->request('GET', $path.('' === $query ? '' : '?'.$query));
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $path);

        return CustomerViewSnapshot::normalize((string) $this->browser->getResponse()->getContent(), $this->now, $this->opaque, $this->serviceNames, $this->expiries());
    }

    /** Every active app's id (the test's own), named by its name: ids come from the autoincrement. */
    private function registerOtherApps(): void
    {
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id, name FROM client_app WHERE is_active = 1') as $row) {
            $name = 'APP_'.strtoupper((string) preg_replace('/\W+/', '_', (string) $row['name']));
            $this->opaque['/open/'.$row['id']] = '/open/'.$name;
            $this->opaque['open%2F'.$row['id']] = 'open%2F'.$name;
            $this->opaque['data-app-id="'.$row['id'].'"'] = 'data-app-id="'.$name.'"';
        }
    }
}
