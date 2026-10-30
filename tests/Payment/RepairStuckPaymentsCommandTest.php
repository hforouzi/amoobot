<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairStuckPaymentsCommandTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private const GB = 1073741824;

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:payments:repair-stuck'));
        $tester->execute($options);
        $this->em->clear();

        return $tester;
    }

    /**
     * A payment the old code confirmed without fulfilling the order.
     */
    private function stuckPayment(Order $order, string $confirmedAgo = '-2 hours'): Payment
    {
        $payment = $this->createPayment($order, null, PaymentStatus::CONFIRMED);
        $this->em->getConnection()->executeStatement('UPDATE payment SET confirmed_at = ? WHERE id = ?', [(new \DateTimeImmutable($confirmedAgo))->format('Y-m-d H:i:s'), $payment->getId()]);

        return $payment;
    }

    private function newServiceOrder(): Order
    {
        return $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
    }

    /**
     * @return array{Order, VpnService}
     */
    private function renewalOrder(string $type = OrderType::RENEWAL): array
    {
        $inbound = $this->createTestInbound();
        $user = $this->createUser();
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(150000, $inbound), $type, null, $service);

        return [$order, $service];
    }

    private function panelShows(VpnService $service, string $expiryShift = '+0 seconds', int $limitGb = 50): void
    {
        $this->driver()->usage = new VpnUsage(
            trafficUsedGb: 0,
            trafficLimitGb: $limitGb,
            usedBytes: 0,
            totalBytes: $limitGb * self::GB,
            expiresAt: $service->getExpiresAt()?->modify($expiryShift),
        );
    }

    public function testDryRunIsTheDefaultAndChangesNothing(): void
    {
        $payment = $this->stuckPayment($this->newServiceOrder());

        $tester = $this->runCommand();

        self::assertConsoleContains('would mark', $tester->getDisplay());
        self::assertConsoleContains('new_service_not_provisioned', $tester->getDisplay());
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testApplyMovesANewServicePaymentToTheRetryListAndRetryProvisionsIt(): void
    {
        $order = $this->newServiceOrder();
        $payment = $this->stuckPayment($order);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $row['status']);
        self::assertStringContainsString('repair_stuck_command', (string) $row['provisioning_error']);
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(0, $this->driver()->createCalls, 'the command itself never provisions');

        $reloaded = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException('payment missing');
        $retry = self::getContainer()->get(PaymentSettlementService::class)->retryProvisioning($reloaded, 1, null, 'test');
        self::assertTrue($retry->isConfirmed(), $retry->message);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testFulfilledAndRecentPaymentsAreLeftAlone(): void
    {
        $user = $this->createUser();
        $fulfilledOrder = $this->createOrder($user, $this->createPlan());
        $fulfilled = $this->stuckPayment($fulfilledOrder);
        $service = $this->createActiveService($user);
        $service->setOrder($fulfilledOrder);
        $this->em->flush();
        $recent = $this->stuckPayment($this->newServiceOrder(), '-1 minute');

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('No stuck confirmed payments found', $tester->getDisplay());
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $fulfilled->getId())['status']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $recent->getId())['status']);
    }

    public function testProvisionedOrderWithoutServiceNeedsAnExplicitFlag(): void
    {
        $order = $this->newServiceOrder();
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ?, provisioned_at = NOW() WHERE id = ?', [OrderStatus::PROVISIONED, $order->getId()]);
        $payment = $this->stuckPayment($order);

        $this->runCommand(['--apply' => true]);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status'], 'service may have been deleted by an admin');

        $this->runCommand(['--apply' => true, '--include-service-missing' => true]);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testRenewalIsMarkedOnlyWhenThePanelStillShowsTheOldState(): void
    {
        [$order, $service] = $this->renewalOrder();
        $payment = $this->stuckPayment($order);
        $this->panelShows($service, '+30 minutes'); // equal to the database within the sync tolerance

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('renewal_not_applied', $tester->getDisplay());
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $row['status']);
        self::assertStringContainsString('panel: expires', (string) $row['provisioning_error'], 'panel snapshot recorded');
        self::assertSame(0, $this->driver()->renewCalls);
    }

    /**
     * @return iterable<string, array{string, string, int, bool}>
     */
    public static function renewalsThatMustNotBeRetried(): iterable
    {
        yield 'renewal: panel expiry later' => [OrderType::RENEWAL, '+30 days', 50, false];
        yield 'renewal: panel traffic higher' => [OrderType::RENEWAL, '+0 seconds', 100, false];
        yield 'renewal: panel unreachable' => [OrderType::RENEWAL, '+0 seconds', 50, true];
        yield 'add traffic: panel traffic higher' => [OrderType::ADD_TRAFFIC, '+0 seconds', 60, false];
    }

    #[DataProvider('renewalsThatMustNotBeRetried')]
    public function testRenewalThatMayAlreadyBeOnThePanelIsNeverMarked(string $type, string $expiryShift, int $limitGb, bool $panelDown): void
    {
        [$order, $service] = $this->renewalOrder($type);
        $payment = $this->stuckPayment($order);
        $this->panelShows($service, $expiryShift, $limitGb);
        $this->driver()->usageFails = $panelDown;

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('skip (manual)', $tester->getDisplay());
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->driver()->renewCalls);
    }
}
