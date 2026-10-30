<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\AdminPaymentAlerter;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Application\StuckPaymentReminder;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Fourth review, item 6: a payment stuck in provisioning_failed, or in review with its wallet debit held, for more than
 * 6 hours is alerted to the admins again, at most once a day per payment (app:automation:run, task "orders").
 */
final class StuckPaymentReminderTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;
    use MiniAppShopFixtures;

    private const PRICE = 150000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, 1_000_000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function reminder(\DateTimeImmutable $now): StuckPaymentReminder
    {
        return new StuckPaymentReminder(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(AdminPaymentAlerter::class), new MockClock($now), new NullLogger());
    }

    private function freshOrder(): Order
    {
        $user = $this->em->find(\App\Entity\User::class, $this->user->getId()) ?? throw new \LogicException();
        $plan = $this->em->find(\App\Entity\Plan::class, $this->plan->getId()) ?? throw new \LogicException();

        return $this->createOrder($user, $plan, OrderType::NEW_SERVICE, self::PRICE);
    }

    /** A wallet payment whose provisioning failed $hoursAgo hours ago (its debit is held). */
    private function failedWalletPayment(int $hoursAgo): Payment
    {
        $this->driver()->failNext(1);
        $result = self::getContainer()->get(WalletPayment::class)->pay($this->freshOrder());
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        $payment = $result->payment ?? throw new \LogicException();
        $this->age($payment, $hoursAgo);

        return $payment;
    }

    /** A Zibal payment, verified, whose provisioning failed $hoursAgo hours ago. */
    private function failedGatewayPayment(int $hoursAgo): Payment
    {
        $method = $this->em->find(StorePaymentMethod::class, $this->methods[PaymentGatewayType::ZIBAL]->getId()) ?? throw new \LogicException();
        $zibal = self::getContainer()->get(PaymentStarter::class)->start($this->freshOrder(), $method)->payment ?? throw new \LogicException();
        $this->driver()->failNext(1);
        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($zibal, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-'.$zibal->getId(), amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        $this->age($zibal, $hoursAgo);

        return $zibal;
    }

    private function age(Payment $payment, int $hoursAgo): void
    {
        $at = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hoursAgo))->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement('UPDATE payment SET provisioning_failed_at = ?, verified_at = ?, created_at = ? WHERE id = ?', [$at, $at, $at, $payment->getId()]);
    }

    /** @return list<string> the reminder alerts sent to the admins since $from */
    private function reminders(int $from = 0): array
    {
        return array_values(array_filter(array_slice($this->http->telegramTexts(), $from), static fn (string $text): bool => str_contains($text, AdminPaymentAlerter::STUCK_REMINDER_TITLE)));
    }

    public function testAPaymentStuckForMoreThanSixHoursIsRemindedAtMostOnceADay(): void
    {
        $stuck = $this->failedGatewayPayment(7);
        $recent = $this->failedGatewayPayment(5);
        $now = new \DateTimeImmutable();

        self::assertSame(['due' => 1, 'reminded' => 1], $this->reminder($now)->remind());
        $sent = $this->reminders();
        self::assertCount(1, $sent);
        self::assertStringContainsString('Payment ID: '.$stuck->getId(), $sent[0]);
        self::assertStringContainsString('Still provisioning_failed after 7 hour(s)', $sent[0]);
        self::assertNotNull($this->row('payment', (int) $stuck->getId())['stuck_reminded_at']);
        self::assertNull($this->row('payment', (int) $recent->getId())['stuck_reminded_at'], '5 hours: not yet');

        // The next runs of the same day: nothing again. The next day: once more (and the other one is due by then).
        self::assertSame(['due' => 0, 'reminded' => 0], $this->reminder($now->modify('+10 minutes'))->remind());
        self::assertSame(['due' => 1, 'reminded' => 1], $this->reminder($now->modify('+2 hours'))->remind(), 'the other one reached 6 hours');
        self::assertSame(['due' => 0, 'reminded' => 0], $this->reminder($now->modify('+23 hours'))->remind());
        self::assertSame(['due' => 1, 'reminded' => 1], $this->reminder($now->modify('+25 hours'))->remind());
        self::assertCount(3, $this->reminders());
    }

    public function testAWalletPaymentInReviewIsRemindedOnlyWhileItsDebitIsHeld(): void
    {
        $held = $this->failedWalletPayment(8);
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'needs_review' WHERE id = ?", [$held->getId()]);
        // A gateway payment in review holds no wallet money: the existing review alert is enough.
        $gateway = $this->failedGatewayPayment(8);
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'needs_review' WHERE id = ?", [$gateway->getId()]);

        self::assertSame(['due' => 1, 'reminded' => 1], $this->reminder(new \DateTimeImmutable())->remind());
        $sent = $this->reminders();
        self::assertCount(1, $sent);
        self::assertStringContainsString('Payment ID: '.$held->getId(), $sent[0]);
        self::assertStringContainsString('wallet debit is held', $sent[0]);

        // Decided (rejected, the debit given back): never reminded again.
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->reject($this->em->find(Payment::class, $held->getId()) ?? throw new \LogicException(), 'refunded', 1, null, 'test_admin', true);
        self::assertSame(['due' => 0, 'reminded' => 0], $this->reminder((new \DateTimeImmutable())->modify('+2 days'))->remind());
    }

    public function testADryRunCountsAndChangesNothing(): void
    {
        $stuck = $this->failedWalletPayment(9);

        self::assertSame(['due' => 1, 'reminded' => 0], $this->reminder(new \DateTimeImmutable())->remind(true));
        self::assertSame([], $this->reminders());
        self::assertNull($this->row('payment', (int) $stuck->getId())['stuck_reminded_at']);
    }

    /** The scheduled job (app:automation:run --only=orders, every 10 minutes) runs it, whatever the expiry toggle. */
    public function testTheOrdersAutomationTaskSendsTheReminder(): void
    {
        $stuck = $this->failedWalletPayment(7);
        $before = count($this->http->telegramTexts());

        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $tester->execute(['--only' => 'orders']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $sent = $this->reminders($before);
        self::assertCount(1, $sent);
        self::assertStringContainsString('Payment ID: '.$stuck->getId(), $sent[0]);
        self::assertConsoleContains('notified: 1', $tester->getDisplay());

        $tester->execute(['--only' => 'orders']);
        self::assertCount(1, $this->reminders($before), 'not again the same day');
    }
}
