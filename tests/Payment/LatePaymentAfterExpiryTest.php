<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\IncompleteOrderExpiryService;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * pending -> expired by the expiry job -> the customer pays anyway: the money must not be ignored.
 */
final class LatePaymentAfterExpiryTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    /**
     * @return array{Order, Payment}
     */
    private function expiredZibalPayment(): array
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('777')->setAuthority('777');
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'), $order->getId()]);
        $this->em->clear();

        self::getContainer()->get(IncompleteOrderExpiryService::class)->expire(false, 24);
        self::assertSame(OrderStatus::EXPIRED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);

        return [$order, $payment];
    }

    private function zibalSaysPaid(): void
    {
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);
    }

    public function testLatePaidCallbackGoesToReviewAndAlertsAdmins(): void
    {
        [$order, $payment] = $this->expiredZibalPayment();
        $this->zibalSaysPaid();
        $this->http->reset();
        $this->zibalSaysPaid();

        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => '777', 'success' => '1', 'status' => '2']);

        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status']);
        self::assertStringStartsWith('paid after expiry', (string) $row['review_reason']);
        self::assertSame('1500000', $row['verified_amount']);
        self::assertSame(0, $this->countServicesForOrder($order), 'not provisioned automatically');
        $alerts = array_filter($this->http->requests('/sendMessage'), static fn (array $r): bool => str_contains((string) ($r['options']['body'] ?? ''), 'admin_confirm_payment:'.$payment->getId()));
        self::assertNotEmpty($alerts, 'admins alerted with an Approve button');
    }

    public function testApproveCreatesTheServiceAsUsual(): void
    {
        [$order, $payment] = $this->expiredZibalPayment();
        $this->zibalSaysPaid();
        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => '777', 'success' => '1', 'status' => '2']);
        $this->em->clear();

        $reloaded = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException('payment missing');
        $result = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($reloaded, 7, null, 'easyadmin_payment_approval');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testReturnPageOfAnExpiredPaymentVerifiesAndShowsReview(): void
    {
        [, $payment] = $this->expiredZibalPayment();
        $this->zibalSaysPaid();

        $this->client->request('GET', PaymentReturnUrl::path($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException()));

        self::assertStringContainsString('توسط پشتیبانی', (string) $this->client->getResponse()->getContent());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testUnpaidVerificationOfAnExpiredPaymentChangesNothing(): void
    {
        [, $payment] = $this->expiredZibalPayment();
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 202, 'message' => 'not paid']);

        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => '777', 'success' => '1', 'status' => '2']);

        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testPaymentRejectedByAnAdminStaysRejected(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('778')->setAuthority('778');
        $this->em->flush();
        self::getContainer()->get(PaymentSettlementService::class)->reject($payment, 'fraud', 1, null, 'test');
        $this->zibalSaysPaid();

        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => '778', 'success' => '1', 'status' => '2']);

        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testPaymentsExpiredBeforeThisChangeAreRecognisedByTheirNote(): void
    {
        [, $payment] = $this->expiredZibalPayment();
        $this->em->getConnection()->executeStatement('UPDATE payment SET confirmation_source = NULL WHERE id = ?', [$payment->getId()]);
        $this->zibalSaysPaid();

        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => '777', 'success' => '1', 'status' => '2']);

        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status']);
    }
}
