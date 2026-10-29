<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * L2: the reseller section's budget (miniapp_reseller, per Telegram user) covers every write, both purchase steps (the
 * 428 confirmation and the purchase) and every quote call. Once it is used up each of them answers 429 rate_limited
 * and changes nothing.
 */
final class ResellerMiniAppRateLimitTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testEveryWriteQuoteAndConfirmStepIsLimited(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $created = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => 'Ali'], self::newKey());
        self::assertSame(200, $this->httpStatus(), (string) json_encode($created));
        $label = (int) substr((string) $created['redirect'], strlen('/app/view/reseller/customers/'));
        $sold = (string) $this->sold->getId();
        $this->appPostJson('/app/api/reseller/services/'.$sold.'/customer', $session, ['customerId' => (string) $label]);
        self::assertSame(200, $this->httpStatus());
        $before = [$this->sqlInt('SELECT COUNT(*) FROM `order`'), $this->sqlInt('SELECT COUNT(*) FROM service_claim'), $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'), $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service')];

        $limiter = self::getContainer()->get('limiter.miniapp_reseller');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        while ($limiter->create(self::CUSTOMER)->consume()->isAccepted()) {
        }

        $calls = [
            'new quote' => ['/new/quote', ['planId' => (string) $this->plan->getId()]],
            'new: the 428 step' => ['/new', ['planId' => (string) $this->plan->getId()]],
            'renewal quote' => ['/services/'.$sold.'/renew/quote', []],
            'renewal: the 428 step' => ['/services/'.$sold.'/renew', []],
            'add-traffic quote' => ['/services/'.$sold.'/add-traffic/quote', ['trafficGb' => '10']],
            'add-traffic: the 428 step' => ['/services/'.$sold.'/add-traffic', ['trafficGb' => '10']],
            'assign' => ['/services/'.$sold.'/customer', ['customerId' => '']],
            'label create' => ['/customers', ['name' => 'Sara']],
            'label update' => ['/customers/'.$label, ['name' => 'Sara']],
            'label delete' => ['/customers/'.$label.'/delete', []],
            'claim link' => ['/services/'.$sold.'/claim-link', []],
            'revoke' => ['/links/1/revoke', []],
        ];
        foreach ($calls as $name => [$path, $body]) {
            $answer = $this->appPostJson('/app/api/reseller'.$path, $session, $body, self::newKey());
            self::assertSame(429, $this->httpStatus(), $name.': '.json_encode($answer));
            self::assertSame('rate_limited', $answer['error'] ?? null, $name);
            self::assertNotSame('', (string) ($answer['message'] ?? ''), $name);
        }

        self::assertSame($before, [$this->sqlInt('SELECT COUNT(*) FROM `order`'), $this->sqlInt('SELECT COUNT(*) FROM service_claim'), $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'), $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service')], 'nothing changed');
        self::assertSame('Ali', $this->em->getConnection()->fetchOne('SELECT name FROM reseller_customer WHERE id = ?', [$label]));
    }
}
