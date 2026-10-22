<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells the admins who moderate payments that a customer sent a card-to-card receipt (photo or tracking code),
 * with the order summary and approve/reject buttons. Used by the bot and the Mini App alike.
 */
final class PaymentReceiptAdminNotifier
{
    public const KIND_RECEIPT_PHOTO = 'receipt_photo';
    public const KIND_TRACKING_CODE = 'tracking_code';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
    ) {
    }

    /**
     * @param string $kind KIND_RECEIPT_PHOTO (sends the photo itself) or KIND_TRACKING_CODE
     */
    public function notify(Payment $payment, string $kind): void
    {
        $chats = $this->accessPolicy->telegramRecipients(AdminPermission::PAYMENTS_MODERATE);
        if ([] === $chats) {
            return;
        }

        $order = $payment->getOrder();
        $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
        $meta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $isRenewal = OrderType::RENEWAL === $order->getType();
        $isAddTraffic = OrderType::ADD_TRAFFIC === $order->getType();
        $durationText = true === ($meta['unlimitedDuration'] ?? false)
            ? 'نامحدود'
            : sprintf('%s روز', (string) ($meta['durationDays'] ?? '-'));
        $customSummary = true === ($meta['custom'] ?? false)
            ? sprintf("Custom: yes\nUsername: %s\nTraffic: %s GB\nDuration: %s\nCalculated amount: %s\n", (string) ($meta['finalUsername'] ?? '-'), (string) ($meta['trafficGb'] ?? '-'), $durationText, (string) ($meta['calculatedAmount'] ?? '-'))
            : "Custom: no\n";
        $priceSnapshot = is_array($meta['priceSnapshot'] ?? null) ? $meta['priceSnapshot'] : [];
        $snapshotLines = sprintf(
            "Base amount: %s\nGlobal discount: %s\nDiscount code: %s\nDiscount code amount: %s\nFinal amount: %s",
            (string) ($priceSnapshot['baseAmount'] ?? '-'),
            (string) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (string) ($priceSnapshot['discountCodeAmount'] ?? 0),
            (string) ($priceSnapshot['finalAmount'] ?? $payment->getAmount())
        );
        if ($isAddTraffic) {
            $message = sprintf(
                "پرداخت خرید حجم اضافه\n\nPayment ID: %d\nOrder ID: %d\nکد پیگیری: %s\nOrder type: add_traffic\nService ID: %s\nUser: %s\nRequested traffic: %s GB\nAmount: %d تومان\n%s\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                (string) ($meta['trafficGb'] ?? '-'),
                $payment->getAmount(),
                $snapshotLines,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        } elseif ($isRenewal) {
            $renewalPolicy = is_array($meta['renewalPolicy'] ?? null) ? $meta['renewalPolicy'] : [];
            $message = sprintf(
                "پرداخت تمدید سرویس\n\nPayment ID: %d\nOrder ID: %d\nکد پیگیری: %s\nOrder type: renewal\nService ID: %s\nUser: %s\nAmount: %d تومان\nRenewal duration: %s\nRenewal traffic: %s GB\nPolicy traffic carry: %s\nPolicy days carry: %s\nPrice source: %s\n%s\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                $payment->getAmount(),
                $durationText,
                (string) ($meta['trafficGb'] ?? '-'),
                true === ($renewalPolicy['carryRemainingTraffic'] ?? true) ? 'yes' : 'no',
                true === ($renewalPolicy['carryRemainingDays'] ?? true) ? 'yes' : 'no',
                (string) ($priceSnapshot['planPriceSource'] ?? 'current_plan'),
                $snapshotLines,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        } else {
            $message = sprintf(
                "پرداخت جدید ثبت شد\n\nPayment ID: %d\nOrder ID: %d\nکد پیگیری: %s\nUser: %s\nPlan: %s\nAmount: %d تومان\n%s%s\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                $this->formatTelegramIdentity($telegramAccount),
                ($order->getPlan()?->getTitle() ?? '-'),
                $payment->getAmount(),
                $customSummary,
                $snapshotLines,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        }

        if ('receipt_photo' === $kind) {
            $receiptFileId = (string) ($payment->getReceiptFileId() ?? '');
            if ('' !== $receiptFileId) {
                foreach ($chats as $chat) {
                    $this->telegramApiClient->sendPhoto($chat, $receiptFileId, $message, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));
                }
                $this->debugLog(sprintf('notify_admin_sent_receipt_photo payment_id=%d', $payment->getId()));

                return;
            }

            $this->debugLog(sprintf('notify_admin_missing_receipt_file payment_id=%d', $payment->getId()));
        }

        foreach ($chats as $chat) {
            $this->telegramApiClient->sendMessage($chat, $message, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));
        }
    }

    private function formatTelegramIdentity(?TelegramAccount $telegramAccount): string
    {
        if (!$telegramAccount instanceof TelegramAccount) {
            return '@- / -';
        }

        return sprintf('@%s / %s', $telegramAccount->getUsername() ?: '-', $telegramAccount->getTelegramId());
    }

    private function debugLog(string $message): void
    {
        error_log('[TelegramUpdateHandler] '.$message);
    }
}
