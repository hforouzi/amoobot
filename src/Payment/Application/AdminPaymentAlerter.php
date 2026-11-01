<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
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
    public const STUCK_REMINDER_TITLE = '⏰ یادآوری: این پرداخت هنوز منتظر تصمیم مدیر است';
    public const DELIVERY_FAILED_TITLE = '📭 پیام تحویل سرویس به مشتری نرسید';
    public const SETTLEMENT_INTERRUPTED_TITLE = '🚨 ثبت پرداخت با خطای پایگاه داده قطع شد؛ وضعیت پرداخت را بررسی کنید';

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

    /**
     * StuckPaymentReminder: the payment is still provisioning_failed (or in review with its wallet debit held) after
     * $hours hours. Sent again at most once a day.
     */
    public function stuckReminder(Payment $payment, int $hours, bool $walletDebitHeld): void
    {
        $this->send($payment, self::STUCK_REMINDER_TITLE, sprintf(
            'Still %s after %d hour(s)%s. Retry / approve it, or reject it (a rejection gives a held wallet debit back). Reason: %s',
            $payment->getStatus(),
            $hours,
            $walletDebitHeld ? '; the customer\'s wallet debit is held' : '',
            (string) ($payment->getProvisioningError() ?? $payment->getReviewReason() ?? '-')
        ), true);
    }

    /**
     * A new service's delivery message ("service ready", with its links) did not fully reach the customer after
     * PaymentDeliveryLog::MAX_ATTEMPTS attempts (M2e E1 review R3). Once per order: only the process that moved the
     * delivery to FAILED calls it. No Telegram id, link or error text in it.
     */
    public function deliveryFailed(Payment $payment, int $attempts): void
    {
        $this->send($payment, self::DELIVERY_FAILED_TITLE, sprintf(
            'The service was created, but its delivery message was not accepted by Telegram after %d attempt(s) (the customer may have blocked the bot). It is not sent again: give the customer the service\'s links yourself.',
            $attempts
        ));
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
            if ($payment instanceof Payment) {
                // The status in the database, never a stale in-memory copy.
                $this->entityManager->refresh($payment);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Wallet top-up credit alert not sent.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return;
        }
        if (!$payment instanceof Payment) {
            return;
        }

        // Its real status (pending / submitted / in review): only a payment that is not in review is retried by the
        // next verification.
        $next = PaymentStatus::NEEDS_REVIEW === $payment->getStatus() ? 'It is in review: approve it again or reject it.' : 'The next verification retries it, or approve it.';
        $this->send($payment, self::WALLET_CREDIT_FAILED_TITLE, sprintf('Nothing was changed; the payment is still %s. %s Error: %s', $payment->getStatus(), $next, $error), true);
    }

    /**
     * A settlement failed AND rolling it back failed (e.g. after a deadlock): the database connection was closed so no
     * half-known transaction state survives. The payment is reloaded from the database (its status there is what the
     * alert shows); a payment still pending may hold a wallet debit, so the admins are told to decide it.
     */
    public function settlementInterrupted(int $paymentId, string $error): void
    {
        try {
            $payment = $this->entityManager->find(Payment::class, $paymentId);
            if ($payment instanceof Payment) {
                $this->entityManager->refresh($payment);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Settlement interruption alert not sent.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return;
        }
        if (!$payment instanceof Payment) {
            return;
        }

        $this->send($payment, self::SETTLEMENT_INTERRUPTED_TITLE, 'The settlement was rolled back after a database error and its rollback failed; nothing of it was kept. Check this payment (a pending wallet payment still holds its debit): approve / retry it, or reject it to give the money back. Error: '.$error, true);
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
