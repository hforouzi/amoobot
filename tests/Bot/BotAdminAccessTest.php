<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\AdminUser;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\IntegrationTestCase;

/**
 * Bot admin access goes through AdminAccessPolicy: an active AdminUser linked by telegramUserId acts with its role's
 * permissions, TELEGRAM_ADMIN_CHAT_ID stays a super admin (fallback), everyone else gets the existing refusal.
 * Payment alerts reach every active linked admin who may moderate payments, plus the env chat, once each.
 */
final class BotAdminAccessTest extends IntegrationTestCase
{
    private const ENV_ADMIN = '999000'; // TELEGRAM_ADMIN_CHAT_ID in .env.test
    private const SUPER = '7001';
    private const ADMIN = '7002';
    private const SUPPORT = '7003';
    private const INACTIVE = '7004';
    private const STRANGER = '7999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->linkedAdmin('super@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::SUPER);
        $this->linkedAdmin('admin@shop.test', AdminUser::ROLE_ADMIN, self::ADMIN);
        $this->linkedAdmin('support@shop.test', AdminUser::ROLE_SUPPORT, self::SUPPORT);
        $this->linkedAdmin('off@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::INACTIVE, false);
        $this->linkedAdmin('unlinked@shop.test', AdminUser::ROLE_SUPER_ADMIN, null);
    }

    public function testWhoOpensTheAdminMenu(): void
    {
        foreach ([self::ENV_ADMIN => true, self::SUPER => true, self::ADMIN => true, self::SUPPORT => true, self::INACTIVE => false, self::STRANGER => false] as $telegramId => $allowed) {
            $this->http->reset();
            $this->pressButton((string) $telegramId, 'admin_menu');
            self::assertSame($allowed, in_array('منوی مدیریت:', $this->http->telegramTexts(), true), 'admin menu for '.$telegramId);
            self::assertSame(!$allowed, $this->refused(), 'refusal for '.$telegramId);
        }
    }

    public function testTheAdminReplyButtonFollowsThePolicy(): void
    {
        $this->message(self::SUPPORT, '🛠 مدیریت');
        self::assertContains('منوی مدیریت:', $this->http->telegramTexts());

        $this->http->reset();
        $this->message(self::STRANGER, '🛠 مدیریت');
        self::assertNotContains('منوی مدیریت:', $this->http->telegramTexts());
        self::assertContains($this->text('admin.unauthorized'), $this->http->telegramTexts());
    }

    public function testPaymentModerationNeedsPaymentsModerate(): void
    {
        foreach ([self::SUPPORT => false, self::ADMIN => true] as $telegramId => $allowed) {
            $payment = $this->submittedPayment();
            $this->http->reset();
            $this->pressButton((string) $telegramId, 'admin_confirm_payment:'.$payment->getId());
            self::assertSame(PaymentStatus::SUBMITTED, $this->row('payment', (int) $payment->getId())['status'], 'the first press only asks for confirmation');
            // The confirmation callback is checked again (a forged one from Support is refused too).
            $this->pressButton((string) $telegramId, 'admin.p:ak:'.$payment->getId());

            self::assertSame(!$allowed, $this->refused(), 'confirm by '.$telegramId);
            self::assertSame($allowed, PaymentStatus::SUBMITTED !== $this->row('payment', (int) $payment->getId())['status'], 'payment changed by '.$telegramId);
        }

        $payment = $this->submittedPayment();
        $this->pressButton(self::SUPPORT, 'admin_reject_payment:'.$payment->getId());
        self::assertTrue($this->refused());
        self::assertSame(PaymentStatus::SUBMITTED, $this->row('payment', (int) $payment->getId())['status']);

        // Support may look at payments.
        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin_view_payment:'.$payment->getId());
        self::assertFalse($this->refused());
    }

    public function testServiceActionsFollowTheirPermissions(): void
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound());
        $id = (int) $service->getId();

        // Support: views and assists, but changes nothing a customer paid for.
        foreach (['admin_services', 'admin_service_view:'.$id, 'admin_users', 'service_sync_usage:'.$id, 'service_resend_config:'.$id] as $data) {
            $this->http->reset();
            $this->pressButton(self::SUPPORT, $data);
            self::assertFalse($this->refused(), 'support '.$data);
            self::assertNotContains('دسترسی غیرمجاز.', $this->http->telegramTexts(), 'support '.$data);
        }
        foreach (['service_suspend:'.$id, 'service_activate:'.$id, 'service_delete:'.$id, 'service_delete_confirm:'.$id, 'service_extend:'.$id.':30', 'service_add_traffic:'.$id.':10', 'service_reset_usage:'.$id, 'service_renew:'.$id] as $data) {
            $this->http->reset();
            $this->pressButton(self::SUPPORT, $data);
            self::assertTrue($this->refused() || in_array('دسترسی غیرمجاز.', $this->popupsAndTexts(), true), 'support '.$data);
        }
        self::assertSame(VpnServiceStatus::ACTIVE, $this->row('vpn_service', $id)['status']);

        // Admin: may suspend.
        $this->http->reset();
        $this->pressButton(self::ADMIN, 'service_suspend:'.$id);
        self::assertFalse($this->refused());
        $this->em->clear();
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->em->find(VpnService::class, $id)?->getStatus());

        // A stranger: refused.
        $this->http->reset();
        $this->pressButton(self::STRANGER, 'service_activate:'.$id);
        self::assertTrue($this->refused());
    }

    public function testAnInactiveLinkedAdminWinsOverTheEnvFallback(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE admin_user SET telegram_user_id = ? WHERE email = ?', [self::ENV_ADMIN, 'off@shop.test']);

        $this->pressButton(self::ENV_ADMIN, 'admin_menu');
        self::assertTrue($this->refused(), 'a deactivated admin stays out even with the env chat id');
    }

    public function testPaymentAlertsReachEveryModeratorOnce(): void
    {
        // The super admin is also linked to the env chat id: it must get the alert once, not twice.
        $this->linkedAdmin('env-twin@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::ENV_ADMIN);

        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->http->reset();
        self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, amount: '100', currency: 'IRR')), 'test');

        self::assertSame([self::SUPER, self::ADMIN, self::ENV_ADMIN], $this->alertChats('admin_confirm_payment:'.$payment->getId()));
    }

    public function testReceiptNotificationsReachEveryModeratorOnce(): void
    {
        $customer = $this->createUser('5550001');
        $payment = $this->createPayment($this->createOrder($customer, $this->createPlan()), null, PaymentStatus::PENDING);
        $this->http->reset();

        $this->message('5550001', 'TRACK-778899');

        self::assertSame([self::SUPER, self::ADMIN, self::ENV_ADMIN], $this->alertChats('admin_confirm_payment:'.$payment->getId()));
    }

    /**
     * @return list<string> sorted chat ids of the messages carrying this callback
     */
    private function alertChats(string $callbackData): array
    {
        $chats = [];
        foreach ([...$this->http->requests('/sendMessage'), ...$this->http->requests('/sendPhoto')] as $request) {
            $body = $this->body($request);
            if (str_contains((string) json_encode($body), $callbackData)) {
                $chats[] = (string) ($body['chat_id'] ?? '');
            }
        }
        sort($chats);

        return $chats;
    }

    private function refused(): bool
    {
        foreach ($this->http->requests('/answerCallbackQuery') as $request) {
            $body = $this->body($request);
            if (in_array((string) ($body['text'] ?? ''), [$this->text('admin.unauthorized'), 'Unauthorized'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function popupsAndTexts(): array
    {
        $texts = $this->http->telegramTexts();
        foreach ($this->http->requests('/answerCallbackQuery') as $request) {
            $texts[] = (string) ($this->body($request)['text'] ?? '');
        }

        return $texts;
    }

    /**
     * @param array{options: array<string, mixed>} $request
     *
     * @return array<string, mixed>
     */
    private function body(array $request): array
    {
        $body = $request['options']['body'] ?? $request['options']['json'] ?? [];
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                parse_str($body, $decoded);
            }
            $body = $decoded;
        }

        return is_array($body) ? $body : [];
    }

    private function submittedPayment(): Payment
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(100000, $this->createTestInbound()));

        return $this->createPayment($order, null, PaymentStatus::SUBMITTED);
    }

    private function pressButton(string $telegramId, string $data): void
    {
        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb'.random_int(1, PHP_INT_MAX),
                'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'T'.$telegramId],
                'message' => ['message_id' => 1, 'chat' => ['id' => (int) $telegramId, 'type' => 'private']],
                'data' => $data,
            ],
        ]);
    }

    private function message(string $telegramId, string $text): void
    {
        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => random_int(1, 100000),
                'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'T'.$telegramId],
                'chat' => ['id' => (int) $telegramId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ]);
    }

    private function text(string $key): string
    {
        return self::getContainer()->get(BotTextResolver::class)->message($key);
    }

    private function linkedAdmin(string $email, string $role, ?string $telegramUserId, bool $active = true): AdminUser
    {
        $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles([$role])->setIsActive($active)->setTelegramUserId($telegramUserId);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
