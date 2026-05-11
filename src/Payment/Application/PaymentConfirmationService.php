<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\BotTexts;
use App\Entity\VpnService;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;
use App\Shop\Domain\OrderType;

class PaymentConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentApprovalService $paymentApprovalService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function confirm(Payment $payment, string $source = 'payment_confirmation'): PaymentApprovalResult
    {
        $result = $this->paymentApprovalService->confirm($payment, $source);
        if ($result->processed) {
            $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $payment->getOrder()->getUser()]);
            if ($telegramAccount instanceof TelegramAccount) {
                $vpnService = $result->vpnService;
                $orderType = $payment->getOrder()->getType();
                if (OrderType::RENEWAL === $orderType) {
                    $messages = $this->buildRenewalConfirmedMessages($vpnService);
                } elseif (OrderType::ADD_TRAFFIC === $orderType) {
                    $metadata = is_array($payment->getOrder()->getMetadata()) ? $payment->getOrder()->getMetadata() : [];
                    $messages = $this->buildAddTrafficConfirmedMessages($vpnService, (int) ($metadata['trafficGb'] ?? 0));
                } else {
                    $messages = $this->buildPaymentConfirmedMessages($vpnService);
                }
                foreach ($messages as $message) {
                    $this->telegramApiClient->sendMessage($telegramAccount->getTelegramId(), $message);
                }
            }
        }

        return $result;
    }

    public function reject(Payment $payment, ?string $note = null): PaymentApprovalResult
    {
        $result = $this->paymentApprovalService->reject($payment, $note);
        if ($result->processed) {
            $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $payment->getOrder()->getUser()]);
            if ($telegramAccount instanceof TelegramAccount) {
                $this->telegramApiClient->sendMessage($telegramAccount->getTelegramId(), BotTexts::PAYMENT_REJECTED);
            }
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function buildPaymentConfirmedMessages(?VpnService $vpnService): array
    {
        if (!$vpnService instanceof VpnService) {
            return ['✅ پرداخت شما تایید شد.'];
        }

        $subscriptionUrl = trim((string) ($vpnService->getSubscriptionUrl() ?? ''));
        $configText = trim((string) ($vpnService->getConfigText() ?? ''));
        $allConfigLinks = [];
        foreach ((array) ($vpnService->getConfigLinks() ?? []) as $link) {
            $candidate = trim((string) $link);
            if ('' !== $candidate) {
                $allConfigLinks[] = $candidate;
            }
        }
        if ([] === $allConfigLinks && preg_match('/^(vless|vmess|trojan):\/\//i', $configText) === 1) {
            $allConfigLinks = [$configText];
        }

        $lines = [
            '✅ پرداخت شما تایید شد.',
            '',
            '📦 خلاصه سرویس',
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('کاربری: %s', (string) ($vpnService->getUsername() ?? '-')),
        ];

        if ('' !== $subscriptionUrl) {
            $lines[] = '';
            $lines[] = '🔗 لینک اشتراک:';
            $lines[] = $subscriptionUrl;
        }

        if ([] !== $allConfigLinks) {
            $lines[] = '';
            $lines[] = '📡 لینکهای اتصال:';
            foreach ($allConfigLinks as $i => $link) {
                $lines[] = sprintf('%d. %s', $i + 1, $link);
            }
        }

        if ([] === $allConfigLinks && '' === $subscriptionUrl && '' !== $configText) {
            $lines[] = '';
            $lines[] = $configText;
        }

        return $this->splitLongMessage(implode("\n", $lines));
    }

    /**
     * @return string[]
     */
    private function buildRenewalConfirmedMessages(?VpnService $vpnService): array
    {
        if (!$vpnService instanceof VpnService) {
            return ['✅ سرویس شما با موفقیت تمدید شد.'];
        }

        $lines = [
            '✅ سرویس شما با موفقیت تمدید شد.',
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('تاریخ انقضای جدید: %s', $vpnService->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود'),
            sprintf('حجم کل جدید: %s', null === $vpnService->getTrafficLimitGb() ? 'نامحدود' : ((string) $vpnService->getTrafficLimitGb().' گیگ')),
            sprintf('لینک اشتراک: %s', $vpnService->getSubscriptionUrl() ?: '-'),
        ];

        $configLinks = [];
        foreach ((array) ($vpnService->getConfigLinks() ?? []) as $link) {
            $candidate = trim((string) $link);
            if ('' !== $candidate) {
                $configLinks[] = $candidate;
            }
        }
        if ([] !== $configLinks) {
            $lines[] = 'لینکهای اتصال:';
            foreach ($configLinks as $index => $link) {
                $lines[] = sprintf('%d. %s', $index + 1, $link);
            }
        }

        return $this->splitLongMessage(implode("\n", $lines));
    }

    /**
     * @return string[]
     */
    private function buildAddTrafficConfirmedMessages(?VpnService $vpnService, int $addedTrafficGb): array
    {
        if (!$vpnService instanceof VpnService) {
            return ['✅ حجم اضافه با موفقیت به سرویس شما اضافه شد.'];
        }

        $lines = [
            '✅ حجم اضافه با موفقیت به سرویس شما اضافه شد.',
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('حجم افزوده: %d گیگ', max(0, $addedTrafficGb)),
            sprintf('حجم کل جدید: %s', null === $vpnService->getTrafficLimitGb() ? 'نامحدود' : ((string) $vpnService->getTrafficLimitGb().' گیگ')),
            sprintf('لینک اشتراک: %s', $vpnService->getSubscriptionUrl() ?: '-'),
        ];

        $configLinks = [];
        foreach ((array) ($vpnService->getConfigLinks() ?? []) as $link) {
            $candidate = trim((string) $link);
            if ('' !== $candidate) {
                $configLinks[] = $candidate;
            }
        }
        if ([] !== $configLinks) {
            $lines[] = 'لینکهای اتصال:';
            foreach ($configLinks as $index => $link) {
                $lines[] = sprintf('%d. %s', $index + 1, $link);
            }
        }

        return $this->splitLongMessage(implode("\n", $lines));
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
}
