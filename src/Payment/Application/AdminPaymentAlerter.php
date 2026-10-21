<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use Psr\Log\LoggerInterface;

/**
 * Telegram alerts when a payment needs a human: to every admin who may act on it (AdminAccessPolicy recipients:
 * linked admins and TELEGRAM_ADMIN_CHAT_ID, each once). Alerts with approve/reject buttons need PAYMENTS_MODERATE,
 * the others PAYMENTS_VIEW.
 *
 * Sent after the settlement transaction has committed; failures are logged, never thrown.
 */
class AdminPaymentAlerter
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly LoggerInterface $logger,
        private readonly AdminAccessPolicy $accessPolicy,
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
