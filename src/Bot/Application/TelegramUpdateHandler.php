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
        $actorId = (string) ($message['from']['id'] ?? '');
        $chatId = (string) ($message['chat']['id'] ?? '');

        if (!is_array($telegramUser) || '' === $chatId) {
            return;
        }

        $account = $this->telegramUserResolver->resolveFromTelegramUser($telegramUser);
        $text = trim((string) ($message['text'] ?? ''));
        $isAdmin = $this->isAdminUserId($actorId);

        if ('/start' === $text) {
            $this->handleStart($chatId, $isAdmin);

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
            $this->telegramApiClient->sendMessage($chatId, BotTexts::UNKNOWN_COMMAND, $this->keyboardFactory->mainMenu($isAdmin));
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

        if (str_starts_with($data, 'admin_')) {
            if (!$this->isAdminUserId($actorId)) {
                $this->debugLog(sprintf('admin_callback_unauthorized data="%s" actor_id="%s" chat_id="%s"', $data, $actorId, $chatId));
                $this->telegramApiClient->answerCallbackQuery($callbackId, BotTexts::ADMIN_UNAUTHORIZED);

                return;
            }

            $this->telegramApiClient->answerCallbackQuery($callbackId);
            $this->debugLog(sprintf('admin_callback_execute data="%s" actor_id="%s"', $data, $actorId));

            if ('admin_menu' === $data) {
                $this->handleAdminMenu($chatId);

                return;
            }

            if ('admin_pending_payments' === $data) {
                $this->handleAdminPendingPayments($chatId);

                return;
            }

            if ('admin_users' === $data) {
                $this->handleAdminUsers($chatId);

                return;
            }

            if ('admin_services' === $data) {
                $this->handleAdminServices($chatId);

                return;
            }

            if ('admin_orders' === $data) {
                $this->handleAdminOrders($chatId);

                return;
            }

            if (str_starts_with($data, 'admin_view_payment:')) {
                $this->handleAdminViewPayment($chatId, (int) str_replace('admin_view_payment:', '', $data));

                return;
            }

            if (str_starts_with($data, 'admin_confirm_payment:')) {
                $this->handleAdminConfirmPayment($chatId, (int) str_replace('admin_confirm_payment:', '', $data));

                return;
            }

            if (str_starts_with($data, 'admin_reject_payment:')) {
                $this->handleAdminRejectPayment($chatId, (int) str_replace('admin_reject_payment:', '', $data));

                return;
            }

            $this->debugLog(sprintf('admin_callback_unknown data="%s"', $data));

            return;
        }

        $isAdmin = $this->isAdminUserId($actorId);

        $account = $this->telegramUserResolver->resolveFromTelegramUser($telegramUser);
        $this->telegramApiClient->answerCallbackQuery($callbackId);

        if ('main_menu' === $data) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::MAIN_MENU, $this->keyboardFactory->mainMenu($isAdmin));

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

    private function handleStart(string $chatId, bool $isAdmin): void
    {
        $this->telegramApiClient->sendMessage($chatId, BotTexts::WELCOME, $this->keyboardFactory->mainMenu($isAdmin));
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

    private function handleAdminMenu(string $chatId): void
    {
        $this->telegramApiClient->sendMessage($chatId, 'منوی مدیریت:', $this->keyboardFactory->adminMenu());
    }

    private function handleAdminPendingPayments(string $chatId): void
    {
        $payments = $this->entityManager->getRepository(Payment::class)->findBy([
            'status' => [PaymentStatus::SUBMITTED, PaymentStatus::PENDING],
        ], ['id' => 'DESC'], 10);

        if ([] === $payments) {
            $this->telegramApiClient->sendMessage($chatId, 'پرداخت در انتظاری یافت نشد.', $this->keyboardFactory->backToAdminMenu());

            return;
        }

        $lines = ["پرداختهای در انتظار:\n"];
        $paymentIds = [];
        foreach ($payments as $payment) {
            $paymentIds[] = (int) $payment->getId();
            $order = $payment->getOrder();
            $telegramAccount = $order->getUser()->getTelegramAccount();
            $tracking = $payment->getTrackingCode() ?: '-';
            $lines[] = sprintf(
                "#%d | سفارش #%d\nکاربر: %s\nپلن: %s\nمبلغ: %d\nوضعیت: %s\nکد پیگیری: %s\n",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $tracking
            );
        }

        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->adminPendingPayments($paymentIds));
    }

    private function handleAdminViewPayment(string $chatId, int $paymentId): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_NOT_FOUND);

            return;
        }

        $order = $payment->getOrder();
        $telegramAccount = $order->getUser()->getTelegramAccount();
        $detail = sprintf(
            "Payment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان\nStatus: %s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
            $payment->getId(),
            $order->getId(),
            $this->formatTelegramIdentity($telegramAccount),
            $order->getPlan()->getTitle(),
            $payment->getAmount(),
            $payment->getStatus(),
            $payment->getTrackingCode() ?: '-',
            $payment->getReceiptMessage() ?: '-',
            $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
        );
        $this->telegramApiClient->sendMessage($chatId, $detail, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));

        $receiptFileId = (string) ($payment->getReceiptFileId() ?? '');
        if ('' === $receiptFileId) {
            $this->debugLog(sprintf('admin_view_payment_missing_receipt_file payment_id=%d', $paymentId));

            return;
        }

        $caption = sprintf(
            "رسید پرداخت\nPayment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان",
            $payment->getId(),
            $order->getId(),
            $this->formatTelegramIdentity($telegramAccount),
            $order->getPlan()->getTitle(),
            $payment->getAmount()
        );

        $this->telegramApiClient->sendPhoto($chatId, $receiptFileId, $caption, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));
        $this->debugLog(sprintf('admin_view_payment_sent_receipt_photo payment_id=%d', $paymentId));
    }

    private function handleAdminConfirmPayment(string $chatId, int $paymentId): void
    {
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

    private function handleAdminRejectPayment(string $chatId, int $paymentId): void
    {
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

    private function handleAdminUsers(string $chatId): void
    {
        $accounts = $this->entityManager->getRepository(TelegramAccount::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $accounts) {
            $this->telegramApiClient->sendMessage($chatId, 'کاربر تلگرامی یافت نشد.', $this->keyboardFactory->backToAdminMenu());

            return;
        }

        $lines = ["آخرین کاربران:\n"];
        foreach ($accounts as $account) {
            $lines[] = sprintf(
                "User ID: %d\nTelegram ID: %s\nUsername: @%s\nFirst name: %s\nLast activity: %s\n",
                $account->getUser()->getId(),
                $account->getTelegramId(),
                $account->getUsername() ?: '-',
                $account->getFirstName() ?: '-',
                $account->getLastActivityAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        }

        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->backToAdminMenu());
    }

    private function handleAdminServices(string $chatId): void
    {
        $services = $this->entityManager->getRepository(VpnService::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $services) {
            $this->telegramApiClient->sendMessage($chatId, 'سرویسی یافت نشد.', $this->keyboardFactory->backToAdminMenu());

            return;
        }

        $lines = ["آخرین سرویسها:\n"];
        foreach ($services as $service) {
            $subscriptionUrl = $service->getSubscriptionUrl() ?: '-';
            if (mb_strlen($subscriptionUrl) > 60) {
                $subscriptionUrl = mb_substr($subscriptionUrl, 0, 60).'...';
            }
            $lines[] = sprintf(
                "Service ID: %d\nUser: %s\nStatus: %s\nExpires at: %s\nSubscription: %s\n",
                $service->getId(),
                $this->formatTelegramIdentity($service->getUser()->getTelegramAccount()),
                $service->getStatus(),
                $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? '-',
                $subscriptionUrl
            );
        }

        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->backToAdminMenu());
    }

    private function handleAdminOrders(string $chatId): void
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $orders) {
            $this->telegramApiClient->sendMessage($chatId, 'سفارشی یافت نشد.', $this->keyboardFactory->backToAdminMenu());

            return;
        }

        $lines = ["آخرین سفارشها:\n"];
        foreach ($orders as $order) {
            $lines[] = sprintf(
                "Order ID: %d\nUser: %s\nPlan: %s\nAmount: %d\nStatus: %s\nCreated at: %s\n",
                $order->getId(),
                $this->formatTelegramIdentity($order->getUser()->getTelegramAccount()),
                $order->getPlan()->getTitle(),
                $order->getAmount(),
                $order->getStatus(),
                $order->getCreatedAt()->format('Y-m-d H:i:s')
            );
        }

        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->backToAdminMenu());
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
        $message = sprintf(
            "پرداخت جدید ثبت شد\n\nPayment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان\nTracking: %s\nReceipt message: %s",
            $payment->getId(),
            $order->getId(),
            $this->formatTelegramIdentity($telegramAccount),
            $order->getPlan()->getTitle(),
            $payment->getAmount(),
            $payment->getTrackingCode() ?: '-',
            $payment->getReceiptMessage() ?: '-'
        );

        if ('receipt_photo' === $kind) {
            $receiptFileId = (string) ($payment->getReceiptFileId() ?? '');
            if ('' !== $receiptFileId) {
                $this->telegramApiClient->sendPhoto(
                    $this->adminChatId,
                    $receiptFileId,
                    $message,
                    $this->keyboardFactory->adminPaymentActions((int) $payment->getId())
                );
                $this->debugLog(sprintf('notify_admin_sent_receipt_photo payment_id=%d', $payment->getId()));

                return;
            }

            $this->debugLog(sprintf('notify_admin_missing_receipt_file payment_id=%d', $payment->getId()));
        }

        $this->telegramApiClient->sendMessage($this->adminChatId, $message, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));
    }

    private function isAdminUserId(string $actorId): bool
    {
        if (null === $this->adminChatId || '' === trim($this->adminChatId)) {
            return false;
        }

        $adminChatId = trim($this->adminChatId);

        return $actorId === $adminChatId;
    }

    private function formatTelegramIdentity(?TelegramAccount $telegramAccount): string
    {
        if (!$telegramAccount instanceof TelegramAccount) {
            return '@- / -';
        }

        return sprintf('@%s / %s', $telegramAccount->getUsername() ?: '-', $telegramAccount->getTelegramId());
    }

    private function debugLog(string $message): void
    {
        error_log('[TelegramUpdateHandler] '.$message);
    }
}
