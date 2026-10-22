<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AdminUser;
use App\Entity\MiniAppSession;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\VpnService;
use App\MiniApp\Admin\AdminConfirmTokens;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;

/**
 * The Mini App admin cast: the TELEGRAM_ADMIN_CHAT_ID super admin, linked ADMIN and SUPPORT admins, a deactivated
 * admin and a plain customer, plus a customer with a card-to-card payment waiting for review and an active service.
 * For IntegrationTestCase subclasses using BotAdminTestTrait and MiniAppTestTrait.
 */
trait MiniAppAdminFixtures
{
    protected const SUPER = '999000';
    protected const ADMIN = '8402';
    protected const SUPPORT = '8401';
    protected const INACTIVE = '8403';
    protected const CUSTOMER_ONLY = '8404';
    /** Real Telegram ids have 5+ digits (the customer search matches an id exactly only then). */
    protected const BUYER = '840501';
    /** A translation key left in a rendered page. */
    protected const RAW_KEY = '/(?:admin|area|nav|order|service|money)\.[a-z_]+(?:\.[a-z_]+)*/';

    protected AdminUser $adminUser;
    protected AdminUser $supportUser;
    protected User $buyer;
    protected Payment $payment;
    protected VpnService $service;

    protected function setUpAdminCast(): void
    {
        $connection = $this->em->getConnection();
        foreach (['mini_app_session', 'checkout_request', 'admin_audit_log', 'admin_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $this->adminUser = $this->linkedAdmin('admin@example.test', AdminUser::ROLE_ADMIN, self::ADMIN);
        $this->supportUser = $this->linkedAdmin('support@example.test', AdminUser::ROLE_SUPPORT, self::SUPPORT);
        $this->linkedAdmin('gone@example.test', AdminUser::ROLE_ADMIN, self::INACTIVE, false);

        $this->buyer = $this->createUser(self::BUYER);
        $inbound = $this->createTestInbound();
        $plan = $this->createPlan(150000, $inbound);
        $gateway = $this->createGateway(PaymentGatewayType::MANUAL_CARD, [], 'IRT');
        $this->payment = $this->createPayment($this->createOrder($this->buyer, $plan)->setTrackingCode('TRK8405AA'), $gateway, PaymentStatus::SUBMITTED);
        $this->payment->setReceiptFileId('AgAD-receipt');
        $this->service = $this->createActiveService($this->buyer, $inbound);
        $this->em->flush();
    }

    /**
     * A JSON POST with the confirm token the server asks for (the app shows Telegram's dialog in between): the first
     * call must answer 428 with a token, the second carries it.
     *
     * @param array{token: string, csrf: string} $session
     * @param array<string, mixed>               $body
     *
     * @return array<string, mixed>
     */
    protected function confirmedPost(string $path, array $session, array $body = [], ?string $key = null): array
    {
        $key ??= self::newKey();
        $first = $this->appPostJson($path, $session, $body, $key);
        self::assertSame(428, $this->httpStatus(), $path.': '.json_encode($first));
        self::assertSame('confirm_required', $first['error'] ?? null);

        return $this->postWithConfirm($path, $session, $body, $key, (string) $first['confirm']['token']);
    }

    /**
     * @param array{token: string, csrf: string} $session
     * @param array<string, mixed>               $body
     *
     * @return array<string, mixed>
     */
    protected function postWithConfirm(string $path, array $session, array $body, string $key, string $confirmToken): array
    {
        $this->client->request('POST', $path, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$session['token'],
            'HTTP_X_CSRF_TOKEN' => $session['csrf'],
            'HTTP_IDEMPOTENCY_KEY' => $key,
            'HTTP_'.strtoupper(str_replace('-', '_', AdminConfirmTokens::HEADER)) => $confirmToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /** Moves the Telegram user's sessions back in time (as if signed in that long ago). */
    protected function ageSessions(string $telegramId, int $seconds): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE mini_app_session SET created_at = DATE_SUB(created_at, INTERVAL '.$seconds.' SECOND) WHERE telegram_user_id = ?',
            [$telegramId]
        );
        $this->em->clear();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function auditRows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM admin_audit_log ORDER BY id');
    }

    protected function sessionRow(string $telegramId): MiniAppSession
    {
        $session = $this->em->getRepository(MiniAppSession::class)->findOneBy(['telegramUserId' => $telegramId], ['id' => 'DESC']);
        self::assertInstanceOf(MiniAppSession::class, $session);

        return $session;
    }
}
