<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\SubscriptionPageButton;
use App\Bot\Application\VpnAccessMessageFormatter;
use App\Entity\VpnService;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;
use App\Shop\Domain\OrderType;
use App\Provisioning\Application\CustomerAccessGuard;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\ServiceConfigRefreshOutcome;
use Psr\Log\LoggerInterface;

/**
 * Customer-facing Telegram messages about a payment's outcome.
 *
 * Called by PaymentSettlementService only after its transaction has committed. Delivery problems
 * are logged and swallowed: a message that cannot be sent must never undo a settled payment.
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
    ) {
    }

    public function confirmed(Payment $payment, ?VpnService $vpnService): void
    {
        // A new service: "Open subscription page" under the last message.
        $orderType = $payment->getOrder()->getType();
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

    public function rejected(Payment $payment): void
    {
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
