<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\SubscriptionPageButton;
use App\Bot\Application\VpnAccessMessageFormatter;
use App\Entity\VpnService;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Bot\Infrastructure\TelegramApiRejected;
use App\Broadcast\BroadcastFormat;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;
use App\Shop\Domain\OrderType;
use App\Provisioning\Application\CustomerAccessGuard;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\ServiceConfigRefreshOutcome;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletKeys;
use Psr\Log\LoggerInterface;

/**
 * Customer-facing Telegram messages about a payment's outcome.
 *
 * Called by PaymentSettlementService only after its transaction has committed. Delivery problems
 * are logged and swallowed: a message that cannot be sent must never undo a settled payment.
 *
 * A renewal or extra traffic paid by someone who no longer owns the service (a reseller for a service his customer
 * claimed with a link, docs/design/reseller-and-claim.md D5): the payer is told it is done, with the new expiry and
 * traffic but WITHOUT the subscription link or config links; the owner gets the usual message with them. The owner is
 * read from the database at that moment, not from the (possibly stale) entity.
 *
 * A new service's delivery message is recorded in PaymentDeliveryLog (M2e E1): it is sent by the one process that claims
 * it, before the attach pass of additional inbounds, and sent again by resendMissing() (a retried settlement, the
 * scheduled "orders" job) when the process that should have sent it died or nothing reached the customer. Before every
 * send (review R1, D5) the service's owner and state are read from the database: a service that changed owner (a claim,
 * an admin transfer), is not active, or a delivery older than PaymentDeliveryLog::MAX_AGE is abandoned, sent to nobody.
 * The follow-up after the attach pass carries no link and goes to the order's user only while he still owns the service.
 */
class PaymentCustomerNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly BotTextResolver $botTextResolver,
        private readonly VpnAccessMessageFormatter $vpnAccessMessageFormatter,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
        private readonly LoggerInterface $logger,
        private readonly CustomerAccessGuard $accessGuard,
        private readonly SubscriptionPageButton $subscriptionPageButton,
        private readonly WalletService $walletService,
        private readonly PaymentDeliveryLog $deliveryLog,
        private readonly AdminPaymentAlerter $adminAlerter,
    ) {
    }

    public function confirmed(Payment $payment, ?VpnService $vpnService): void
    {
        if (OrderType::WALLET_TOPUP === $payment->getOrder()->getType()) {
            $this->walletToppedUp($payment);

            return;
        }

        $orderType = $payment->getOrder()->getType();
        if ((OrderType::RENEWAL === $orderType || OrderType::ADD_TRAFFIC === $orderType) && $vpnService instanceof VpnService && null !== $vpnService->getId()) {
            $ownerId = $this->entityManager->getConnection()->fetchOne('SELECT user_id FROM vpn_service WHERE id = ?', [$vpnService->getId()]);
            if (false !== $ownerId && (int) $ownerId !== $payment->getOrder()->getUser()->getId()) {
                $this->confirmedForPreviousOwner($payment, $vpnService, (int) $ownerId);

                return;
            }
        }

        if (OrderType::NEW_SERVICE === $orderType && $vpnService instanceof VpnService && null !== $this->trackedDelivery($payment, $vpnService)) {
            $this->sendTrackedDelivery($payment, $vpnService);

            return;
        }

        // A new service: "Open subscription page" under the last message.
        $lastMessageKeyboard = OrderType::RENEWAL !== $orderType && OrderType::ADD_TRAFFIC !== $orderType ? $this->subscriptionPageButton->keyboardFor($vpnService) : null;
        $this->deliver($payment, 'confirmed', function () use ($payment, $vpnService): array {
            $orderType = $payment->getOrder()->getType();
            if (OrderType::RENEWAL === $orderType) {
                return $this->buildRenewalConfirmedMessages($vpnService);
            }
            if (OrderType::ADD_TRAFFIC === $orderType) {
                $metadata = is_array($payment->getOrder()->getMetadata()) ? $payment->getOrder()->getMetadata() : [];

                return $this->buildAddTrafficConfirmedMessages($vpnService, (int) ($metadata['trafficGb'] ?? 0));
            }

            return $this->buildNewServiceConfirmedMessages($vpnService);
        }, $lastMessageKeyboard);
    }

    /**
     * A new service's delivery message that was due but not sent (the process died before or while sending it, or
     * nothing reached the customer): sent now, once, by whoever claims it. Never throws.
     */
    public function resendMissing(Payment $payment, VpnService $vpnService): void
    {
        try {
            if (OrderType::NEW_SERVICE !== $payment->getOrder()->getType() || null === $this->trackedDelivery($payment, $vpnService)) {
                return;
            }
            $this->sendTrackedDelivery($payment, $vpnService);
        } catch (\Throwable $e) {
            $this->logger->error('Customer delivery resend failed.', ['payment_id' => $payment->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * After the attach pass of a new service (M2e E1): one short message when it attached additional servers, at most
     * once per order, and only after the delivery message (a delivery still to be sent is built from the live state and
     * carries them anyway). Never throws.
     */
    public function inboundsAttached(Payment $payment, VpnService $vpnService, int $attached): void
    {
        try {
            $order = $payment->getOrder();
            if ($attached <= 0 || PaymentDeliveryLog::SENT !== $this->trackedDelivery($payment, $vpnService)
                || null !== $this->undeliverable($payment, $vpnService, false)
                || !$this->deliveryLog->followUpOnce((int) $vpnService->getId(), (int) $order->getUser()->getId(), (int) $order->getId(), $attached)) {
                return;
            }
            $account = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
            if ($account instanceof TelegramAccount) {
                $this->telegramApiClient->sendMessage($account->getTelegramId(), $this->botTextResolver->message('service.inbounds_attached', ['service' => ['id' => (int) $vpnService->getId(), 'count' => $attached]]));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Customer follow-up after the attach pass failed.', ['payment_id' => $payment->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * The state of this order's delivery in PaymentDeliveryLog; null for an order settled before M2e or whose row could
     * not be recorded (review R2): not tracked, delivered as before M2e. A failed read is "not tracked" too, never thrown.
     */
    private function trackedDelivery(Payment $payment, VpnService $vpnService): ?string
    {
        try {
            return null === $vpnService->getId() ? null : $this->deliveryLog->state((int) $vpnService->getId(), (int) $payment->getOrder()->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Delivery tracking could not be read; the delivery message is sent untracked.', ['error_class' => $e::class]);

            return null;
        }
    }

    /**
     * The delivery message of a new service, sent by the one process that claims it (review R3): the same messages and
     * requests as deliver(), each send checked. A message Telegram accepted is recorded at once (PaymentDeliveryLog::
     * accepted(), before and independent of the bot message log) and never sent again; a failed message does not stop
     * the next ones; an HTML message Telegram cannot parse is sent again as plain text (links kept). The delivery is SENT
     * only when every message was accepted, else a later attempt sends the rest; an unknown outcome (timeout) counts as
     * not accepted (sent again: at least once). After the last attempt it is FAILED and the admins are alerted once.
     * Never throws.
     */
    private function sendTrackedDelivery(Payment $payment, VpnService $vpnService): void
    {
        $serviceId = (int) $vpnService->getId();
        $orderId = (int) $payment->getOrder()->getId();
        try {
            $claim = $this->deliveryLog->claim($serviceId, $orderId);
            if (null === $claim) {
                // Sent, being sent by another process, failed or abandoned; or its attempts are used up (the last one died).
                if ($this->deliveryLog->exhaust($serviceId, $orderId)) {
                    $this->adminAlerter->deliveryFailed($payment, PaymentDeliveryLog::MAX_ATTEMPTS);
                }

                return;
            }
            $reason = $this->undeliverable($payment, $vpnService, true);
            $telegramAccount = null === $reason ? $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $payment->getOrder()->getUser()]) : null;
            $reason ??= $telegramAccount instanceof TelegramAccount ? null : 'no_telegram_account';
            if (null !== $reason || !$telegramAccount instanceof TelegramAccount) {
                $this->deliveryLog->abandon($serviceId, $orderId, (string) $reason);
                $this->logger->warning('Customer delivery abandoned, nothing sent.', ['reason' => $reason]);

                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Customer delivery could not be started.', ['payment_id' => $payment->getId(), 'error_class' => $e::class]);

            return;
        }

        $accepted = array_fill_keys($claim['accepted'], true);
        $total = null;
        try {
            $keyboard = $this->subscriptionPageButton->keyboardFor($vpnService);
            $messages = array_values($this->buildNewServiceConfirmedMessages($vpnService));
            $total = count($messages);
            foreach ($messages as $i => $message) {
                if (isset($accepted[$i])) {
                    continue;
                }
                if ($this->sendDeliveryMessage($telegramAccount->getTelegramId(), $message, $i === $total - 1 ? $keyboard : null, function () use ($serviceId, $orderId, $i, &$accepted): void {
                    $accepted[$i] = true;
                    try {
                        $this->deliveryLog->accepted($serviceId, $orderId, $i);
                    } catch (\Throwable $e) {
                        $this->logger->error('Customer delivery progress could not be recorded.', ['error_class' => $e::class]);
                    }
                })) {
                    continue;
                }
                $this->logger->error('Customer payment notification failed.', ['payment_id' => $payment->getId(), 'kind' => 'confirmed', 'message' => $i + 1, 'of' => $total, 'attempt' => $claim['attempt']]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Customer payment notification failed.', ['payment_id' => $payment->getId(), 'kind' => 'confirmed', 'attempt' => $claim['attempt'], 'error' => $e->getMessage()]);
        }

        $complete = null !== $total && count(array_intersect_key($accepted, range(0, max(0, $total - 1)))) >= $total;
        try {
            if (PaymentDeliveryLog::FAILED === $this->deliveryLog->finish($serviceId, $orderId, $claim['token'], $complete)) {
                $this->adminAlerter->deliveryFailed($payment, $claim['attempt']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Customer delivery state could not be recorded.', ['payment_id' => $payment->getId(), 'error_class' => $e::class]);
        }
    }

    /**
     * One message of a delivery; true when Telegram accepted it ($onAccepted has run, even if the bot message log failed
     * afterwards). An HTML message Telegram cannot parse is sent once more as plain text, its links kept.
     *
     * @param array<string, mixed>|null $markup
     */
    private function sendDeliveryMessage(string $chatId, string $message, ?array $markup, \Closure $onAccepted): bool
    {
        $done = false;
        $accept = static function () use (&$done, $onAccepted): void {
            $done = true;
            $onAccepted();
        };
        $html = str_contains($message, '<code>');
        try {
            $html ? $this->telegramApiClient->sendHtmlMessageStrict($chatId, $message, $markup, $accept) : $this->telegramApiClient->sendMessageStrict($chatId, $message, $markup, $accept);

            return true;
        } catch (TelegramApiRejected $e) {
            if ($done || !$html || !BroadcastFormat::isParseError($e->getMessage())) {
                return $done;
            }
        } catch (\Throwable) {
            return $done;
        }
        try {
            $this->telegramApiClient->sendMessageStrict($chatId, html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $markup, $accept);
        } catch (\Throwable) {
        }

        return $done;
    }

    /**
     * Why this order's service must not be delivered (to anyone) now, or null: read fresh from the database, never from
     * the (possibly stale) entity. $checkAge: also a delivery older than PaymentDeliveryLog::MAX_AGE.
     */
    private function undeliverable(Payment $payment, VpnService $vpnService, bool $checkAge): ?string
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT user_id, status FROM vpn_service WHERE id = ?', [$vpnService->getId()]);

        return match (true) {
            false === $row => 'service_missing',
            (int) $row['user_id'] !== (int) $payment->getOrder()->getUser()->getId() => 'owner_changed',
            VpnServiceStatus::DELETED === $row['status'] => 'service_deleted',
            VpnServiceStatus::ACTIVE !== $row['status'] => 'service_not_active',
            $checkAge && $this->deliveryLog->tooOld((int) $vpnService->getId(), (int) $payment->getOrder()->getId()) => 'too_old',
            default => null,
        };
    }

    /**
     * The payer no longer owns the service: no links to him; the owner gets the usual message (with his links).
     */
    private function confirmedForPreviousOwner(Payment $payment, VpnService $vpnService, int $ownerId): void
    {
        $key = OrderType::RENEWAL === $payment->getOrder()->getType() ? 'service.renewed_for_customer' : 'service.add_traffic_done_for_customer';
        $this->deliver($payment, 'confirmed_for_customer', fn (): array => [$this->botTextResolver->message($key, ['service' => [
            'id' => (int) $vpnService->getId(),
            'expiresAt' => $vpnService->getExpiresAt()?->format('Y-m-d') ?? '∞',
            'trafficGb' => null === $vpnService->getTrafficLimitGb() ? '∞' : (string) $vpnService->getTrafficLimitGb(),
        ]])]);

        $owner = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $ownerId]);
        if (!$owner instanceof TelegramAccount) {
            return;
        }
        try {
            $messages = OrderType::RENEWAL === $payment->getOrder()->getType()
                ? $this->buildRenewalConfirmedMessages($vpnService)
                : $this->buildAddTrafficConfirmedMessages($vpnService, (int) ((is_array($payment->getOrder()->getMetadata()) ? $payment->getOrder()->getMetadata() : [])['trafficGb'] ?? 0));
            foreach ($messages as $message) {
                if (str_contains($message, '<code>')) {
                    $this->telegramApiClient->sendHtmlMessage($owner->getTelegramId(), $message);
                } else {
                    $this->telegramApiClient->sendMessage($owner->getTelegramId(), $message);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Owner notification of a paid renewal failed.', ['payment_id' => $payment->getId(), 'error' => $e->getMessage()]);
        }
    }

    /** A confirmed wallet top-up: the amount credited (what was received, see TopUpCredit) and the balance now. */
    private function walletToppedUp(Payment $payment): void
    {
        $credited = $this->walletService->findTransaction(WalletKeys::topUp((int) $payment->getId()))?->getAmount() ?? $payment->getOrder()->getAmount();
        $this->deliver($payment, 'wallet_topup', fn (): array => [$this->botTextResolver->message('wallet.topup_confirmed', ['wallet' => [
            'amount' => number_format($credited),
            'balance' => number_format($this->walletService->find($payment->getOrder()->getUser())?->getBalance() ?? 0),
        ]])]);
    }

    public function rejected(Payment $payment): void
    {
        // Keyed on the ledger (like the release itself), not on the payment's method.
        $reversal = $this->walletService->findTransaction(WalletKeys::purchaseReversal((int) $payment->getId()));
        if (null !== $reversal) {
            $this->deliver($payment, 'wallet_reversed', fn (): array => [$this->botTextResolver->message('wallet.reversed', ['wallet' => [
                'amount' => number_format($reversal->getAmount()),
                'balance' => number_format($this->walletService->find($payment->getOrder()->getUser())?->getBalance() ?? 0),
            ]])]);

            return;
        }
        $key = null !== $payment->getRefundedAt() ? 'payment.rejected_refunded' : 'payment.rejected';
        $this->deliver($payment, 'rejected', fn (): array => [$this->botTextResolver->message($key)]);
    }

    public function underReview(Payment $payment): void
    {
        // A late payment (after cancel/expiry) is not an amount problem: say what actually happened.
        $key = $payment->isLatePaymentInReview() ? 'payment.under_review_late' : 'payment.under_review';
        $this->deliver($payment, 'under_review', fn (): array => [$this->botTextResolver->message($key)]);
    }

    public function provisioningDelayed(Payment $payment): void
    {
        $this->deliver($payment, 'provisioning_delayed', fn (): array => [$this->botTextResolver->message('payment.provisioning_delayed')]);
    }

    /**
     * @param callable(): string[]       $buildMessages
     * @param array<string, mixed>|null $lastMessageKeyboard reply markup of the last message
     */
    private function deliver(Payment $payment, string $kind, callable $buildMessages, ?array $lastMessageKeyboard = null): void
    {
        try {
            $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $payment->getOrder()->getUser()]);
            if (!$telegramAccount instanceof TelegramAccount) {
                return;
            }

            $messages = array_values($buildMessages());
            foreach ($messages as $i => $message) {
                $keyboard = $i === count($messages) - 1 ? $lastMessageKeyboard : null;
                if (str_contains($message, '<code>')) {
                    $this->telegramApiClient->sendHtmlMessage($telegramAccount->getTelegramId(), $message, $keyboard);
                } else {
                    $this->telegramApiClient->sendMessage($telegramAccount->getTelegramId(), $message, $keyboard);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Customer payment notification failed.', ['payment_id' => $payment->getId(), 'kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return string[]
     */
    public function buildNewServiceConfirmedMessages(?VpnService $vpnService, ?string $headline = null): array
    {
        if (!$vpnService instanceof VpnService) {
            return [$headline ?? $this->botTextResolver->message('payment.confirmed')];
        }

        return $this->vpnAccessMessageFormatter->formatServiceAccessMessages(
            $vpnService,
            $headline ?? '✅ سرویس شما آماده شد',
            'payment_confirmed_new_service'
        );
    }

    /**
     * @return string[]
     */
    private function buildRenewalConfirmedMessages(?VpnService $vpnService): array
    {
        if (!$vpnService instanceof VpnService) {
            return [$this->botTextResolver->message('service.renewed')];
        }

        $refreshOutcome = $this->refreshBeforePaymentDelivery($vpnService, 'payment_confirmed_renewal');
        if (!$refreshOutcome->succeeded && !$refreshOutcome->skipped && !$refreshOutcome->fallbackToStored) {
            return ['دریافت کانفیگ از پنل با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.'];
        }
        $refreshWarning = $this->paymentDeliveryRefreshWarning($refreshOutcome);

        $lines = [
            $this->botTextResolver->message('service.renewed'),
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('تاریخ انقضای جدید: %s', $this->html($vpnService->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود')),
            sprintf('حجم کل جدید: %s', null === $vpnService->getTrafficLimitGb() ? 'نامحدود' : ((string) $vpnService->getTrafficLimitGb().' گیگ')),
            sprintf('لینک اشتراک: %s', $this->subscriptionLine($vpnService)),
        ];

        $configLinks = $this->accessGuard->configLinks($vpnService, $this->finalConfigLinkProvider->getFinalLinksForService($vpnService, 'payment_confirmed_renewal'));
        if ([] !== $configLinks) {
            $lines[] = 'لینکهای اتصال:';
            foreach ($configLinks as $index => $link) {
                $lines[] = sprintf("%d.\n%s", $index + 1, $this->htmlCode($link));
            }
        }

        if (null !== $refreshWarning) {
            array_unshift($lines, $refreshWarning);
        }

        return $this->splitLongMessage(implode("\n", $lines));
    }

    /**
     * @return string[]
     */
    private function buildAddTrafficConfirmedMessages(?VpnService $vpnService, int $addedTrafficGb): array
    {
        if (!$vpnService instanceof VpnService) {
            return [$this->botTextResolver->message('service.add_traffic_done')];
        }

        $refreshOutcome = $this->refreshBeforePaymentDelivery($vpnService, 'payment_confirmed_add_traffic');
        if (!$refreshOutcome->succeeded && !$refreshOutcome->skipped && !$refreshOutcome->fallbackToStored) {
            return ['دریافت کانفیگ از پنل با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.'];
        }
        $refreshWarning = $this->paymentDeliveryRefreshWarning($refreshOutcome);

        $lines = [
            $this->botTextResolver->message('service.add_traffic_done'),
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('حجم افزوده: %d گیگ', max(0, $addedTrafficGb)),
            sprintf('حجم کل جدید: %s', null === $vpnService->getTrafficLimitGb() ? 'نامحدود' : ((string) $vpnService->getTrafficLimitGb().' گیگ')),
            sprintf('لینک اشتراک: %s', $this->subscriptionLine($vpnService)),
        ];

        $configLinks = $this->accessGuard->configLinks($vpnService, $this->finalConfigLinkProvider->getFinalLinksForService($vpnService, 'payment_confirmed_add_traffic'));
        if ([] !== $configLinks) {
            $lines[] = 'لینکهای اتصال:';
            foreach ($configLinks as $index => $link) {
                $lines[] = sprintf("%d.\n%s", $index + 1, $this->htmlCode($link));
            }
        }

        if (null !== $refreshWarning) {
            array_unshift($lines, $refreshWarning);
        }

        return $this->splitLongMessage(implode("\n", $lines));
    }

    /** The usable subscription URL, the support notice when the stored one cannot be reached, or "-". */
    private function subscriptionLine(VpnService $vpnService): string
    {
        $url = $this->accessGuard->subscriptionUrl($vpnService);
        if (null !== $url) {
            return $this->htmlCode($url);
        }

        return '' !== trim((string) $vpnService->getSubscriptionUrl()) ? $this->html(CustomerAccessGuard::SUPPORT_NOTICE) : '-';
    }

    /**
     * @return string[]
     */
    private function splitLongMessage(string $message, int $maxLength = 3500): array
    {
        $message = trim($message);
        if (mb_strlen($message) <= $maxLength) {
            return [$message];
        }

        $chunks = [];
        $remaining = $message;
        while (mb_strlen($remaining) > $maxLength) {
            $slice = mb_substr($remaining, 0, $maxLength);
            $breakPos = mb_strrpos($slice, "\n");
            if (false === $breakPos || $breakPos < ($maxLength / 2)) {
                $breakPos = min($maxLength, mb_strlen($remaining));
            }
            $chunks[] = trim(mb_substr($remaining, 0, $breakPos));
            $remaining = trim(mb_substr($remaining, (int) $breakPos));
        }
        if ('' !== $remaining) {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function htmlCode(string $value): string
    {
        return '<code>'.$this->html($value).'</code>';
    }

    private function refreshBeforePaymentDelivery(VpnService $service, string $sourceFlow): ServiceConfigRefreshOutcome
    {
        $outcome = $this->deliveryRefresher->refreshBeforeDelivery($service, $sourceFlow);
        if ($outcome->succeeded) {
            $this->entityManager->flush();
        }

        return $outcome;
    }

    private function paymentDeliveryRefreshWarning(ServiceConfigRefreshOutcome $outcome): ?string
    {
        if ($outcome->skipped) {
            return null;
        }

        if ($outcome->fallbackToStored) {
            return 'دریافت آنلاین کانفیگ از پنل انجام نشد؛ آخرین کانفیگ ذخیره‌شده ارسال می‌شود.';
        }

        return 'دریافت کانفیگ از پنل با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.';
    }
}
