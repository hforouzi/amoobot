<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Reseller\Application\ResellerSettings;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who sees the reseller section: only an active reseller while reseller.enabled is on. Anybody else gets 404 on every
 * reseller route (views and APIs, GET and POST), no "Reseller" in the navigation, and nothing is written. The claim
 * link screens need reseller.claims_enabled too.
 */
final class ResellerMiniAppAccessTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    private const STRANGER = '8620';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
        $this->createUser(self::STRANGER);
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'dashboard' => ['GET', '/app/view/reseller'];
        yield 'new' => ['GET', '/app/view/reseller/new'];
        yield 'plan' => ['GET', '/app/view/reseller/new/{plan}'];
        yield 'services' => ['GET', '/app/view/reseller/services'];
        yield 'service' => ['GET', '/app/view/reseller/services/{sold}'];
        yield 'renew form' => ['GET', '/app/view/reseller/services/{sold}/renew'];
        yield 'traffic form' => ['GET', '/app/view/reseller/services/{sold}/add-traffic'];
        yield 'customers' => ['GET', '/app/view/reseller/customers'];
        yield 'customer form' => ['GET', '/app/view/reseller/customers/new'];
        yield 'links' => ['GET', '/app/view/reseller/links'];
        yield 'quote' => ['POST', '/app/api/reseller/new/quote'];
        yield 'buy' => ['POST', '/app/api/reseller/new'];
        yield 'renew quote' => ['POST', '/app/api/reseller/services/{sold}/renew/quote'];
        yield 'renew' => ['POST', '/app/api/reseller/services/{sold}/renew'];
        yield 'traffic quote' => ['POST', '/app/api/reseller/services/{sold}/add-traffic/quote'];
        yield 'traffic' => ['POST', '/app/api/reseller/services/{sold}/add-traffic'];
        yield 'assign' => ['POST', '/app/api/reseller/services/{sold}/customer'];
        yield 'customer create' => ['POST', '/app/api/reseller/customers'];
        yield 'claim link' => ['POST', '/app/api/reseller/services/{sold}/claim-link'];
        yield 'revoke' => ['POST', '/app/api/reseller/links/1/revoke'];
    }

    #[DataProvider('routes')]
    public function testANonResellerGets404AndNothingChanges(string $method, string $path): void
    {
        $this->assertRefusedFor($this->authenticate(self::STRANGER), $method, $path);
    }

    #[DataProvider('routes')]
    public function testWithResellersOffEveryRouteIs404(string $method, string $path): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->setting(ResellerSettings::ENABLED, 'false');
        $this->assertRefusedFor($session, $method, $path);
    }

    #[DataProvider('routes')]
    public function testADisabledResellerGets404(string $method, string $path): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->em->getConnection()->executeStatement("UPDATE reseller SET status = 'disabled' WHERE id = ?", [$this->reseller->getId()]);
        $this->assertRefusedFor($session, $method, $path);
    }

    public function testTheNavigationShowsTheEntryOnlyToAnActiveReseller(): void
    {
        $reseller = $this->authenticate(self::CUSTOMER);
        self::assertStringContainsString('data-nav-key="reseller"', $this->appGet('/app/view/nav', $reseller));
        self::assertStringNotContainsString('data-nav-key="reseller"', $this->appGet('/app/view/nav', $this->authenticate(self::STRANGER)));

        $this->setting(ResellerSettings::ENABLED, 'false');
        self::assertStringNotContainsString('data-nav-key="reseller"', $this->appGet('/app/view/nav', $reseller));
        self::assertStringNotContainsString('reseller', $this->appGet('/app/view/nav', $reseller), 'off: no trace of it');
    }

    public function testClaimLinkScreensNeedClaimsOnToo(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        self::assertStringContainsString('data-reseller-link="links"', $this->appGet('/app/view/reseller', $session));
        self::assertStringContainsString('data-make-link', $this->appGet('/app/view/reseller/services/'.$this->sold->getId(), $session));

        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');
        $html = $this->appGet('/app/view/reseller', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('data-reseller-link="links"', $html);
        self::assertStringNotContainsString('data-make-link', $this->appGet('/app/view/reseller/services/'.$this->sold->getId(), $session));
        $this->appGet('/app/view/reseller/links', $session);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/claim-link', $session);
        self::assertSame(404, $this->httpStatus());
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));
    }

    public function testWithoutTheCsrfTokenNothingIsBought(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $orders = $this->resellerOrders();
        $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], self::newKey(), 'wrong', ['HTTP_X_ADMIN_CONFIRM' => '1.x']);
        self::assertSame(403, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/customers', $session, ['name' => 'Ali'], self::newKey(), 'wrong');
        self::assertSame(403, $this->httpStatus());
        self::assertSame($orders, $this->resellerOrders());
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
    }

    /**
     * @param array{token: string, csrf: string} $session
     */
    private function assertRefusedFor(array $session, string $method, string $path): void
    {
        $path = strtr($path, ['{plan}' => (string) $this->plan->getId(), '{sold}' => (string) $this->sold->getId()]);
        $before = [$this->resellerOrders(), $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'), $this->sqlInt('SELECT COUNT(*) FROM service_claim')];
        if ('GET' === $method) {
            $this->appGet($path, $session);
        } else {
            $this->appPostJson($path, $session, ['planId' => (string) $this->plan->getId(), 'name' => 'Ali', 'customerId' => '1'], self::newKey());
        }
        self::assertSame(404, $this->httpStatus(), $method.' '.$path);
        self::assertSame($before, [$this->resellerOrders(), $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'), $this->sqlInt('SELECT COUNT(*) FROM service_claim')]);
    }
}
