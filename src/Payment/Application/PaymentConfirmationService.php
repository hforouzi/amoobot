<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\VpnProvisioningService;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

class PaymentConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnProvisioningService $vpnProvisioningService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function confirm(Payment $payment): void
    {
        $order = $payment->getOrder();

        $payment
            ->setStatus(PaymentStatus::CONFIRMED)
            ->setConfirmedAt(new \DateTimeImmutable());

        $order
            ->setStatus(OrderStatus::PAID)
            ->setPaidAt(new \DateTimeImmutable());

        $vpnService = $this->vpnProvisioningService->provisionOrder($order);

        $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);

        $this->entityManager->flush();

        if ($telegramAccount instanceof TelegramAccount) {
            $this->telegramApiClient->sendMessage(
                $telegramAccount->getTelegramId(),
                sprintf(
                    "✅ پرداخت شما تایید شد.\n\nSubscription URL:\n%s\n\nConfig:\n%s",
                    $vpnService->getSubscriptionUrl() ?? '-',
                    $vpnService->getConfigText() ?? '-'
                )
            );
        }
    }

    public function reject(Payment $payment, ?string $note = null): void
    {
        $payment
            ->setStatus(PaymentStatus::REJECTED)
            ->setAdminNote($note);

        $payment->getOrder()->setStatus(OrderStatus::FAILED);

        $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $payment->getOrder()->getUser()]);
        $this->entityManager->flush();

        if ($telegramAccount instanceof TelegramAccount) {
            $this->telegramApiClient->sendMessage($telegramAccount->getTelegramId(), '❌ پرداخت شما رد شد. لطفا مجدد رسید معتبر ارسال کنید.');
        }
    }
}
