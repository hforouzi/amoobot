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
            $this->handleStart($chatId);

            return;
        }

        $openPayment = $this->findOpenPayment($account);

        if (isset($message['photo']) && is_array($message['photo']) && $openPayment instanceof Payment) {
            $this->handleReceiptPhoto($openPayment, $message, $chatId);

            return;
        }

        if ('' !== $text && $openPayment instanceof Payment) {
            $this->handleReceiptText($openPayment, $text, $chatId);

            return;
        }

        if ('' !== $text && !($openPayment instanceof Payment)) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::UNKNOWN_COMMAND, $this->keyboardFactory->mainMenu());
        }
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $telegramUser = $callbackQuery['from'] ?? null;
        $actorId = (string) ($callbackQuery['from']['id'] ?? '');
        $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');

        $this->debugLog(sprintf('callback_received data="%s" chat_id="%s" actor_id="%s"', $data, $chatId, $actorId));

        if (!is_array($telegramUser) || '' === $chatId || '' === $callbackId) {
            return;
        }

        if (str_starts_with($data, 'admin_confirm_payment:')) {
            $this->handleAdminConfirmPayment($callbackId, $chatId, $actorId, (int) str_replace('admin_confirm_payment:', '', $data));

            return;
        }

        if (str_starts_with($data, 'admin_reject_payment:')) {
            $this->handleAdminRejectPayment($callbackId, $chatId, $actorId, (int) str_replace('admin_reject_payment:', '', $data));

            return;
        }

        $account = $this->telegramUserResolver->resolveFromTelegramUser($telegramUser);
        $this->telegramApiClient->answerCallbackQuery($callbackId);

        if ('main_menu' === $data) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::MAIN_MENU, $this->keyboardFactory->mainMenu());

            return;
        }

        if ('buy_service' === $data) {
            $this->handleBuyService($chatId);

            return;
        }

        if (str_starts_with($data, 'select_plan:')) {
            $this->handleSelectPlan($account, $chatId, (int) str_replace('select_plan:', '', $data));

            return;
        }

        if ('my_services' === $data) {
            $this->handleMyServices($account, $chatId);

            return;
        }

        if ('support' === $data) {
            $this->handleSupport($chatId);

            return;
        }

        $this->debugLog(sprintf('callback_unknown data="%s"', $data));
    }

    private function handleStart(string $chatId): void
    {
        $this->telegramApiClient->sendMessage($chatId, BotTexts::WELCOME, $this->keyboardFactory->mainMenu());
    }

    private function handleBuyService(string $chatId): void
    {
        $plans = $this->entityManager->getRepository(Plan::class)->findBy(['isActive' => true], ['id' => 'ASC']);
        if ([] === $plans) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::NO_PLANS, $this->keyboardFactory->backToMainMenu());

            return;
        }

        $this->telegramApiClient->sendMessage($chatId, BotTexts::SELECT_PLAN, $this->keyboardFactory->plansMenu($plans));
    }

    private function handleSelectPlan(TelegramAccount $account, string $chatId, int $planId): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($planId);
        if (!$plan instanceof Plan || !$plan->isActive()) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::INVALID_PLAN, $this->keyboardFactory->backToMainMenu());

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
    }

    private function handleMyServices(TelegramAccount $account, string $chatId): void
    {
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
    }

    private function handleSupport(string $chatId): void
    {
        $this->telegramApiClient->sendMessage($chatId, BotTexts::SUPPORT, $this->keyboardFactory->backToMainMenu());
    }

    private function handleAdminConfirmPayment(string $callbackId, string $chatId, string $actorId, int $paymentId): void
    {
        if (!$this->isAdminAuthorized($actorId, $chatId)) {
            $this->debugLog(sprintf('admin_confirm_unauthorized actor_id="%s" chat_id="%s" payment_id=%d', $actorId, $chatId, $paymentId));
            $this->telegramApiClient->answerCallbackQuery($callbackId, BotTexts::ADMIN_UNAUTHORIZED);

            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId);
        $this->debugLog(sprintf('admin_confirm_execute actor_id="%s" payment_id=%d', $actorId, $paymentId));

        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_NOT_FOUND);

            return;
        }

        $result = $this->paymentConfirmationService->confirm($payment);
        $this->debugLog(sprintf(
            'admin_confirm_result payment_id=%d processed=%s already_processed=%s message="%s"',
            $paymentId,
            $result->processed ? 'true' : 'false',
            $result->alreadyProcessed ? 'true' : 'false',
            $result->message
        ));
        $this->telegramApiClient->sendMessage(
            $chatId,
            $result->alreadyProcessed ? BotTexts::ADMIN_PAYMENT_ALREADY_PROCESSED : BotTexts::ADMIN_PAYMENT_CONFIRMED
        );
    }

    private function handleAdminRejectPayment(string $callbackId, string $chatId, string $actorId, int $paymentId): void
    {
        if (!$this->isAdminAuthorized($actorId, $chatId)) {
            $this->debugLog(sprintf('admin_reject_unauthorized actor_id="%s" chat_id="%s" payment_id=%d', $actorId, $chatId, $paymentId));
            $this->telegramApiClient->answerCallbackQuery($callbackId, BotTexts::ADMIN_UNAUTHORIZED);

            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId);
        $this->debugLog(sprintf('admin_reject_execute actor_id="%s" payment_id=%d', $actorId, $paymentId));

        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_NOT_FOUND);

            return;
        }

        $result = $this->paymentConfirmationService->reject($payment);
        $this->debugLog(sprintf(
            'admin_reject_result payment_id=%d processed=%s already_processed=%s message="%s"',
            $paymentId,
            $result->processed ? 'true' : 'false',
            $result->alreadyProcessed ? 'true' : 'false',
            $result->message
        ));
        $this->telegramApiClient->sendMessage(
            $chatId,
            $result->alreadyProcessed ? BotTexts::ADMIN_PAYMENT_ALREADY_PROCESSED : BotTexts::ADMIN_PAYMENT_REJECTED
        );
    }

    private function handleReceiptPhoto(Payment $openPayment, array $message, string $chatId): void
    {
        $photos = $message['photo'];
        $lastPhoto = end($photos);
        $fileId = is_array($lastPhoto) ? (string) ($lastPhoto['file_id'] ?? '') : '';
        if ('' === $fileId) {
            return;
        }

        $openPayment
            ->setReceiptFileId($fileId)
            ->setStatus(PaymentStatus::SUBMITTED)
            ->setSubmittedAt(new \DateTimeImmutable());
        $openPayment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->entityManager->flush();
        $this->notifyAdmin($openPayment, 'receipt_photo');
        $this->telegramApiClient->sendMessage($chatId, BotTexts::RECEIPT_SUBMITTED);
    }

    private function handleReceiptText(Payment $openPayment, string $text, string $chatId): void
    {
        $openPayment
            ->setTrackingCode($text)
            ->setReceiptMessage($text)
            ->setStatus(PaymentStatus::SUBMITTED)
            ->setSubmittedAt(new \DateTimeImmutable());
        $openPayment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->entityManager->flush();
        $this->notifyAdmin($openPayment, 'tracking_code');
        $this->telegramApiClient->sendMessage($chatId, BotTexts::RECEIPT_SUBMITTED);
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

    private function isAdminAuthorized(string $actorId, string $chatId): bool
    {
        if (null === $this->adminChatId || '' === trim($this->adminChatId)) {
            return false;
        }

        $adminChatId = trim($this->adminChatId);

        return $actorId === $adminChatId || $chatId === $adminChatId;
    }

    private function debugLog(string $message): void
    {
        error_log('[TelegramUpdateHandler] '.$message);
    }
}
