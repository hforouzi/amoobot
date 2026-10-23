<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Telegram alerts when a payment needs a human: to every admin who may act on it (AdminAccessPolicy recipients:
 * linked admins and TELEGRAM_ADMIN_CHAT_ID, each once). Alerts with approve/reject buttons need PAYMENTS_MODERATE,
 * the others PAYMENTS_VIEW.
 *
 * Sent after the settlement transaction has committed (or was rolled back); failures are logged, never thrown.
 */
class AdminPaymentAlerter
{
    public const WALLET_CREDIT_FAILED_TITLE = '🚨 پرداخت شارژ کیف پول تایید شد اما واریز به کیف پول انجام نشد';

    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly LoggerInterface $logger,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function needsReview(Payment $payment): void
    {
        $this->send($payment, '⚠️ پرداخت نیازمند بررسی', (string) ($payment->getReviewReason() ?? '-'), true);
    }

    public function provisioningFailed(Payment $payment): void
    {
        $this->send($payment, '🚨 پرداخت تایید شد اما ساخت سرویس ناموفق بود', (string) ($payment->getProvisioningError() ?? '-'), true);
    }

    public function overpaid(Payment $payment): void
    {
        $this->send($payment, 'ℹ️ پرداخت بیش از مبلغ سفارش (تایید شد)', sprintf('Verified: %s %s', $payment->getVerifiedAmount() ?? '-', $payment->getVerifiedCurrency() ?? '-'));
    }

    /**
     * The gateway confirmed a wallet top-up but crediting the wallet failed and was rolled back (the payment stays
     * pending, a later verification or an admin approval settles it). Once per payment: the alert is claimed with a
     * conditional UPDATE of payment.wallet_credit_alerted_at, so concurrent or repeated failures alert only once.
     */
    public function walletTopUpCreditFailed(int $paymentId, string $error): void
    {
        try {
            $claimed = $this->entityManager->getConnection()->executeStatement(
                'UPDATE payment SET wallet_credit_alerted_at = ? WHERE id = ? AND wallet_credit_alerted_at IS NULL',
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $paymentId]
            );
            $payment = 1 === $claimed ? $this->entityManager->find(Payment::class, $paymentId) : null;
        } catch (\Throwable $e) {
            $this->logger->error('Wallet top-up credit alert not sent.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return;
        }
        if (!$payment instanceof Payment) {
            return;
        }

        $this->send($payment, self::WALLET_CREDIT_FAILED_TITLE, 'Nothing was changed; the payment is still pending. The next verification retries, or approve it. Error: '.$error, true);
    }

    private function send(Payment $payment, string $title, string $detail, bool $withActions = false): void
    {
        $chats = $this->accessPolicy->telegramRecipients($withActions ? AdminPermission::PAYMENTS_MODERATE : AdminPermission::PAYMENTS_VIEW);
        if ([] === $chats) {
            $this->logger->warning('Admin payment alert not sent: no admin chat (TELEGRAM_ADMIN_CHAT_ID empty and no linked admin).', ['payment_id' => $payment->getId(), 'title' => $title]);

            return;
        }

        $order = $payment->getOrder();
        $text = implode("\n", [
            $title,
            sprintf('Payment ID: %d', $payment->getId() ?? 0),
            sprintf('Order ID: %d (%s)', $order->getId() ?? 0, $order->getType()),
            sprintf('Tracking: %s', $order->getTrackingCode() ?? '-'),
            sprintf('Gateway: %s', $payment->getGatewayType() ?? $payment->getMethod()),
            sprintf('Amount: %d تومان', $payment->getPayableAmount() ?? $payment->getAmount()),
            sprintf('Status: %s', $payment->getStatus()),
            'Detail: '.mb_substr($detail, 0, 1500),
        ]);

        foreach ($chats as $chatId) {
            try {
                // Approve / Retry / Reject buttons; the bot checks the admin's permission again when they are pressed.
                $this->telegramApiClient->sendMessage($chatId, $text, $withActions ? $this->keyboardFactory->adminPaymentAttentionActions((int) $payment->getId(), $payment->getStatus()) : null);
            } catch (\Throwable $e) {
                $this->logger->error('Admin payment alert failed.', ['payment_id' => $payment->getId(), 'chat' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }
}
