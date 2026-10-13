<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\AdminUser;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A second payment for an order that is already fulfilled: review, then a clean close.
 */
final class DuplicatePaymentClosureTest extends IntegrationTestCase
{
    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private static function paid(): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, amount: '1500000', currency: 'IRR'));
    }

    /**
     * @return array{Order, Payment, Payment} order, first (confirmed) payment, duplicate in review
     */
    private function duplicateInReview(): array
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $first = $this->createPayment($order, null, PaymentStatus::PENDING);
        $duplicate = $this->createPayment($order, null, PaymentStatus::PENDING);
        self::assertTrue($this->settlement()->settle($first, self::paid(), 'test')->isConfirmed());
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $this->settlement()->settle($duplicate, self::paid(), 'test')->outcome);

        return [$order, $first, $duplicate];
    }

    private function adminAlertCount(Payment $payment): int
    {
        return count(array_filter($this->http->requests('/sendMessage'), static fn (array $r): bool => str_contains((string) ($r['options']['body'] ?? ''), 'admin_confirm_payment:'.$payment->getId())));
    }

    public function testApproveOnADuplicateIsAnExplicitTerminalAnswerNotAnotherReview(): void
    {
        [$order, , $duplicate] = $this->duplicateInReview();
        $reason = $this->row('payment', (int) $duplicate->getId())['review_reason'];
        $alerts = $this->adminAlertCount($duplicate);

        $result = $this->settlement()->approveByAdmin($duplicate, 1, null, 'test');

        self::assertSame(SettlementOutcome::ORDER_ALREADY_FULFILLED, $result->outcome);
        self::assertStringContainsString('Reject this payment instead', $result->message);
        $row = $this->row('payment', (int) $duplicate->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status'], 'nothing changed');
        self::assertSame($reason, $row['review_reason']);
        self::assertSame($alerts, $this->adminAlertCount($duplicate), 'not re-parked, no new alert');
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testRejectingADuplicateAsRefundedLeavesTheFulfilledOrderAlone(): void
    {
        [$order, $first, $duplicate] = $this->duplicateInReview();

        $result = $this->settlement()->reject($duplicate, 'duplicate', 1, null, 'test', refunded: true);

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome, $result->message);
        $row = $this->row('payment', (int) $duplicate->getId());
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertNotNull($row['refunded_at']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $first->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'بازگردانده شد')), 'customer told about the refund');
    }

    public function testRejectingADuplicateWithoutRefund(): void
    {
        [$order, , $duplicate] = $this->duplicateInReview();

        self::assertSame(SettlementOutcome::REJECTED, $this->settlement()->reject($duplicate, null, 1, null, 'test')->outcome);

        self::assertNull($this->row('payment', (int) $duplicate->getId())['refunded_at']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testRejectingAnUnfulfilledOrdersPaymentStillFailsTheOrder(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $this->settlement()->reject($payment, null, 1, null, 'test');

        self::assertSame(OrderStatus::FAILED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testRetryOfAFailedPaymentWhoseOrderWasFulfilledMeanwhileIsRefused(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $failed = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext();
        $this->settlement()->approveByAdmin($failed, 1, null, 'test');
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException(); // reset by the failed attempt
        $other = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::WAITING_PAYMENT, $order->getId()]);
        $this->em->clear();
        self::assertTrue($this->settlement()->approveByAdmin($this->em->find(Payment::class, $other->getId()) ?? throw new \LogicException(), 1, null, 'test')->isConfirmed());

        $result = $this->settlement()->retryProvisioning($this->em->find(Payment::class, $failed->getId()) ?? throw new \LogicException(), 1, null, 'test');

        self::assertSame(SettlementOutcome::ORDER_ALREADY_FULFILLED, $result->outcome);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $failed->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testEasyAdminRejectRefundedAction(): void
    {
        [$order, , $duplicate] = $this->duplicateInReview();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('admin@shop.test')->setPassword('x')->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        $crawler = $client->request('GET', '/admin/payment-needs-review');
        $approve = $crawler->filter(sprintf('form[action*="/admin/payment/%d/confirm"]', $duplicate->getId()));
        $client->request('POST', (string) $approve->attr('action'));
        $client->followRedirect();
        self::assertStringContainsString('already fulfilled', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/admin/payment-needs-review');
        $form = $crawler->filter(sprintf('form[action*="/admin/payment/%d/reject"][action*="refunded=1"]', $duplicate->getId()));
        self::assertCount(1, $form, 'Reject (refunded) is offered');
        $client->request('POST', (string) $form->attr('action'));

        self::assertNotNull($this->row('payment', (int) $duplicate->getId())['refunded_at']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }
}
