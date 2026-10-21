<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\AdminUser;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminPaymentAttentionTest extends IntegrationTestCase
{
    private const ADMIN_CHAT = '999000'; // TELEGRAM_ADMIN_CHAT_ID in .env.test

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    /**
     * @return array{Order, Payment}
     */
    private function paymentInReview(): array
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $verification = new PaymentVerificationResult(success: true, paid: true, amount: '100', currency: 'IRR');
        self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'test');

        return [$order, $payment];
    }

    private function loginAdmin(): AdminUser
    {
        $admin = (new AdminUser())->setEmail('admin@shop.test')->setPassword('x')->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');

        return $admin;
    }

    public function testAlertToAdminsCarriesActionButtons(): void
    {
        [, $payment] = $this->paymentInReview();

        $alerts = array_filter($this->http->requests('/sendMessage'), static fn (array $r): bool => str_contains((string) ($r['options']['body'] ?? ''), 'admin_confirm_payment:'.$payment->getId()));
        self::assertNotEmpty($alerts);
    }

    public function testEasyAdminReviewListApprovesViaPostWithCsrfToken(): void
    {
        [$order, $payment] = $this->paymentInReview();
        $admin = $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/payment-needs-review');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $form = $crawler->filter(sprintf('form[action*="/admin/payment/%d/confirm"]', $payment->getId()));
        self::assertCount(1, $form, 'Approve is rendered as a POST form');
        $action = (string) $form->attr('action');
        self::assertStringContainsString('_token=', $action);

        $this->client->request('GET', $action);
        self::assertSame(405, $this->client->getResponse()->getStatusCode(), 'GET must not approve');

        $this->client->request('POST', (string) preg_replace('/_token=[^&]+/', '_token=forged', $action));
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status'], 'forged token rejected');

        $this->client->request('POST', $action);
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame($admin->getId(), (int) $row['confirmed_by_admin_id']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testProvisioningFailedListOffersRetry(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, null, null, 'test');
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/payment-provisioning-failed');
        $form = $crawler->filter(sprintf('form[action*="/admin/payment/%d/retry"]', $payment->getId()));
        self::assertCount(1, $form);
        $this->client->request('POST', (string) $form->attr('action'));

        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testFlashMessagesFromPanelErrorsAreEscaped(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/payment');
        $form = $crawler->filter(sprintf('form[action*="/admin/payment/%d/confirm"]', $payment->getId()));
        // The panel answers with markup in its error message.
        $this->driver()->failNext(1, '<img src=x onerror=alert(1)>');

        $this->client->request('POST', (string) $form->attr('action'));
        $this->client->followRedirect();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x onerror', $content);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $content);
    }

    public function testBotAdminCanListAndRetryAndOthersCannot(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, null, null, 'test');
        $handler = self::getContainer()->get(TelegramUpdateHandler::class);

        $handler->handle($this->callbackUpdate('1234', 'admin_confirm_payment:'.$payment->getId()));
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $payment->getId())['status'], 'non-admin cannot retry');

        $this->http->reset();
        $handler->handle($this->callbackUpdate(self::ADMIN_CHAT, 'admin_attention_payments'));
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'خطای ساخت سرویس')));

        $handler->handle($this->callbackUpdate(self::ADMIN_CHAT, 'admin_confirm_payment:'.$payment->getId()));
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $payment->getId())['status'], 'asks for confirmation first');
        $handler->handle($this->callbackUpdate(self::ADMIN_CHAT, 'admin.p:ak:'.$payment->getId()));
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame(self::ADMIN_CHAT, $row['confirmed_by_telegram_id']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackUpdate(string $fromId, string $data): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 1_000_000),
                'from' => ['id' => (int) $fromId, 'first_name' => 'T', 'username' => 'u'.$fromId],
                'message' => ['message_id' => 1, 'chat' => ['id' => (int) $fromId, 'type' => 'private']],
                'data' => $data,
            ],
        ];
    }
}
