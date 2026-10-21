<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Security\AdminPermission;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: pending / needs-attention payment lists, payment detail with receipt, approve (or retry provisioning),
 * reject. Permissions are checked by AdminBotHandler before these run.
 */
final class AdminPaymentsHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly BotTextResolver $botTextResolver,
        private readonly PaymentSettlementService $paymentSettlementService,
        private readonly AdminAuditLogger $auditLogger,
        private readonly AdminBotReplies $replies,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'p';
    }

    /**
     * pend:<page>, att:<page>, v:<id> (view); ap / rj / rf:<id> ask to approve / reject / reject as refunded,
     * ak / rk / fk:<id> do it.
     */
    public function permissions(string $verb): array
    {
        return match ($verb) {
            'pend', 'att', 'v' => [AdminPermission::PAYMENTS_VIEW],
            'ap', 'rj', 'rf', 'ak', 'rk', 'fk' => [AdminPermission::PAYMENTS_MODERATE],
            default => [],
        };
    }

    public function handle(AdminRequest $request): void
    {
        $id = $request->intArg(0);
        match ($request->verb) {
            'pend' => $this->pending($request->chatId, $request->callbackId, $id),
            'att' => $this->attention($request->chatId, $request->admin, $request->callbackId, $id),
            'v' => $this->view($request->chatId, $id, $request->admin, $request->callbackId),
            'ap', 'rj', 'rf' => $this->ask($request, $id),
            'ak' => $this->confirm($request->chatId, $id, $request->callbackId, $request->admin),
            'rk' => $this->reject($request->chatId, $id, $request->callbackId, $request->admin),
            'fk' => $this->reject($request->chatId, $id, $request->callbackId, $request->admin, true),
            default => null,
        };
    }

    /** The confirmation step before approving / rejecting a payment. */
    private function ask(AdminRequest $request, int $paymentId): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.p:pend:0');

            return;
        }
        $variables = ['id' => $paymentId, 'amount' => AdminScreen::money((int) ($payment->getPayableAmount() ?? $payment->getAmount())), 'user' => $this->replies->identity($payment->getOrder()->getUser()->getTelegramAccount())];
        [$key, $confirm] = match ($request->verb) {
            'ap' => [PaymentStatus::PROVISIONING_FAILED === $payment->getStatus() ? 'admin.payments.confirm_retry' : 'admin.payments.confirm_approve', 'admin.p:ak:'],
            'rj' => ['admin.payments.confirm_reject', 'admin.p:rk:'],
            default => ['admin.payments.confirm_refunded', 'admin.p:fk:'],
        };
        $this->screen->confirm($request, $this->screen->text($key, $variables), $confirm.$paymentId, 'admin.p:v:'.$paymentId);
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }

    /** Approve / reject buttons only for admins who may use them (the callbacks are checked again). */
    private function canModerate(?AdminActor $admin): bool
    {
        return $this->accessPolicy->can($admin, AdminPermission::PAYMENTS_MODERATE);
    }

    /**
     * Payments that need a human: NEEDS_REVIEW (amount/currency mismatch, duplicate) and
     * PROVISIONING_FAILED (paid, service not created yet). One message per payment with its actions.
     */
    public function attention(string $chatId, ?AdminActor $admin, ?string $callbackId = null, int $page = 0): void
    {
        $criteria = ['status' => [PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED]];
        [$page, $pages] = $this->screen->page($page, $this->entityManager->getRepository(Payment::class)->count($criteria));
        $payments = $this->entityManager->getRepository(Payment::class)->findBy($criteria, ['id' => 'ASC'], AdminScreen::PAGE_SIZE, $page * AdminScreen::PAGE_SIZE);

        if ([] === $payments) {
            $this->replies->popup($chatId, $callbackId, 'پرداختی برای رسیدگی وجود ندارد.', 'popup_no_attention_payments');

            return;
        }

        $this->replies->ack($callbackId);
        foreach ($payments as $payment) {
            $order = $payment->getOrder();
            $needsReview = PaymentStatus::NEEDS_REVIEW === $payment->getStatus();
            $text = implode("
", [
                sprintf('%s پرداخت #%d | سفارش #%d (%s)', $needsReview ? '⚠️' : '🚨', $payment->getId() ?? 0, $order->getId() ?? 0, $this->replies->orderType($order->getType())),
                sprintf('کاربر: %s', $this->replies->identity($order->getUser()->getTelegramAccount())),
                sprintf('مبلغ سفارش: %d تومان | درگاه: %s', $payment->getPayableAmount() ?? $payment->getAmount(), $payment->getGatewayType() ?? $payment->getMethod()),
                sprintf('گزارش درگاه: %s %s', $payment->getVerifiedAmount() ?? '-', $payment->getVerifiedCurrency() ?? ''),
                $needsReview
                    ? 'علت بررسی: '.mb_substr((string) ($payment->getReviewReason() ?? '-'), 0, 500)
                    : sprintf('خطای ساخت سرویس (%d تلاش): %s', $payment->getProvisioningAttempts(), mb_substr((string) ($payment->getProvisioningError() ?? '-'), 0, 500)),
            ]);
            $this->telegramApiClient->sendMessage($chatId, $text, $this->canModerate($admin) ? $this->keyboardFactory->adminPaymentAttentionActions((int) $payment->getId(), $payment->getStatus()) : $this->keyboardFactory->backToAdminMenu());
        }
        if ($pages > 1) {
            $this->telegramApiClient->sendMessage($chatId, $this->screen->pageInfo($page, $pages), $this->screen->listKeyboard([], $this->screen->pagerRow($page, $pages, 'admin.p:att:%d'), 'admin.menu'));
        }
    }

    public function pending(string $chatId, ?string $callbackId = null, int $page = 0): void
    {
        $criteria = ['status' => [PaymentStatus::SUBMITTED, PaymentStatus::PENDING]];
        [$page, $pages] = $this->screen->page($page, $this->entityManager->getRepository(Payment::class)->count($criteria));
        $payments = $this->entityManager->getRepository(Payment::class)->findBy($criteria, ['id' => 'DESC'], AdminScreen::PAGE_SIZE, $page * AdminScreen::PAGE_SIZE);

        if ([] === $payments) {
            $this->replies->popup($chatId, $callbackId, 'پرداخت در انتظاری وجود ندارد.', 'popup_no_pending_payments');

            return;
        }

        $lines = ["پرداختهای در انتظار:\n"];
        $paymentIds = [];
        foreach ($payments as $payment) {
            $paymentIds[] = (int) $payment->getId();
            $order = $payment->getOrder();
            $telegramAccount = $order->getUser()->getTelegramAccount();
            $tracking = $payment->getTrackingCode() ?: '-';
            $meta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            $isRenewal = OrderType::RENEWAL === $order->getType();
            $isAddTraffic = OrderType::ADD_TRAFFIC === $order->getType();
            $renewalSuffix = '';
            if ($isRenewal) {
                $duration = true === ($meta['unlimitedDuration'] ?? false)
                    ? 'نامحدود'
                    : ((string) ($meta['durationDays'] ?? '-').' روز');
                $renewalSuffix = sprintf(
                    "\nتمدید سرویس #%s | حجم: %sGB | مدت: %s",
                    (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                    (string) ($meta['trafficGb'] ?? '-'),
                    $duration
                );
            }
            $addTrafficSuffix = '';
            if ($isAddTraffic) {
                $addTrafficSuffix = sprintf(
                    "\nپرداخت خرید حجم اضافه | سرویس #%s | حجم درخواستی: %sGB",
                    (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                    (string) ($meta['trafficGb'] ?? '-')
                );
            }
            $lines[] = sprintf(
                "#%d | سفارش #%d\nکد پیگیری سفارش: %s\nکاربر: %s\nنوع سفارش: %s\nپلن: %s\nمبلغ: %d\nوضعیت: %s\nکد پیگیری پرداخت: %s%s%s\n",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                $this->replies->identity($telegramAccount),
                $order->getType(),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $tracking,
                $renewalSuffix,
                $addTrafficSuffix
            );
        }

        $this->replies->ack($callbackId);
        $keyboard = $this->keyboardFactory->adminPendingPayments($paymentIds);
        $pager = $this->screen->pagerRow($page, $pages, 'admin.p:pend:%d');
        if ([] !== $pager) {
            array_splice($keyboard['inline_keyboard'], -1, 0, [$pager]);
            $lines[] = $this->screen->pageInfo($page, $pages);
        }
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    public function view(string $chatId, int $paymentId, ?AdminActor $admin, ?string $callbackId = null): void
    {
        $this->replies->ack($callbackId);
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->replies->ack($callbackId);
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('payment.failed'));

            return;
        }

        $order = $payment->getOrder();
        $telegramAccount = $order->getUser()->getTelegramAccount();
        $customMeta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $isRenewal = OrderType::RENEWAL === $order->getType();
        $isAddTraffic = OrderType::ADD_TRAFFIC === $order->getType();
        $durationText = true === ($customMeta['unlimitedDuration'] ?? false)
            ? 'نامحدود'
            : (string) ($customMeta['durationDays'] ?? '-');
        $customSummary = true === ($customMeta['custom'] ?? false)
            ? sprintf(
                "Custom: yes\nUsername: %s\nTraffic: %s GB\nDuration: %s\nCalculated amount: %s",
                (string) ($customMeta['finalUsername'] ?? '-'),
                (string) ($customMeta['trafficGb'] ?? '-'),
                $durationText,
                (string) ($customMeta['calculatedAmount'] ?? '-')
            )
            : "Custom: no";
        $priceSnapshot = is_array($customMeta['priceSnapshot'] ?? null) ? $customMeta['priceSnapshot'] : [];
        $snapshotLines = sprintf(
            "Base amount: %s\nGlobal discount: %s\nDiscount code: %s\nDiscount code amount: %s\nFinal amount: %s",
            (string) ($priceSnapshot['baseAmount'] ?? '-'),
            (string) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (string) ($priceSnapshot['discountCodeAmount'] ?? 0),
            (string) ($priceSnapshot['finalAmount'] ?? $payment->getAmount())
        );
        if ($isAddTraffic) {
            $detail = sprintf(
                "پرداخت خرید حجم اضافه\nOrder type: add_traffic\nPayment ID: %d\nOrder ID: %d\nOrder Tracking: %s\nService ID: %s\nUser: %s\nRequested traffic: %s GB\nAmount: %d تومان\n%s\nStatus: %s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->replies->identity($telegramAccount),
                (string) ($customMeta['trafficGb'] ?? '-'),
                $payment->getAmount(),
                $snapshotLines,
                $payment->getStatus(),
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        } elseif ($isRenewal) {
            $renewalPolicy = is_array($customMeta['renewalPolicy'] ?? null) ? $customMeta['renewalPolicy'] : [];
            $detail = sprintf(
                "پرداخت تمدید سرویس\nOrder type: renewal\nPayment ID: %d\nOrder ID: %d\nOrder Tracking: %s\nService ID: %s\nUser: %s\nAmount: %d تومان\nDuration: %s\nTraffic: %s GB\nPolicy traffic carry: %s\nPolicy days carry: %s\nPrice source: %s\n%s\nStatus: %s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->replies->identity($telegramAccount),
                $payment->getAmount(),
                $durationText,
                (string) ($customMeta['trafficGb'] ?? '-'),
                true === ($renewalPolicy['carryRemainingTraffic'] ?? true) ? 'yes' : 'no',
                true === ($renewalPolicy['carryRemainingDays'] ?? true) ? 'yes' : 'no',
                (string) ($priceSnapshot['planPriceSource'] ?? 'current_plan'),
                $snapshotLines,
                $payment->getStatus(),
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        } else {
            $detail = sprintf(
                "Payment ID: %d\nOrder ID: %d\nOrder Tracking: %s\nUser: %s\nPlan: %s\nAmount: %d تومان\nStatus: %s\n%s\n%s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                $this->replies->identity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $customSummary,
                $snapshotLines,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        }
        $attention = in_array($payment->getStatus(), [PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true);
        $actions = match (true) {
            !$this->canModerate($admin) => $this->keyboardFactory->backToAdminMenu(),
            $attention => $this->keyboardFactory->adminPaymentAttentionActions((int) $payment->getId(), $payment->getStatus()),
            default => $this->keyboardFactory->adminPaymentActions((int) $payment->getId()),
        };
        $this->telegramApiClient->sendMessage($chatId, $detail, $actions);

        $receiptFileId = (string) ($payment->getReceiptFileId() ?? '');
        if ('' === $receiptFileId) {
            $this->replies->log(sprintf('admin_view_payment_missing_receipt_file payment_id=%d', $paymentId));

            return;
        }

        $caption = $isAddTraffic
            ? sprintf(
                "رسید پرداخت خرید حجم اضافه\nPayment ID: %d\nOrder ID: %d\nOrder Tracking: %s\nService ID: %s\nUser: %s\nRequested traffic: %s GB\nAmount: %d تومان",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->replies->identity($telegramAccount),
                (string) ($customMeta['trafficGb'] ?? '-'),
                $payment->getAmount()
            )
            : ($isRenewal
            ? sprintf(
                "رسید پرداخت تمدید سرویس\nPayment ID: %d\nOrder ID: %d\nOrder Tracking: %s\nService ID: %s\nUser: %s\nAmount: %d تومان",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->replies->identity($telegramAccount),
                $payment->getAmount()
            )
            : sprintf(
                "رسید پرداخت\nPayment ID: %d\nOrder ID: %d\nOrder Tracking: %s\nUser: %s\nPlan: %s\nAmount: %d تومان",
                $payment->getId(),
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                $this->replies->identity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount()
            ));

        $this->telegramApiClient->sendPhoto($chatId, $receiptFileId, $caption, $actions);
        $this->replies->log(sprintf('admin_view_payment_sent_receipt_photo payment_id=%d', $paymentId));
    }

    public function confirm(string $chatId, int $paymentId, ?string $callbackId = null, ?AdminActor $admin = null): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->replies->ack($callbackId);
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('payment.failed'));

            return;
        }

        // On a PROVISIONING_FAILED payment "confirm" means: retry fulfilment (already verified).
        $retry = PaymentStatus::PROVISIONING_FAILED === $payment->getStatus();
        $result = $retry
            ? $this->paymentSettlementService->retryProvisioning($payment, $admin?->adminUserId, $chatId, 'telegram_retry_provisioning')
            : $this->paymentSettlementService->approveByAdmin($payment, $admin?->adminUserId, $chatId, 'telegram_payment_approval');
        $this->auditLogger->record($retry ? 'payment.retry' : 'payment.approve', 'Payment', $paymentId, ['outcome' => $result->outcome->value], $admin);
        $this->replies->log(sprintf(
            'admin_confirm_result payment_id=%d outcome=%s message="%s"',
            $paymentId,
            $result->outcome->value,
            $result->message
        ));
        if ($result->isAlreadyProcessed()) {
            $this->replies->popup($chatId, $callbackId, 'این پرداخت قبلاً بررسی شده است.', 'popup_payment_already_processed_confirm');

            return;
        }

        if (!$result->isConfirmed()) {
            $this->replies->popup($chatId, $callbackId, $result->message, 'popup_payment_confirm_panel_failed');

            return;
        }

        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('admin.payment_confirmed'));
    }

    public function reject(string $chatId, int $paymentId, ?string $callbackId = null, ?AdminActor $admin = null, bool $refunded = false): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->replies->ack($callbackId);
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('payment.failed'));

            return;
        }

        $result = $this->paymentSettlementService->reject($payment, null, $admin?->adminUserId, $chatId, 'telegram_payment_reject', $refunded);
        $this->auditLogger->record('payment.reject', 'Payment', $paymentId, ['outcome' => $result->outcome->value, 'refunded' => $refunded], $admin);
        $this->replies->log(sprintf(
            'admin_reject_result payment_id=%d outcome=%s message="%s"',
            $paymentId,
            $result->outcome->value,
            $result->message
        ));
        if ($result->isAlreadyProcessed()) {
            $this->replies->popup($chatId, $callbackId, 'این پرداخت قبلاً بررسی شده است.', 'popup_payment_already_processed_reject');

            return;
        }

        if (SettlementOutcome::REJECTED !== $result->outcome) {
            $this->replies->popup($chatId, $callbackId, 'عملیات انجام نشد.', 'popup_payment_reject_failed');

            return;
        }

        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message($refunded ? 'admin.payments.rejected_refunded' : 'admin.payment_rejected'));
    }
}
