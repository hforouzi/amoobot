<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Application\WalletTopUp;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The whole wallet flow (top-up through a gateway, pay with the wallet, provisioning failure, admin rejection and
 * reversal) on a fresh database whose ids all start at 1, and on one where every table has its own id range, so that a
 * user id used as a wallet id (or an order id as a payment id) would show up as a wrong row.
 */
final class WalletIdCharacterizationTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const TABLES = ['`user`' => 1, 'telegram_account' => 1, 'plan' => 1, 'vpn_panel' => 1, 'vpn_inbound' => 1, 'payment_gateway' => 1, 'store_payment_method' => 1, '`order`' => 1, 'payment' => 1, 'vpn_service' => 1, 'wallet' => 1, 'wallet_transaction' => 1];
    private const OFFSETS = ['`user`' => 100000, 'telegram_account' => 150000, 'plan' => 200000, 'vpn_panel' => 250000, 'vpn_inbound' => 260000, 'payment_gateway' => 270000, 'store_payment_method' => 280000, '`order`' => 300000, 'payment' => 400000, 'vpn_service' => 500000, 'wallet' => 600000, 'wallet_transaction' => 700000];

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function idLayouts(): iterable
    {
        yield 'fresh database (ids from 1)' => [self::TABLES];
        yield 'offset ids per table' => [self::OFFSETS];
    }

    /**
     * @param array<string, int> $firstIds
     */
    #[DataProvider('idLayouts')]
    public function testTheWholeFlow(array $firstIds): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($firstIds as $table => $first) {
            $connection->executeStatement('TRUNCATE TABLE '.$table);
            $connection->executeStatement(sprintf('ALTER TABLE %s AUTO_INCREMENT = %d', $table, $first));
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em->clear();

        $this->setUpShop();
        $this->setting(WalletSettings::ENABLED, 'true');
        self::assertSame($firstIds['`user`'], (int) $this->user->getId());
        $settlement = self::getContainer()->get(PaymentSettlementService::class);

        // 1. Top-up of 300,000 through Zibal.
        $topUp = self::getContainer()->get(WalletTopUp::class)->createOrder($this->user, 300000);
        self::assertSame($firstIds['`order`'], (int) $topUp->getId());
        $start = self::getContainer()->get(PaymentStarter::class)->start($topUp, $this->methods[PaymentGatewayType::ZIBAL]);
        $topUpPayment = $start->payment ?? throw new \LogicException();
        self::assertSame($firstIds['payment'], (int) $topUpPayment->getId());
        $credited = $settlement->settle($topUpPayment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z1', amount: '3000000', currency: 'IRR')), 'webhook');
        self::assertSame(SettlementOutcome::CONFIRMED, $credited->outcome, $credited->message);

        $wallet = $connection->fetchAssociative('SELECT * FROM wallet');
        self::assertSame($firstIds['wallet'], (int) $wallet['id']);
        self::assertSame((int) $this->user->getId(), (int) $wallet['user_id']);
        $credit = $connection->fetchAssociative('SELECT * FROM wallet_transaction');
        self::assertSame($firstIds['wallet_transaction'], (int) $credit['id']);
        self::assertSame((int) $wallet['id'], (int) $credit['wallet_id']);
        self::assertSame('topup:payment:'.$topUpPayment->getId(), $credit['idempotency_key']);
        self::assertSame((string) $topUpPayment->getId(), $credit['reference_id']);

        // 2. A new service paid from the wallet; provisioning fails, then the admin rejects it: the debit comes back.
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $this->driver()->failNext();
        $failed = self::getContainer()->get(WalletPayment::class)->pay($this->em->find(Order::class, $order->getId()) ?? throw new \LogicException());
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $failed->outcome, $failed->message);
        $walletPayment = $failed->payment ?? throw new \LogicException();
        self::assertSame($firstIds['payment'] + 1, (int) $walletPayment->getId());
        $debit = $connection->fetchAssociative("SELECT * FROM wallet_transaction WHERE type = 'purchase'");
        self::assertSame('purchase:payment:'.$walletPayment->getId(), $debit['idempotency_key']);
        self::assertSame((string) $walletPayment->getId(), $debit['reference_id']);
        self::assertSame(150000, (int) $connection->fetchOne('SELECT balance FROM wallet'));

        $settlement->reject($walletPayment, 'cannot deliver', 1, null, 'admin', true);
        $reversal = $connection->fetchAssociative("SELECT * FROM wallet_transaction WHERE type = 'reversal'");
        self::assertSame((int) $debit['id'], (int) $reversal['reversal_of_id']);
        self::assertSame(300000, (int) $connection->fetchOne('SELECT balance FROM wallet'));

        // 3. Paid again from the wallet, this time provisioned.
        // A failed settlement clears the entity manager (PaymentSettlementService::rollbackAndReset()): reload.
        $user = $this->em->find(\App\Entity\User::class, $this->user->getId()) ?? throw new \LogicException();
        $plan = $this->em->find(\App\Entity\Plan::class, $this->plan->getId()) ?? throw new \LogicException();
        $second = $this->createOrder($user, $plan, OrderType::NEW_SERVICE, 150000);
        $paid = self::getContainer()->get(WalletPayment::class)->pay($second);
        self::assertSame(SettlementOutcome::CONFIRMED, $paid->outcome, $paid->message);
        self::assertSame((int) $second->getId(), (int) $connection->fetchOne('SELECT order_id FROM vpn_service'));
        self::assertSame(150000, (int) $connection->fetchOne('SELECT balance FROM wallet'));
        self::assertSame([], self::getContainer()->get(WalletReconciler::class)->mismatches());
    }
}
