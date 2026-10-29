<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Entity\Plan;
use App\Entity\VpnService;
use App\Shop\Application\SalesSettingsProvider;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Buying at wholesale in the Mini App goes through the bot's own path (ResellerCheckout with its signed one-time
 * confirmation): the first call changes nothing and answers 428 with the price, the second (same Idempotency-Key, the
 * confirmation's token) buys from the wallet. The amount is never taken from the client; a changed price, an altered or
 * expired token asks again; a repeat of the same call or of the same confirmation is one order.
 */
final class ResellerMiniAppPurchaseTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    /** 150,000 Toman, 20 % off. */
    private const WHOLESALE = 120000;

    private Plan $custom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
        // 10-200 GB at 2,000 Toman, 30-90 days at 1,000 Toman.
        $this->custom = (new Plan())->setTitle('Custom')->setDurationDays(30)->setTrafficGb(10)->setPrice(0)->setInbound($this->inbound)
            ->setIsCustomizable(true)->setMinTrafficGb(10)->setMaxTrafficGb(200)->setPricePerGb(2000)
            ->setMinDurationDays(30)->setMaxDurationDays(90)->setPricePerDay(1000);
        $this->em->persist($this->custom);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testTheDashboardShowsWalletCapSalesAndOrders(): void
    {
        $this->reseller->setDailySpendLimit(500000);
        $this->em->flush();
        $html = $this->appGet('/app/view/reseller', $this->authenticate(self::CUSTOMER));

        self::assertSame(200, $this->httpStatus(), $html);
        self::assertStringContainsString('data-balance="'.(1_000_000 - self::WHOLESALE).'"', $html);
        self::assertStringContainsString('data-remaining-today="'.(500000 - self::WHOLESALE).'"', $html);
        // One sale (setUp): wholesale 120,000 against retail 150,000.
        self::assertStringContainsString('data-sales="total" data-wholesale="120000" data-retail="150000" data-margin="30000"', $html);
        self::assertStringContainsString('data-sales="month" data-wholesale="120000"', $html);
        self::assertStringContainsString('data-order-id=', $html);
        self::assertStringContainsString('data-tile="mine"><b>1</b>', $html);
        self::assertStringNotContainsString((string) $this->othersService->getUsername(), $html, 'never another reseller\'s figures');
    }

    public function testBuyingAFixedPlanAsksForConfirmationThenPaysFromTheWallet(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $html = $this->appGet('/app/view/reseller/new', $session);
        self::assertStringContainsString('data-plan-id="'.$this->plan->getId().'" data-wholesale="'.self::WHOLESALE.'"', $html);
        $this->appGet('/app/view/reseller/new/'.$this->plan->getId(), $session);
        self::assertSame(200, $this->httpStatus());

        $key = self::newKey();
        $first = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key);
        self::assertSame(428, $this->httpStatus());
        self::assertSame(1, $this->soldCount(), 'the confirmation step buys nothing');
        self::assertMatchesRegularExpression('/^120000\.[0-9a-z]{7}[0-9a-f]{6}[A-Za-z0-9_-]{10}$/D', $first['confirm']['token']);
        self::assertStringContainsString('120,000', $first['confirm']['message']);

        $answer = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key, null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($answer));
        self::assertSame(2, $this->soldCount());
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->reseller], ['id' => 'DESC']);
        self::assertSame('/app/view/reseller/services/'.$service?->getId(), $answer['redirect']);
        self::assertSame(1_000_000 - 2 * self::WHOLESALE, $this->balance());

        // The same call again (a retry after a lost answer): the same answer, nothing more bought.
        $again = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key, null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame($answer, $again);
        // The same confirmation with another key (a double tap after an error dropped the key): the first order's state.
        $other = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], self::newKey(), null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('done', $other['state'] ?? null);
        self::assertSame(2, $this->soldCount());
        self::assertSame(1_000_000 - 2 * self::WHOLESALE, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testACustomizablePlanIsQuotedAndBoughtWithTheChosenTrafficAndDays(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $html = $this->appGet('/app/view/reseller/new/'.$this->custom->getId(), $session);
        self::assertStringContainsString('name="trafficGb"', $html);
        self::assertStringContainsString('name="durationDays"', $html);

        $quote = $this->appPostJson('/app/api/reseller/new/quote', $session, ['planId' => (string) $this->custom->getId(), 'trafficGb' => '50', 'durationDays' => '60']);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($quote));
        // (50 x 2,000 + 60 x 1,000) - 20 % = 128,000.
        self::assertSame(128000, $quote['wholesaleAmount']);
        self::assertSame(160000, $quote['retailAmount']);

        [$answer] = $this->buyThroughApp($session, '/app/api/reseller/new', ['planId' => (string) $this->custom->getId(), 'trafficGb' => '50', 'durationDays' => '60']);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($answer));
        $order = $this->em->getConnection()->fetchAssociative('SELECT amount, metadata FROM `order` WHERE reseller_id = ? ORDER BY id DESC LIMIT 1', [$this->reseller->getId()]) ?: [];
        self::assertSame(128000, (int) $order['amount']);
        $metadata = json_decode((string) $order['metadata'], true);
        self::assertSame([50, 60], [$metadata['trafficGb'], $metadata['durationDays']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badChoices(): iterable
    {
        yield 'space before' => [['trafficGb' => ' 50', 'durationDays' => '60']];
        yield 'trailing newline' => [['trafficGb' => "50\n", 'durationDays' => '60']];
        yield 'decimal' => [['trafficGb' => '50.0', 'durationDays' => '60']];
        yield 'exponent' => [['trafficGb' => '5e1', 'durationDays' => '60']];
        yield 'negative' => [['trafficGb' => '-50', 'durationDays' => '60']];
        yield 'leading zero' => [['trafficGb' => '050', 'durationDays' => '60']];
        yield 'persian digits' => [['trafficGb' => '۵۰', 'durationDays' => '60']];
        yield 'array' => [['trafficGb' => ['50'], 'durationDays' => '60']];
        yield 'float' => [['trafficGb' => 50.5, 'durationDays' => '60']];
        yield 'out of range' => [['trafficGb' => '201', 'durationDays' => '60']];
        yield 'missing' => [['durationDays' => '60']];
        yield 'plan id with newline' => [['planId' => "1\n", 'trafficGb' => '50', 'durationDays' => '60']];
    }

    /**
     * @param array<string, mixed> $choices
     */
    #[DataProvider('badChoices')]
    public function testChoicesAreReadExactlyAndRefusedOtherwise(array $choices): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $body = $choices + ['planId' => (string) $this->custom->getId()];
        $this->appPostJson('/app/api/reseller/new/quote', $session, $body);
        self::assertSame(422, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/new', $session, $body, self::newKey(), null, ['HTTP_X_ADMIN_CONFIRM' => '128000.0000000000000AAAAAAAAAA']);
        self::assertContains($this->httpStatus(), [422], 'refused before anything else');
        self::assertSame(1, $this->soldCount());
    }

    public function testTheAmountIsNeverTakenFromTheClient(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $key = self::newKey();
        $first = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId(), 'amount' => '1'], $key);
        self::assertSame(428, $this->httpStatus());
        // The token's amount altered to 1: its signature no longer matches, so it is a fresh confirmation, not a sale.
        [, $nonce] = explode('.', (string) $first['confirm']['token']);
        $answer = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId(), 'amount' => '1'], $key, null, ['HTTP_X_ADMIN_CONFIRM' => '1.'.$nonce]);
        self::assertSame(428, $this->httpStatus(), (string) json_encode($answer));
        self::assertStringStartsWith('120000.', $answer['confirm']['token']);
        foreach (["120000.$nonce\n", "120000.$nonce ", ' 120000.'.$nonce, '120000'.$nonce, '0120000.'.$nonce] as $bad) {
            $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], self::newKey(), null, ['HTTP_X_ADMIN_CONFIRM' => $bad]);
            self::assertSame(428, $this->httpStatus(), 'not a token: '.json_encode($bad));
        }
        self::assertSame(1, $this->soldCount());
        self::assertSame(1_000_000 - self::WHOLESALE, $this->balance());
    }

    public function testAChangedPriceAsksAgainAndBuysNothing(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $key = self::newKey();
        $first = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key);
        // The admin lowers his discount to 10 % meanwhile: 135,000 now.
        $this->em->getConnection()->executeStatement('UPDATE reseller SET discount_bp = 1000 WHERE id = ?', [$this->reseller->getId()]);

        $answer = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key, null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame(428, $this->httpStatus(), (string) json_encode($answer));
        self::assertStringStartsWith('135000.', $answer['confirm']['token']);
        $notice = self::getContainer()->get('translator')->trans('reseller.confirm.price_changed', [], 'miniapp', 'fa');
        self::assertStringStartsWith($notice, $answer['confirm']['message']);
        self::assertSame(1, $this->soldCount());

        // OK on the new price, same key: bought at the new price.
        $bought = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key, null, ['HTTP_X_ADMIN_CONFIRM' => $answer['confirm']['token']]);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($bought));
        self::assertSame(1_000_000 - self::WHOLESALE - 135000, $this->balance());
    }

    public function testAFrozenWalletOrTooLittleMoneyBuysNothing(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->em->getConnection()->executeStatement("UPDATE wallet SET status = 'frozen' WHERE user_id = ?", [$this->user->getId()]);
        $first = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key = self::newKey());
        $answer = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key, null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame(409, $this->httpStatus(), (string) json_encode($answer));
        self::assertSame('wallet_frozen', $answer['error']);
        self::assertStringContainsString('data-wallet-frozen', $this->appGet('/app/view/reseller', $session));

        $this->em->getConnection()->executeStatement("UPDATE wallet SET status = 'active', balance = 1000 WHERE user_id = ?", [$this->user->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM wallet_transaction WHERE wallet_id = (SELECT id FROM wallet WHERE user_id = ?)', [$this->user->getId()]);
        [$poor] = $this->buyThroughApp($session, '/app/api/reseller/new', ['planId' => (string) $this->plan->getId()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('wallet_insufficient', $poor['error']);
        self::assertSame(1, $this->soldCount());
        self::assertSame(1, $this->resellerOrders(), 'a refused purchase leaves no order');
    }

    public function testADisableBetweenConfirmationAndPurchaseBuysNothing(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $key = self::newKey();
        $first = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key);
        $this->em->getConnection()->executeStatement("UPDATE reseller SET status = 'disabled' WHERE id = ?", [$this->reseller->getId()]);

        $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], $key, null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame(404, $this->httpStatus());
        self::assertSame(1, $this->resellerOrders());
    }

    public function testWithoutAnIdempotencyKeyNothingIsBought(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $first = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()]);
        $answer = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], null, null, ['HTTP_X_ADMIN_CONFIRM' => $first['confirm']['token']]);
        self::assertSame(400, $this->httpStatus(), (string) json_encode($answer));
        self::assertSame(1, $this->resellerOrders());
    }

    public function testRenewAndAddTrafficAtWholesale(): void
    {
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
        $session = $this->authenticate(self::CUSTOMER);
        $id = (int) $this->sold->getId();
        self::assertStringContainsString('data-wholesale="'.self::WHOLESALE.'"', $this->appGet('/app/view/reseller/services/'.$id.'/renew', $session));
        [$renewed] = $this->buyThroughApp($session, '/app/api/reseller/services/'.$id.'/renew', []);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($renewed));
        self::assertSame('/app/view/reseller/services/'.$id, $renewed['redirect']);

        $this->appGet('/app/view/reseller/services/'.$id.'/add-traffic', $session);
        self::assertSame(200, $this->httpStatus());
        [$added] = $this->buyThroughApp($session, '/app/api/reseller/services/'.$id.'/add-traffic', ['trafficGb' => '20']);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($added));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM `order` WHERE type = 'renewal' AND reseller_id = ".(int) $this->reseller->getId()));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM `order` WHERE type = 'add_traffic' AND reseller_id = ".(int) $this->reseller->getId()));

        // Another reseller's service: not found, whatever the call.
        $other = (int) $this->othersService->getId();
        foreach (['/renew/quote', '/renew', '/add-traffic/quote', '/add-traffic'] as $suffix) {
            $this->appPostJson('/app/api/reseller/services/'.$other.$suffix, $session, ['trafficGb' => '20'], self::newKey());
            self::assertSame(404, $this->httpStatus(), $suffix);
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order` WHERE target_service_id = '.$other));
    }

    public function testSalesClosedIsSaidAndNothingIsBought(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->setting(SalesSettingsProvider::NEW_ORDERS_ENABLED, 'false');
        $html = $this->appGet('/app/view/reseller/new', $session);
        self::assertStringContainsString('data-offers-empty="sales_closed"', $html);
        $answer = $this->appPostJson('/app/api/reseller/new', $session, ['planId' => (string) $this->plan->getId()], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_closed', $answer['error']);
    }
}
