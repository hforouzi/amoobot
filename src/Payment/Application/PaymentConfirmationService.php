<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Application\BotTexts;
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

    public function confirm(Payment $payment): PaymentApprovalResult
    {
        $result = $this->paymentApprovalService->confirm($payment);
        if ($result->processed) {
            $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $payment->getOrder()->getUser()]);
            if ($telegramAccount instanceof TelegramAccount) {
                $vpnService = $result->vpnService;
                $this->telegramApiClient->sendMessage(
                    $telegramAccount->getTelegramId(),
                    sprintf(
                        BotTexts::PAYMENT_CONFIRMED_TEMPLATE,
                        $vpnService?->getSubscriptionUrl() ?? '-',
                        $vpnService?->getConfigText() ?? '-'
                    )
                );
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
}
