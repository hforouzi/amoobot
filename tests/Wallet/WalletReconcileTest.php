<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Application\WalletTopUp;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Review finding I3: app:wallet:reconcile also cross-checks payments against the ledger, class by class, and reports
 * (exit code 1) without fixing anything. Each test builds a real state with the services, then breaks one link the
 * way a bug or a hand edit would.
 */
final class WalletReconcileTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const PRICE = 150000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, 400000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    /**
     * @return array<string, list<int|null>> problem => payment ids
     */
    private function problems(): array
    {
        $found = [];
        foreach (self::getContainer()->get(WalletReconciler::class)->paymentMismatches() as $mismatch) {
            $found[$mismatch['problem']][] = $mismatch['paymentId'];
        }

        return $found;
    }

    private function confirmedTopUp(): Payment
    {
        $order = self::getContainer()->get(WalletTopUp::class)->createOrder($this->user, 50000);
        $payment = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();
        $result = $this->settlement()->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-'.$payment->getId(), amount: '50000', currency: 'IRT')), 'test_webhook');
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);

        return $payment;
    }

    private function openWalletPayment(): Payment
    {
        return self::getContainer()->get(WalletPayment::class)->open($this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE));
    }

    public function testConsistentPaymentsAndLedgerReportNothing(): void
    {
        $this->confirmedTopUp();
        self::getContainer()->get(WalletPayment::class)->pay($this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE));
        $cancelled = $this->openWalletPayment();
        $this->settlement()->cancelByCustomer($cancelled->getOrder());

        self::assertSame([], self::getContainer()->get(WalletReconciler::class)->paymentMismatches());
        self::assertSame(0, $this->runCommand()->getStatusCode());
    }

    public function testAConfirmedTopUpWithoutItsCredit(): void
    {
        $order = self::getContainer()->get(WalletTopUp::class)->createOrder($this->user, 50000);
        $payment = $this->createPayment($order, $this->methods[PaymentGatewayType::ZIBAL]->getGateway(), 'confirmed');

        self::assertSame([WalletReconciler::TOPUP_NOT_CREDITED => [$payment->getId()]], $this->problems());
    }

    public function testATopUpCreditWhosePaymentIsNotConfirmed(): void
    {
        $payment = $this->confirmedTopUp();
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'pending' WHERE id = ?", [$payment->getId()]);

        self::assertSame([WalletReconciler::TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT => [$payment->getId()]], $this->problems());
    }

    public function testAWalletPaymentWithoutItsDebit(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $payment = $this->createPayment($order, null, 'confirmed');
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'wallet', gateway_type = 'wallet' WHERE id = ?", [$payment->getId()]);

        self::assertSame([WalletReconciler::WALLET_PAYMENT_WITHOUT_DEBIT => [$payment->getId()]], $this->problems());
    }

    /** The review's scratch case: a debit stranded on a payment whose method was rewritten, then rejected. */
    public function testARejectedPaymentWhoseDebitWasNeverGivenBack(): void
    {
        $payment = $this->openWalletPayment();
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'zibal', gateway_type = 'zibal', status = 'rejected' WHERE id = ?", [$payment->getId()]);

        self::assertSame([WalletReconciler::DEBIT_NOT_RETURNED => [$payment->getId()]], $this->problems());
    }

    public function testADebitGivenBackWhileItsPaymentIsStillLive(): void
    {
        $payment = $this->openWalletPayment();
        self::getContainer()->get(WalletService::class)->releasePurchase((int) $payment->getId(), WalletActor::system(), 'test');

        self::assertSame([WalletReconciler::REVERSAL_OF_LIVE_PAYMENT => [$payment->getId()]], $this->problems());
    }

    public function testAPurchaseDebitWithoutAPayment(): void
    {
        self::getContainer()->get(WalletService::class)->debit($this->user, 1000, WalletTransactionType::PURCHASE, 'purchase:payment:987654321', WalletActor::system());

        $mismatches = self::getContainer()->get(WalletReconciler::class)->paymentMismatches();

        self::assertCount(1, $mismatches);
        self::assertSame(WalletReconciler::PURCHASE_WITHOUT_PAYMENT, $mismatches[0]['problem']);
        self::assertNull($mismatches[0]['paymentId']);
        self::assertNotNull($mismatches[0]['transactionId']);
    }

    public function testTheCommandReportsEveryClassWithIdsAndExitsWithOne(): void
    {
        $topUp = $this->confirmedTopUp();
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'pending' WHERE id = ?", [$topUp->getId()]);
        $live = $this->openWalletPayment();
        self::getContainer()->get(WalletService::class)->releasePurchase((int) $live->getId(), WalletActor::system(), 'test');

        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString(WalletReconciler::TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT, $display);
        self::assertStringContainsString(WalletReconciler::REVERSAL_OF_LIVE_PAYMENT, $display);
        self::assertStringContainsString('#'.$topUp->getId(), $display);
        self::assertStringContainsString('#'.$live->getId(), $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame('pending', $this->row('payment', (int) $topUp->getId())['status'], 'report only');
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:wallet:reconcile'));
        $tester->execute([]);

        return $tester;
    }
}
