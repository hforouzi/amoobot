<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\BotTextResolver;
use App\Entity\VpnService;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;
use App\Shop\Domain\OrderType;
use App\Provisioning\Application\FinalConfigLinkProvider;

class PaymentConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentApprovalService $paymentApprovalService,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly BotTextResolver $botTextResolver,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
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
                    $messages = $this->buildNewServiceConfirmedMessages($vpnService);
                }
                foreach ($messages as $message) {
                    if (str_contains($message, '<code>')) {
                        $this->telegramApiClient->sendHtmlMessage($telegramAccount->getTelegramId(), $message);
                    } else {
                        $this->telegramApiClient->sendMessage($telegramAccount->getTelegramId(), $message);
                    }
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
                $this->telegramApiClient->sendMessage($telegramAccount->getTelegramId(), $this->botTextResolver->message('payment.rejected'));
            }
        }

        return $result;
    }

    /**
     * @return string[]
     */
    public function buildNewServiceConfirmedMessages(?VpnService $vpnService, ?string $headline = null): array
    {
        if (!$vpnService instanceof VpnService) {
            return [$headline ?? $this->botTextResolver->message('payment.confirmed')];
        }

        $subscriptionUrl = trim((string) ($vpnService->getSubscriptionUrl() ?? ''));
        $allConfigLinks = $this->finalConfigLinkProvider->getFinalLinksForService($vpnService, 'payment_confirmed_new_service');

        $lines = [
            $headline ?? $this->botTextResolver->message('payment.confirmed'),
            '',
            '📦 خلاصه سرویس',
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('کاربری: %s', $this->html((string) ($vpnService->getUsername() ?? '-'))),
        ];

        if ('' !== $subscriptionUrl) {
            $lines[] = '';
            $lines[] = '🔗 لینک اشتراک:';
            $lines[] = $this->htmlCode($subscriptionUrl);
        }

        if ([] !== $allConfigLinks) {
            $lines[] = '';
            $lines[] = '📡 لینکهای اتصال:';
            foreach ($allConfigLinks as $i => $link) {
                $lines[] = sprintf("%d.\n%s", $i + 1, $this->htmlCode($link));
            }
        }

        return $this->splitLongMessage(implode("\n", $lines));
    }

    /**
     * @return string[]
     */
    private function buildRenewalConfirmedMessages(?VpnService $vpnService): array
    {
        if (!$vpnService instanceof VpnService) {
            return [$this->botTextResolver->message('service.renewed')];
        }

        $lines = [
            $this->botTextResolver->message('service.renewed'),
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('تاریخ انقضای جدید: %s', $this->html($vpnService->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود')),
            sprintf('حجم کل جدید: %s', null === $vpnService->getTrafficLimitGb() ? 'نامحدود' : ((string) $vpnService->getTrafficLimitGb().' گیگ')),
            sprintf('لینک اشتراک: %s', $vpnService->getSubscriptionUrl() ? $this->htmlCode($vpnService->getSubscriptionUrl()) : '-'),
        ];

        $configLinks = $this->finalConfigLinkProvider->getFinalLinksForService($vpnService, 'payment_confirmed_renewal');
        if ([] !== $configLinks) {
            $lines[] = 'لینکهای اتصال:';
            foreach ($configLinks as $index => $link) {
                $lines[] = sprintf("%d.\n%s", $index + 1, $this->htmlCode($link));
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
            return [$this->botTextResolver->message('service.add_traffic_done')];
        }

        $lines = [
            $this->botTextResolver->message('service.add_traffic_done'),
            sprintf('شناسه سرویس: %d', $vpnService->getId() ?? 0),
            sprintf('حجم افزوده: %d گیگ', max(0, $addedTrafficGb)),
            sprintf('حجم کل جدید: %s', null === $vpnService->getTrafficLimitGb() ? 'نامحدود' : ((string) $vpnService->getTrafficLimitGb().' گیگ')),
            sprintf('لینک اشتراک: %s', $vpnService->getSubscriptionUrl() ? $this->htmlCode($vpnService->getSubscriptionUrl()) : '-'),
        ];

        $configLinks = $this->finalConfigLinkProvider->getFinalLinksForService($vpnService, 'payment_confirmed_add_traffic');
        if ([] !== $configLinks) {
            $lines[] = 'لینکهای اتصال:';
            foreach ($configLinks as $index => $link) {
                $lines[] = sprintf("%d.\n%s", $index + 1, $this->htmlCode($link));
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

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function htmlCode(string $value): string
    {
        return '<code>'.$this->html($value).'</code>';
    }
}
