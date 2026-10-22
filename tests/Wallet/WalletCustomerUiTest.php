<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Part 4: "My wallet" and wallet payment for the customer, in the Mini App and in the bot, through the M2 checkout
 * services and the Mini App's Idempotency-Key handling.
 */
final class WalletCustomerUiTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        $this->startMiniAppClient();
    }

    private function fund(int $amount): void
    {
        self::getContainer()->get(WalletService::class)->credit($this->user, $amount, WalletTransactionType::TOPUP, 'seed-'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function balance(): int
    {
        return (int) ($this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]) ?: 0);
    }

    // --- Mini App ---------------------------------------------------------------------------------

    public function testTheWalletPageShowsBalanceCreditAndPagedTransactions(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->fund(1000);
        }
        self::getContainer()->get(WalletService::class)->setCreditLimit($this->user, 5000, WalletActor::system());
        $session = $this->authenticate(self::CUSTOMER);

        $page = new Crawler($this->appGet('/app/view/wallet', $session));

        self::assertSame(200, $this->httpStatus());
        self::assertSame('12000', $page->filter('[data-wallet-balance]')->attr('data-wallet-balance'));
        self::assertCount(1, $page->filter('[data-wallet-credit]'));
        self::assertCount(10, $page->filter('[data-transaction-id]'));
        self::assertCount(4, $page->filter('[data-quick-amounts] button'));
        self::assertCount(1, $page->filter('[data-next-page]'));

        $second = new Crawler($this->appGet('/app/view/wallet?page=2', $session));
        self::assertCount(2, $second->filter('[data-transaction-id]'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet'), 'viewing creates nothing');
    }

    public function testTheWalletIsHiddenWhileSwitchedOff(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        self::assertCount(1, (new Crawler($this->appGet('/app/view/services', $session)))->filter('[data-wallet-link]'));

        $this->setting(WalletSettings::ENABLED, 'false');
        $this->appGet('/app/view/wallet', $session);
        self::assertSame(404, $this->httpStatus());
        self::assertCount(0, (new Crawler($this->appGet('/app/view/services', $session)))->filter('[data-wallet-link]'));
        $this->appPostJson('/app/api/wallet/topup', $session, ['amount' => 50000], self::newKey());
        self::assertSame(409, $this->httpStatus());
    }

    public function testATopUpIsPaidWithTheStoreMethodsOncePerKey(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $key = self::newKey();

        $answer = $this->appPostJson('/app/api/wallet/topup', $session, ['amount' => 100000], $key);
        $again = $this->appPostJson('/app/api/wallet/topup', $session, ['amount' => 100000], $key);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($answer, $again);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order` WHERE type = ?', [OrderType::WALLET_TOPUP]));
        $order = $this->em->getRepository(Order::class)->findOneBy(['type' => OrderType::WALLET_TOPUP]) ?? throw new \LogicException();
        self::assertSame('/app/view/orders/'.$order->getId().'/pay', $answer['redirect']);

        $pay = new Crawler($this->appGet($answer['redirect'], $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(count($this->methods), $pay->filter('[data-method-id]'));
        self::assertCount(0, $pay->filter('[data-pay-wallet]'), 'a top-up is never paid from the wallet');

        $started = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods['manual_card']->getId()], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($started, JSON_UNESCAPED_UNICODE) ?: '');
        $payment = new Crawler($this->appGet($started['redirect'], $session));
        self::assertStringContainsString(self::CARD_NUMBER, $payment->html());

        $this->appPostJson('/app/api/wallet/topup', $session, ['amount' => 5], self::newKey());
        self::assertSame(422, $this->httpStatus());
        self::assertSame('topup_out_of_range', $this->json()['error']);
    }

    public function testPayingAnOrderFromTheWalletInTheMiniApp(): void
    {
        $this->fund(200000);
        $session = $this->authenticate(self::CUSTOMER);
        $order = $this->createOrder($this->user, $this->plan);

        $pay = new Crawler($this->appGet('/app/view/orders/'.$order->getId().'/pay', $session));
        self::assertCount(1, $pay->filter('[data-pay-wallet]'));

        $key = self::newKey();
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay-wallet', $session, [], $key);
        $repeat = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay-wallet', $session, [], $key);

        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEquals($answer, $repeat, 'the same answer for a retry');
        self::assertSame(50000, $this->balance(), 'debited once');
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE order_id = ?', [$order->getId()]));
        $page = new Crawler($this->appGet($answer['redirect'], $session));
        self::assertSame('paid', $page->filter('[data-payment-state]')->attr('data-payment-state'));

        // A new key for a paid order: refused, nothing debited.
        $this->appPostJson('/app/api/orders/'.$order->getId().'/pay-wallet', $session, [], self::newKey());
        self::assertSame(404, $this->httpStatus());
        self::assertSame(50000, $this->balance());
    }

    public function testTheWalletIsNotOfferedWhenItDoesNotCoverTheOrder(): void
    {
        $this->fund(1000);
        $session = $this->authenticate(self::CUSTOMER);
        $order = $this->createOrder($this->user, $this->plan);

        $pay = new Crawler($this->appGet('/app/view/orders/'.$order->getId().'/pay', $session));
        self::assertCount(0, $pay->filter('[data-pay-wallet]'));

        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay-wallet', $session, [], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('wallet_insufficient', $answer['error']);
        self::assertNotSame('checkout.message.wallet_insufficient', $answer['message'], 'a translated message, never a raw key');
        self::assertSame(1000, $this->balance());
    }

    // --- bot ----------------------------------------------------------------------------------------

    public function testTheBotShowsMyWalletWithItsTransactions(): void
    {
        $this->fund(70000);

        $this->sendText(self::CUSTOMER, $this->botButton('button.main.wallet'));

        self::assertTrue($this->sentTextContains('70,000'), implode("\n", $this->sentTexts()));
        $callbacks = $this->sentCallbacks();
        self::assertContains('wallet_topup', $callbacks);
        self::assertContains('wallet_tx:1', $callbacks);
        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsString('wallet.', $text, 'no raw keys');
        }
    }

    public function testTheBotTopUpOffersTheStoreMethods(): void
    {
        $this->pressButton(self::CUSTOMER, 'wallet_topup');
        self::assertContains('wallet_topup:50000', $this->sentCallbacks());

        $this->pressButton(self::CUSTOMER, 'wallet_topup:50000');

        $order = $this->em->getRepository(Order::class)->findOneBy(['type' => OrderType::WALLET_TOPUP]) ?? throw new \LogicException('no top-up order');
        self::assertSame(50000, $order->getAmount());
        self::assertContains('select_store_payment_method:'.$order->getId().':'.$this->methods['manual_card']->getId(), $this->sentCallbacks());

        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->methods['manual_card']->getId());
        self::assertTrue($this->sentTextContains(self::CARD_NUMBER));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = ?', [$order->getId()]));
    }

    public function testTheBotPaysAnOrderFromTheWallet(): void
    {
        $this->fund(200000);
        $order = $this->createOrder($this->user, $this->plan);

        $this->pressButton(self::CUSTOMER, 'order_payment_methods:'.$order->getId());
        self::assertContains('wallet_pay:'.$order->getId(), $this->sentCallbacks());

        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());

        self::assertSame(50000, $this->balance(), 'a double tap debits once');
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertTrue($this->sentTextContains('50,000'));
    }
}
