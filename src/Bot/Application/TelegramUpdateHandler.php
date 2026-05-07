<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\TelegramAccount;
use App\Entity\VpnService;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUpdateHandler
{
    public function __construct(
        private readonly TelegramUserResolver $telegramUserResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentConfirmationService $paymentConfirmationService,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly ?string $adminChatId = null,
        private readonly string $paymentCardNumber = '',
        private readonly string $paymentCardHolder = '',
        private readonly ?string $paymentDescription = null,
    ) {
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);

            return;
        }

        if (!isset($update['message'])) {
            return;
        }

        $this->handleMessage($update['message']);
    }

    private function handleMessage(array $message): void
    {
        $telegramUser = $message['from'] ?? null;
        $chatId = (string) ($message['chat']['id'] ?? '');

        if (!is_array($telegramUser) || '' === $chatId) {
            return;
        }

        $account = $this->telegramUserResolver->resolveFromTelegramUser($telegramUser);
        $text = trim((string) ($message['text'] ?? ''));

        if ('/start' === $text) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::WELCOME, $this->keyboardFactory->mainMenu());

            return;
        }

        $openPayment = $this->findOpenPayment($account);

        if (isset($message['photo']) && is_array($message['photo']) && $openPayment instanceof Payment) {
            $lastPhoto = end($message['photo']);
            $fileId = is_array($lastPhoto) ? (string) ($lastPhoto['file_id'] ?? '') : '';
            if ('' !== $fileId) {
                $openPayment
                    ->setReceiptFileId($fileId)
                    ->setStatus(PaymentStatus::SUBMITTED)
                    ->setSubmittedAt(new \DateTimeImmutable());
                $openPayment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
                $this->entityManager->flush();
                $this->notifyAdmin($openPayment, 'receipt_photo');
                $this->telegramApiClient->sendMessage($chatId, BotTexts::RECEIPT_SUBMITTED);
            }

            return;
        }

        if ('' !== $text && $openPayment instanceof Payment) {
            $openPayment
                ->setTrackingCode($text)
                ->setReceiptMessage($text)
                ->setStatus(PaymentStatus::SUBMITTED)
                ->setSubmittedAt(new \DateTimeImmutable());
            $openPayment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
            $this->entityManager->flush();
            $this->notifyAdmin($openPayment, 'tracking_code');
            $this->telegramApiClient->sendMessage($chatId, BotTexts::RECEIPT_SUBMITTED);

            return;
        }

        $this->telegramApiClient->sendMessage($chatId, BotTexts::UNKNOWN_COMMAND, $this->keyboardFactory->mainMenu());
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $telegramUser = $callbackQuery['from'] ?? null;
        $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');

        if (!is_array($telegramUser) || '' === $chatId || '' === $callbackId) {
            return;
        }

        if (str_starts_with($data, 'admin_confirm_payment:') || str_starts_with($data, 'admin_reject_payment:')) {
            $this->handleAdminPaymentCallback($callbackId, $chatId, $data);

            return;
        }

        $account = $this->telegramUserResolver->resolveFromTelegramUser($telegramUser);
        $this->telegramApiClient->answerCallbackQuery($callbackId);

        if ('main_menu' === $data) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::MAIN_MENU, $this->keyboardFactory->mainMenu());

            return;
        }

        if ('buy_service' === $data) {
            $plans = $this->entityManager->getRepository(Plan::class)->findBy(['isActive' => true], ['id' => 'ASC']);
            if ([] === $plans) {
                $this->telegramApiClient->sendMessage($chatId, BotTexts::NO_PLANS, $this->keyboardFactory->mainMenu());

                return;
            }

            $this->telegramApiClient->sendMessage($chatId, BotTexts::SELECT_PLAN, $this->keyboardFactory->plansMenu($plans));

            return;
        }

        if ('my_services' === $data) {
            $services = $this->entityManager->getRepository(VpnService::class)->findBy([
                'user' => $account->getUser(),
                'status' => VpnServiceStatus::ACTIVE,
            ], ['id' => 'DESC']);

            if ([] === $services) {
                $this->telegramApiClient->sendMessage($chatId, BotTexts::NO_SERVICES, $this->keyboardFactory->backToMainMenu());

                return;
            }

            $text = "سرویس‌های شما:\n\n";
            foreach ($services as $service) {
                $text .= sprintf(
                    "• اشتراک: %s\n• کانفیگ: %s\n\n",
                    $service->getSubscriptionUrl() ?? '-',
                    $service->getConfigText() ?? '-'
                );
            }

            $this->telegramApiClient->sendMessage($chatId, trim($text), $this->keyboardFactory->backToMainMenu());

            return;
        }

        if ('support' === $data) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::SUPPORT, $this->keyboardFactory->backToMainMenu());

            return;
        }

        if (str_starts_with($data, 'select_plan:')) {
            $planId = (int) str_replace('select_plan:', '', $data);
            $plan = $this->entityManager->getRepository(Plan::class)->find($planId);
            if (!$plan instanceof Plan || !$plan->isActive()) {
                $this->telegramApiClient->sendMessage($chatId, BotTexts::INVALID_PLAN, $this->keyboardFactory->mainMenu());

                return;
            }

            $order = (new Order())
                ->setUser($account->getUser())
                ->setPlan($plan)
                ->setAmount($plan->getPrice())
                ->setStatus(OrderStatus::WAITING_PAYMENT);

            $payment = (new Payment())
                ->setOrder($order)
                ->setMethod('manual_card')
                ->setAmount($plan->getPrice())
                ->setStatus(PaymentStatus::PENDING);

            $this->entityManager->persist($order);
            $this->entityManager->persist($payment);
            $this->entityManager->flush();

            $cardNumber = $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
            $cardHolder = $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
            $description = $this->settingValueProvider->get('payment.description', $this->paymentDescription);

            $message = sprintf(
                "پلن: %s\nمبلغ: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nلطفا تصویر رسید یا کد پیگیری را ارسال کنید.",
                $plan->getTitle(),
                $plan->getPrice(),
                $cardNumber ?: '-',
                $cardHolder ?: '-',
                $description ? 'توضیحات: '.$description : ''
            );

            $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentInstructionsMenu());

            return;
        }

        $this->telegramApiClient->sendMessage($chatId, BotTexts::UNKNOWN_COMMAND, $this->keyboardFactory->mainMenu());
    }

    private function findOpenPayment(TelegramAccount $account): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->join('p.order', 'o')
            ->where('o.user = :user')
            ->andWhere('o.status = :orderStatus')
            ->andWhere('p.status IN (:paymentStatuses)')
            ->setParameter('user', $account->getUser())
            ->setParameter('orderStatus', OrderStatus::WAITING_PAYMENT)
            ->setParameter('paymentStatuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function notifyAdmin(Payment $payment, string $kind): void
    {
        if (null === $this->adminChatId || '' === trim($this->adminChatId)) {
            return;
        }

        $order = $payment->getOrder();
        $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
        $username = $telegramAccount?->getUsername();
        $telegramId = $telegramAccount?->getTelegramId() ?? '-';
        $receiptInfo = 'tracking_code' === $kind
            ? ($payment->getTrackingCode() ?: $payment->getReceiptMessage() ?: '-')
            : ('file_id: '.($payment->getReceiptFileId() ?: '-'));

        $message = sprintf(
            "پرداخت جدید ثبت شد\n\nPayment ID: %d\nOrder ID: %d\nUser: @%s / %s\nPlan: %s\nAmount: %d تومان\nReceipt: %s",
            $payment->getId(),
            $order->getId(),
            $username ?: '-',
            $telegramId,
            $order->getPlan()->getTitle(),
            $payment->getAmount(),
            $receiptInfo
        );

        $this->telegramApiClient->sendMessage($this->adminChatId, $message, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));
    }

    private function handleAdminPaymentCallback(string $callbackId, string $chatId, string $data): void
    {
        if (null === $this->adminChatId || '' === trim($this->adminChatId) || $chatId !== trim($this->adminChatId)) {
            $this->telegramApiClient->answerCallbackQuery($callbackId, BotTexts::ADMIN_UNAUTHORIZED);

            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId);

        $paymentId = (int) substr($data, strrpos($data, ':') + 1);
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_NOT_FOUND);

            return;
        }

        if (str_starts_with($data, 'admin_confirm_payment:')) {
            $result = $this->paymentConfirmationService->confirm($payment);
            $this->telegramApiClient->sendMessage(
                $chatId,
                $result->alreadyProcessed ? BotTexts::ADMIN_PAYMENT_ALREADY_PROCESSED : BotTexts::ADMIN_PAYMENT_CONFIRMED
            );

            return;
        }

        $result = $this->paymentConfirmationService->reject($payment);
        $this->telegramApiClient->sendMessage(
            $chatId,
            $result->alreadyProcessed ? BotTexts::ADMIN_PAYMENT_ALREADY_PROCESSED : BotTexts::ADMIN_PAYMENT_REJECTED
        );
    }
}
