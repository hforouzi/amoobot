<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Wallet;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fourth review, item 7: before a wallet purchase the customer confirms "Pay X Toman from your wallet? Balance Y -> Z"
 * (bot: Confirm / Cancel buttons; Mini App: the server's confirmation step, 428 + token). Only Confirm pays; Cancel
 * changes nothing; a double tap pays once; the balance shown is the one in the database at that moment.
 */
final class WalletPaymentConfirmationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;
    use SubprocessAssertions;

    private const PRICE = 150000;
    private const FUNDED = 200000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, self::FUNDED, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $this->startMiniAppClient();
    }

    private function balance(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]);
    }

    private function payments(Order $order): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = ?', [$order->getId()]);
    }

    private function debits(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'");
    }

    private function assertUnpaid(Order $order): void
    {
        self::assertSame(self::FUNDED, $this->balance(), 'nothing debited');
        self::assertSame(0, $this->debits());
        self::assertSame(0, $this->payments($order), 'no payment started');
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
    }

    private function assertPaidOnce(Order $order): void
    {
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'debited once');
        self::assertSame(1, $this->debits());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM payment WHERE order_id = ? AND status = 'confirmed'", [$order->getId()]));
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    // --- bot ----------------------------------------------------------------------------------------

    public function testTheBotAsksToConfirmWithTheAmountAndTheBalanceBeforeAndAfter(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);

        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());

        self::assertTrue($this->sentTextContains('150,000 تومان از کیف پول شما پرداخت شود؟'), implode("\n", $this->sentTexts()));
        self::assertTrue($this->sentTextContains('موجودی فعلی: 200,000 تومان'));
        self::assertTrue($this->sentTextContains('موجودی پس از پرداخت: 50,000 تومان'));
        self::assertSame(['wallet_pay_confirm:'.$order->getId(), 'order_payment_methods:'.$order->getId()], $this->sentCallbacks());
        $this->assertUnpaid($order);
    }

    public function testCancelGoesBackToThePaymentMethodsAndChangesNothing(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, 'order_payment_methods:'.$order->getId());

        $callbacks = $this->sentCallbacks();
        self::assertContains('wallet_pay:'.$order->getId(), $callbacks, 'the payment methods again, the wallet among them');
        self::assertContains('select_store_payment_method:'.$order->getId().':'.$this->methods['zibal']->getId(), $callbacks);
        $this->assertUnpaid($order);
    }

    public function testConfirmPaysAndASecondTapPaysNothing(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());

        $this->pressButton(self::CUSTOMER, 'wallet_pay_confirm:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'wallet_pay_confirm:'.$order->getId());

        $this->assertPaidOnce($order);
        // Asking again for a paid order: refused, nothing shown to confirm.
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());
        self::assertNotContains('wallet_pay_confirm:'.$order->getId(), $this->sentCallbacks());
        $this->assertPaidOnce($order);
    }

    /** The same step for a renewal: Cancel shows the renewal's payment methods again; Confirm renews. */
    public function testARenewalIsConfirmedTheSameWay(): void
    {
        $service = $this->createActiveService($this->user, $this->plan->getInbound());
        $order = $this->createOrder($this->user, $this->plan, OrderType::RENEWAL, self::PRICE, $service);

        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());
        self::assertTrue($this->sentTextContains('موجودی پس از پرداخت: 50,000 تومان'), implode("\n", $this->sentTexts()));
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'order_payment_methods:'.$order->getId());
        self::assertContains('wallet_pay:'.$order->getId(), $this->sentCallbacks(), 'back to the renewal\'s payment methods');
        self::assertSame(self::FUNDED, $this->balance());
        self::assertSame(0, $this->payments($order));

        $this->pressButton(self::CUSTOMER, 'wallet_pay_confirm:'.$order->getId());
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }

    /** Two taps on Confirm handled at the same time (two webhook requests, two processes): paid once. */
    public function testTwoConfirmTapsAtTheSameTimePayOnce(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $startAt = sprintf('%.6f', microtime(true) + 8.0); // both processes boot first (one after the other), then go together
        $taps = [];
        for ($i = 0; $i < 2; ++$i) {
            $taps[$i] = new Process([$php, dirname(__DIR__).'/Support/bin/bot-callback.php', self::CUSTOMER, 'wallet_pay_confirm:'.$order->getId(), $startAt], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '500'], null, 120);
            $taps[$i]->start();
        }
        foreach ($taps as $i => $tap) {
            $tap->wait();
            self::assertStringEndsWith('done', self::assertSubprocessClean($tap, 'bot-callback.php #'.$i));
        }

        $this->em->clear();
        $this->assertPaidOnce($order);
        self::assertSame(1, $this->payments($order));
    }

    /** A long-running bot worker holds an older copy of the wallet: the confirmation reads the database again. */
    public function testTheBotShowsTheCurrentBalance(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $wallet = self::getContainer()->get(WalletService::class)->find($this->user) ?? throw new \LogicException();
        self::assertSame(self::FUNDED, $wallet->getBalance(), 'loaded into this process');
        // Another process (php-fpm: an admin adjustment or a top-up) changes the balance meanwhile.
        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $other->executeStatement('UPDATE wallet SET balance = balance + 30000 WHERE user_id = ?', [$this->user->getId()]);

        $this->pressButton(self::CUSTOMER, 'wallet_pay:'.$order->getId());

        self::assertTrue($this->sentTextContains('موجودی فعلی: 230,000 تومان'), implode("\n", $this->sentTexts()));
        self::assertTrue($this->sentTextContains('موجودی پس از پرداخت: 80,000 تومان'));
        self::assertSame(230000, $this->em->find(Wallet::class, $wallet->getId())?->getBalance());
    }

    // --- Mini App -------------------------------------------------------------------------------------

    public function testTheMiniAppAsksToConfirmFirstAndPaysOnlyWithTheToken(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $session = $this->authenticate(self::CUSTOMER);
        $url = '/app/api/orders/'.$order->getId().'/pay-wallet';
        $key = self::newKey();

        $ask = $this->appPostJson($url, $session, [], $key);
        self::assertSame(428, $this->httpStatus());
        self::assertSame('confirm_required', $ask['error']);
        self::assertStringContainsString('150,000 تومان از کیف پول شما پرداخت شود؟', $ask['confirm']['message']);
        self::assertStringContainsString('موجودی فعلی: 200,000 تومان', $ask['confirm']['message']);
        self::assertStringContainsString('موجودی پس از پرداخت: 50,000 تومان', $ask['confirm']['message']);
        $en = $this->appPostJson($url.'?lang=en', $session, [], $key);
        self::assertSame("Pay 150,000 Toman from your wallet?\nBalance: 200,000 → 50,000 Toman", $en['confirm']['message']);
        // Cancel: the app sends nothing more. A forged token pays nothing either.
        $this->appPostJson($url, $session, [], $key, null, ['HTTP_X_ADMIN_CONFIRM' => '9999999999.'.str_repeat('A', 43)]);
        self::assertSame(428, $this->httpStatus());
        $this->assertUnpaid($order);

        // OK: the same call with the token; a double tap (same key) gets the same answer.
        $confirm = ['HTTP_X_ADMIN_CONFIRM' => (string) $ask['confirm']['token']];
        $paid = $this->appPostJson($url, $session, [], $key, null, $confirm);
        self::assertSame(200, $this->httpStatus(), json_encode($paid, JSON_UNESCAPED_UNICODE) ?: '');
        $again = $this->appPostJson($url, $session, [], $key, null, $confirm);
        self::assertEquals($paid, $again);
        // A second tap with another key (and the same token): the order is paid, nothing more is taken.
        $this->appPostJson($url, $session, [], self::newKey(), null, $confirm);
        self::assertSame(404, $this->httpStatus());
        $this->assertPaidOnce($order);
        self::assertSame(PaymentStatus::CONFIRMED, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE order_id = ?', [$order->getId()]));
    }

    public function testTheMiniAppConfirmationShowsTheBalanceAtTheTap(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $session = $this->authenticate(self::CUSTOMER);
        $this->appGet('/app/view/orders/'.$order->getId().'/pay', $session);
        self::getContainer()->get(WalletService::class)->credit($this->user, 25000, WalletTransactionType::TOPUP, 'later:'.random_int(1, PHP_INT_MAX), WalletActor::system());

        $ask = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay-wallet?lang=en', $session, [], self::newKey());

        self::assertSame(428, $this->httpStatus());
        self::assertSame("Pay 150,000 Toman from your wallet?\nBalance: 225,000 → 75,000 Toman", $ask['confirm']['message']);
    }

    public function testTheMiniAppRefusesToAskWhenTheWalletDoesNotCoverTheOrder(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 500000);
        $session = $this->authenticate(self::CUSTOMER);

        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay-wallet', $session, [], self::newKey());

        self::assertSame(409, $this->httpStatus());
        self::assertSame('wallet_insufficient', $answer['error']);
        self::assertSame(self::FUNDED, $this->balance());
    }
}
