<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\BotTexts;
use App\Entity\VpnService;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;

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
                foreach ($this->buildPaymentConfirmedMessages($vpnService) as $message) {
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
        $singleConfigLink = '';
        foreach ((array) ($vpnService->getConfigLinks() ?? []) as $link) {
            $candidate = trim((string) $link);
            if ('' !== $candidate) {
                $singleConfigLink = $candidate;
                break;
            }
        }
        if ('' === $singleConfigLink && preg_match('/^(vless|vmess|trojan):\/\//i', $configText) === 1) {
            $singleConfigLink = $configText;
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

        if ('' !== $singleConfigLink) {
            $lines[] = '';
            $lines[] = '📡 لینک اتصال:';
            $lines[] = $singleConfigLink;
        }

        if ('' === $subscriptionUrl && '' === $singleConfigLink && '' !== $configText) {
            $lines[] = '';
            $lines[] = $configText;
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
                $breakPos = $maxLength;
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
