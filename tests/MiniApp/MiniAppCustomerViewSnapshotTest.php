<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Branding\BrandSubscriptionPages;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\CustomerViewSnapshot;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Golden files of what the customer sees about a VPN service in the Mini App, as the real controllers serve it,
 * recorded before PM1d (docs/design/product-modules.md 9): the services list (_service_card), each state's service
 * page, the in-app subscription view, and the self-service actions (MiniAppServiceActionController: the JSON answer
 * and what the bot then sends into the chat), in Persian and English.
 */
final class MiniAppCustomerViewSnapshotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;
    use CustomerViewFixtures;
    use MiniAppTestTrait;

    private const LOCALES = ['fa' => '', 'en' => '?lang=en'];

    /** @var array{token: string, csrf: string} */
    private array $session;

    protected function setUp(): void
    {
        // The kernel boots in start(): the subscription page on the brand domain needs its environment first.
    }

    protected function tearDown(): void
    {
        $this->tearDownCustomerView();
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    private function start(bool $brandPages = false): void
    {
        if ($brandPages) {
            $this->useBrandDomain();
        }
        parent::setUp();
        $this->setUpCustomerView();
        if ($brandPages) {
            $this->viewSetting(BrandSubscriptionPages::ENABLED, 'true');
        }
        $this->startMiniAppClient();
        if ($brandPages) {
            // The Mini App is served on the brand domain then.
            $this->client->setServerParameter('HTTP_HOST', 'brand.example.org');
            $this->client->setServerParameter('HTTPS', 'on');
        }
        $this->session = $this->authenticate(self::VIEW_CUSTOMER, 'Sara');
    }

    public function testTheServicesList(): void
    {
        $this->start();
        foreach (self::STATES as $state) {
            $this->stateService($state);
        }

        $this->snapshotPages('miniapp-services', '/app/view/services');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function states(): iterable
    {
        foreach (self::STATES as $state) {
            yield $state => [$state, false];
        }
        // The page links (open, copy, main button) on the brand domain.
        yield 'active, pages on the brand domain' => ['active', true];
    }

    #[DataProvider('states')]
    public function testTheServicePage(string $state, bool $brandPages): void
    {
        $this->start($brandPages);
        $service = $this->stateService($state);

        $this->snapshotPages('miniapp-service-'.$state.($brandPages ? '-brand' : ''), '/app/view/services/'.$service->getId());
    }

    public function testRenewAndAddTrafficNotAllowed(): void
    {
        $this->start();
        $service = $this->stateService('active');
        $this->viewSetting(\App\Shop\Application\SalesSettingsProvider::RENEWALS_ENABLED, 'false');
        $this->viewSetting(\App\Shop\Application\SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'false');

        $this->snapshotPages('miniapp-service-sales-closed', '/app/view/services/'.$service->getId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function subscriptionStates(): iterable
    {
        foreach (['active', 'expired', 'multi_inbound', 'imported'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('subscriptionStates')]
    public function testTheInAppSubscriptionView(string $state): void
    {
        $this->start();
        $service = $this->stateService($state);

        $this->snapshotPages('miniapp-subscription-'.$state, '/app/view/services/'.$service->getId().'/subscription', 'platform=android');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function actions(): iterable
    {
        foreach (['send-link', 'send-qr', 'resend-config', 'refresh'] as $action) {
            yield $action => [$action, 'active'];
        }
        yield 'resend-config, expired' => ['resend-config', 'expired'];
        yield 'refresh, panel down' => ['refresh-failed', 'active'];
    }

    #[DataProvider('actions')]
    public function testTheSelfServiceActions(string $action, string $state): void
    {
        $this->start();
        $service = $this->stateService($state);
        $this->driver()->usage = new VpnUsage(trafficUsedGb: 20, trafficLimitGb: 50, usedBytes: 20 * 1073741824, totalBytes: 50 * 1073741824);
        $this->driver()->usageFails = 'refresh-failed' === $action;

        $out = '';
        foreach (self::LOCALES as $locale => $query) {
            $this->http->reset();
            $this->appPost('/app/api/services/'.$service->getId().'/'.str_replace('-failed', '', $action).$query, $this->session);
            $out .= sprintf("== %s: HTTP %d\n%s\n", $locale, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
            $out .= implode("\n\n", array_map(fn (string $line): string => CustomerViewSnapshot::normalize($line, $this->now, [], $this->serviceNames), $this->transcript()))."\n";
        }
        if ('refresh' === $action) {
            // What the reloaded section then shows.
            $out .= "== after refresh\n".$this->normalized($this->appGet('/app/view/services/'.$service->getId(), $this->session));
        }

        CustomerViewSnapshot::assertMatches('MiniApp', 'miniapp-action-'.$action.('active' !== $state ? '-'.$state : '').'.txt', $out);
    }

    private function snapshotPages(string $name, string $path, string $query = ''): void
    {
        foreach (self::LOCALES as $locale => $lang) {
            $url = $path.('' === $query ? $lang : '?'.$query.('' === $lang ? '' : '&lang=en'));
            $html = $this->appGet($url, $this->session);
            self::assertSame(200, $this->httpStatus(), $url);
            CustomerViewSnapshot::assertMatches('MiniApp', $name.'.'.$locale.'.html', $this->normalized($html));
        }
    }

    private function normalized(string $html): string
    {
        return CustomerViewSnapshot::normalize($html, $this->now, $this->opaque, $this->serviceNames, $this->expiries());
    }
}
