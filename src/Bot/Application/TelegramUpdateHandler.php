<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Application\ServiceAction\ServiceActionContext;
use App\Bot\Application\ServiceAction\ServiceActionResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\TelegramAccount;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUpdateHandler
{
    private const BUTTON_BUY_SERVICE = '🛒 خرید سرویس';
    private const BUTTON_MY_SERVICES = '📦 سرویسهای من';
    private const BUTTON_SUPPORT = '🎧 پشتیبانی';
    private const BUTTON_ADMIN_MENU = '🛠 مدیریت';
    private const STEP_WAITING_CUSTOM_USERNAME = 'waiting_custom_username';
    private const STEP_WAITING_CUSTOM_TRAFFIC = 'waiting_custom_traffic';
    private const STEP_WAITING_CUSTOM_DURATION = 'waiting_custom_duration';

    public function __construct(
        private readonly TelegramUserResolver $telegramUserResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly ServiceManagementService $serviceManagementService,
        private readonly ServiceActionResolver $serviceActionResolver,
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

        if (self::BUTTON_BUY_SERVICE === $text) {
            $this->handleBuyService($chatId);

            return;
        }

        if (self::BUTTON_MY_SERVICES === $text) {
            $this->serviceManagementService->handleMyServices($account, $chatId);

            return;
        }

        if (self::BUTTON_SUPPORT === $text) {
            $this->handleSupport($chatId);

            return;
        }

        if (self::BUTTON_ADMIN_MENU === $text) {
            if (!$isAdmin) {
                $this->debugLog(sprintf('admin_text_unauthorized actor_id="%s"', $actorId));
                $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_UNAUTHORIZED, $this->keyboardFactory->mainReplyKeyboard(false));

                return;
            }

            $this->handleAdminMenu($chatId);

            return;
        }

        $activeDraft = $this->findActiveOrderDraft($account);
        if ('' !== $text && $activeDraft instanceof OrderDraft) {
            $this->handleCustomOrderDraftTextInput($account, $activeDraft, $text, $chatId);

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
            $this->telegramApiClient->sendMessage($chatId, BotTexts::UNKNOWN_COMMAND, $this->keyboardFactory->mainReplyKeyboard($isAdmin));
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

        $account = $this->telegramUserResolver->resolveFromTelegramUser($telegramUser);

        if (str_starts_with($data, 'admin_')) {
            if (!$this->isAdminUserId($actorId)) {
                $this->debugLog(sprintf('admin_callback_unauthorized data="%s" actor_id="%s" chat_id="%s"', $data, $actorId, $chatId));
                $this->telegramApiClient->answerCallbackQuery($callbackId, BotTexts::ADMIN_UNAUTHORIZED, true);

                return;
            }

            $this->debugLog(sprintf('admin_callback_execute data="%s" actor_id="%s"', $data, $actorId));

            if ('admin_menu' === $data) {
                $this->handleAdminMenu($chatId, $callbackId);

                return;
            }

            if ('admin_pending_payments' === $data) {
                $this->handleAdminPendingPayments($chatId, $callbackId);

                return;
            }

            if ('admin_users' === $data) {
                $this->handleAdminUsers($chatId, $callbackId);

                return;
            }

            if ('admin_orders' === $data) {
                $this->handleAdminOrders($chatId, $callbackId);

                return;
            }

            if (str_starts_with($data, 'admin_view_payment:')) {
                $this->handleAdminViewPayment($chatId, (int) str_replace('admin_view_payment:', '', $data), $callbackId);

                return;
            }

            if (str_starts_with($data, 'admin_confirm_payment:')) {
                $this->handleAdminConfirmPayment($chatId, (int) str_replace('admin_confirm_payment:', '', $data), $callbackId);

                return;
            }

            if (str_starts_with($data, 'admin_reject_payment:')) {
                $this->handleAdminRejectPayment($chatId, (int) str_replace('admin_reject_payment:', '', $data), $callbackId);

                return;
            }

            if ($this->serviceActionResolver->dispatch(new ServiceActionContext(
                account: $account,
                actorId: $actorId,
                chatId: $chatId,
                callbackId: $callbackId,
                data: $data,
                isAdmin: true,
            ))) {
                return;
            }

            $this->acknowledgeCallback($callbackId);
            $this->debugLog(sprintf('admin_callback_unknown data="%s"', $data));

            return;
        }

        $isAdmin = $this->isAdminUserId($actorId);

        if ('main_menu' === $data) {
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, BotTexts::MAIN_MENU, $this->keyboardFactory->mainReplyKeyboard($isAdmin));

            return;
        }

        if ('buy_service' === $data) {
            $this->handleBuyService($chatId, $callbackId);

            return;
        }

        if (str_starts_with($data, 'select_plan:')) {
            $this->handleSelectPlan($account, $chatId, (int) str_replace('select_plan:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'custom_order_confirm:')) {
            $this->handleCustomOrderConfirm($account, $chatId, (int) str_replace('custom_order_confirm:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'custom_order_cancel:')) {
            $this->handleCustomOrderCancel($account, $chatId, (int) str_replace('custom_order_cancel:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'select_payment_method:')) {
            $this->handleSelectPaymentMethod($account, $chatId, $data, $callbackId);

            return;
        }

        if (str_starts_with($data, 'select_payment_method_draft:')) {
            $this->handleSelectPaymentMethodFromDraft($account, $chatId, $data, $callbackId);

            return;
        }

        if (str_starts_with($data, 'payment_submit_receipt:')) {
            $this->handlePaymentSubmitReceipt($account, $chatId, (int) str_replace('payment_submit_receipt:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'payment_cancel:')) {
            $this->handlePaymentCancel($account, $chatId, (int) str_replace('payment_cancel:', '', $data), $callbackId);

            return;
        }

        if ('support' === $data) {
            $this->handleSupport($chatId, $callbackId);

            return;
        }

        if ($this->serviceActionResolver->dispatch(new ServiceActionContext(
            account: $account,
            actorId: $actorId,
            chatId: $chatId,
            callbackId: $callbackId,
            data: $data,
            isAdmin: $isAdmin,
        ))) {
            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->debugLog(sprintf('callback_unknown data="%s"', $data));
    }

    private function handleStart(string $chatId, bool $isAdmin): void
    {
        $this->telegramApiClient->sendMessage($chatId, BotTexts::WELCOME, $this->keyboardFactory->mainReplyKeyboard($isAdmin));
    }

    private function handleBuyService(string $chatId, ?string $callbackId = null): void
    {
        $plans = $this->entityManager->getRepository(Plan::class)->findBy(['isActive' => true], ['id' => 'ASC']);
        if ([] === $plans) {
            $this->showPopupOrMessage($chatId, $callbackId, 'در حال حاضر سرویسی برای خرید موجود نیست.', 'popup_no_active_plans');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, BotTexts::SELECT_PLAN, $this->keyboardFactory->plansMenu($plans));
    }

    private function handleSelectPlan(TelegramAccount $account, string $chatId, int $planId, ?string $callbackId = null): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($planId);
        if (!$plan instanceof Plan || !$plan->isActive()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan');

            return;
        }

        $this->startCustomOrderDraft($account, $plan, $chatId, $callbackId);
    }

    private function startCustomOrderDraft(TelegramAccount $account, Plan $plan, string $chatId, ?string $callbackId = null): void
    {
        $this->expireUserDrafts($account);

        $draft = $this->findActiveOrderDraft($account);
        if (!$draft instanceof OrderDraft || $draft->getPlan()->getId() !== $plan->getId()) {
            $draft = (new OrderDraft())
                ->setUser($account->getUser())
                ->setPlan($plan)
                ->setStatus(OrderDraftStatus::PENDING)
                ->setStep(self::STEP_WAITING_CUSTOM_USERNAME)
                ->setExpiresAt((new \DateTimeImmutable())->modify('+1 hour'));
            $this->entityManager->persist($draft);
        }
        $this->debugLog(sprintf('order_draft_start user_id=%d plan_id=%d draft_id=%d', $account->getUser()->getId() ?? 0, $plan->getId() ?? 0, $draft->getId() ?? 0));

        $draft
            ->setStatus(OrderDraftStatus::PENDING)
            ->setStep(self::STEP_WAITING_CUSTOM_USERNAME)
            ->setCustomUsernamePrefix(null)
            ->setFinalUsername(null)
            ->setTrafficGb(null)
            ->setDurationDays(null)
            ->setCalculatedAmount(null)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            'لطفاً نام دلخواه اکانت را وارد کنید.',
            $this->keyboardFactory->customOrderInputMenu((int) ($draft->getId() ?? 0))
        );
    }

    private function handleCustomOrderDraftTextInput(TelegramAccount $account, OrderDraft $draft, string $text, string $chatId): void
    {
        $plan = $draft->getPlan();
        if (OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return;
        }
        if ($draft->getUser()->getId() !== $account->getUser()->getId()) {
            return;
        }
        if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->telegramApiClient->sendMessage($chatId, 'این درخواست منقضی شده است. دوباره از بخش خرید شروع کنید.');

            return;
        }

        if (self::STEP_WAITING_CUSTOM_USERNAME === $draft->getStep()) {
            $prefix = $this->normalizeUsernamePrefix($text);
            if (null === $prefix) {
                $this->telegramApiClient->sendMessage($chatId, 'نام نامعتبر است. فقط a-z, 0-9 و _ با طول 3 تا 24 مجاز است.');

                return;
            }

            $draft
                ->setCustomUsernamePrefix($prefix)
                ->setFinalUsername($this->buildFinalUsername($prefix))
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->debugLog(sprintf('order_draft_username_set draft_id=%d prefix="%s" final="%s"', $draft->getId() ?? 0, $prefix, (string) $draft->getFinalUsername()));

            if ($this->shouldAskCustomTraffic($plan)) {
                $draft->setStep(self::STEP_WAITING_CUSTOM_TRAFFIC)->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftTrafficPrompt($chatId, $draft);

                return;
            }

            $draft->setTrafficGb($this->resolveDefaultTrafficGb($plan));

            if ($this->shouldAskCustomDuration($plan)) {
                $draft->setStep(self::STEP_WAITING_CUSTOM_DURATION)->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftDurationPrompt($chatId, $draft);

                return;
            }

            $durationDays = $this->resolveDefaultDurationDays($plan);
            $trafficGb = $draft->getTrafficGb();
            $amount = $this->calculateDraftAmount($plan, $trafficGb, $durationDays);
            $draft
                ->setDurationDays($durationDays)
                ->setCalculatedAmount($amount)
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->sendCustomDraftSummary($chatId, $draft);

            return;
        }

        if (self::STEP_WAITING_CUSTOM_TRAFFIC === $draft->getStep()) {
            $traffic = $this->parsePositiveInt($text);
            $minTraffic = $plan->getMinTrafficGb();
            $maxTraffic = $plan->getMaxTrafficGb();
            if (null === $traffic || null === $minTraffic || null === $maxTraffic || $traffic < $minTraffic || $traffic > $maxTraffic) {
                $this->telegramApiClient->sendMessage($chatId, sprintf('حجم نامعتبر است. عدد صحیح بین %d تا %d وارد کنید.', (int) ($minTraffic ?? 0), (int) ($maxTraffic ?? 0)));

                return;
            }

            $draft->setTrafficGb($traffic)->setUpdatedAt(new \DateTimeImmutable());
            $this->debugLog(sprintf('order_draft_traffic_set draft_id=%d traffic=%d', $draft->getId() ?? 0, $traffic));

            if ($this->shouldAskCustomDuration($plan)) {
                $draft->setStep(self::STEP_WAITING_CUSTOM_DURATION)->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftDurationPrompt($chatId, $draft);

                return;
            }

            $durationDays = $this->resolveDefaultDurationDays($plan);
            $amount = $this->calculateDraftAmount($plan, $draft->getTrafficGb(), $durationDays);
            $draft
                ->setDurationDays($durationDays)
                ->setCalculatedAmount($amount)
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->sendCustomDraftSummary($chatId, $draft);

            return;
        }

        if (self::STEP_WAITING_CUSTOM_DURATION === $draft->getStep()) {
            $duration = $this->parsePositiveInt($text);
            $minDuration = $plan->getMinDurationDays();
            $maxDuration = $plan->getMaxDurationDays();
            if (null === $duration || null === $minDuration || null === $maxDuration || $duration < $minDuration || $duration > $maxDuration) {
                $this->telegramApiClient->sendMessage($chatId, sprintf('مدت نامعتبر است. عدد صحیح بین %d تا %d وارد کنید.', (int) ($minDuration ?? 0), (int) ($maxDuration ?? 0)));

                return;
            }
            $traffic = (int) ($draft->getTrafficGb() ?? 0);
            if ($traffic <= 0) {
                $draft->setStep(self::STEP_WAITING_CUSTOM_TRAFFIC)->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftTrafficPrompt($chatId, $draft);

                return;
            }

            $amount = $this->calculateDraftAmount($plan, $traffic, $duration);
            $draft
                ->setDurationDays($duration)
                ->setCalculatedAmount($amount)
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->debugLog(sprintf('order_draft_duration_set draft_id=%d duration=%d amount=%d', $draft->getId() ?? 0, $duration, $amount));
            $this->sendCustomDraftSummary($chatId, $draft);
        }
    }

    private function sendCustomDraftTrafficPrompt(string $chatId, OrderDraft $draft): void
    {
        $plan = $draft->getPlan();
        $this->telegramApiClient->sendMessage(
            $chatId,
            sprintf('حجم موردنظر را وارد کنید. حداقل %d گیگ و حداکثر %d گیگ.', (int) ($plan->getMinTrafficGb() ?? 0), (int) ($plan->getMaxTrafficGb() ?? 0)),
            $this->keyboardFactory->customOrderInputMenu((int) ($draft->getId() ?? 0))
        );
    }

    private function sendCustomDraftDurationPrompt(string $chatId, OrderDraft $draft): void
    {
        $plan = $draft->getPlan();
        $this->telegramApiClient->sendMessage(
            $chatId,
            sprintf('مدت زمان موردنظر را وارد کنید. حداقل %d روز و حداکثر %d روز.', (int) ($plan->getMinDurationDays() ?? 0), (int) ($plan->getMaxDurationDays() ?? 0)),
            $this->keyboardFactory->customOrderInputMenu((int) ($draft->getId() ?? 0))
        );
    }

    private function sendCustomDraftSummary(string $chatId, OrderDraft $draft): void
    {
        $plan = $draft->getPlan();
        $durationText = null === $draft->getDurationDays() || $plan->isUnlimitedDuration()
            ? 'نامحدود'
            : sprintf('%d روز', (int) $draft->getDurationDays());
        $summary = sprintf(
            "خلاصه سفارش:\nنام اکانت: %s\nنام نهایی: %s\nحجم: %s گیگ\nمدت: %s\nمبلغ قابل پرداخت: %d تومان",
            (string) ($draft->getCustomUsernamePrefix() ?? '-'),
            (string) ($draft->getFinalUsername() ?? '-'),
            null === $draft->getTrafficGb() ? '-' : (string) $draft->getTrafficGb(),
            $durationText,
            (int) ($draft->getCalculatedAmount() ?? 0)
        );
        $summary = sprintf(
            "پلن: %s\n%s",
            $plan->getTitle(),
            $summary
        );
        $this->telegramApiClient->sendMessage($chatId, $summary, $this->keyboardFactory->customOrderSummaryMenu((int) ($draft->getId() ?? 0)));
    }

    private function handleCustomOrderConfirm(TelegramAccount $account, string $chatId, int $draftId, ?string $callbackId = null): void
    {
        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (!$draft instanceof OrderDraft || $draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'درخواست نامعتبر است.', 'popup_custom_order_confirm_invalid');

            return;
        }

        if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->showPopupOrMessage($chatId, $callbackId, 'این درخواست منقضی شده است.', 'popup_custom_order_confirm_expired');

            return;
        }

        if (null === $draft->getCalculatedAmount() || $draft->getCalculatedAmount() <= 0 || null === $draft->getFinalUsername()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'اطلاعات سفارش ناقص است.', 'popup_custom_order_confirm_incomplete');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'لطفاً روش پرداخت را انتخاب کنید:', $this->keyboardFactory->paymentMethodSelectionForDraftMenu($draftId));
    }

    private function handleCustomOrderCancel(TelegramAccount $account, string $chatId, int $draftId, ?string $callbackId = null): void
    {
        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if ($draft instanceof OrderDraft && $draft->getUser()->getId() === $account->getUser()->getId()) {
            $draft->setStatus(OrderDraftStatus::CANCELLED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'سفارش سفارشی لغو شد.', $this->keyboardFactory->mainReplyKeyboard(false));
    }

    private function handleSelectPaymentMethodFromDraft(TelegramAccount $account, string $chatId, string $data, ?string $callbackId = null): void
    {
        $parts = explode(':', $data);
        if (3 !== count($parts)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_method_draft_callback');

            return;
        }

        $draftId = (int) $parts[1];
        $paymentMethod = $parts[2];
        if ('manual_card' !== $paymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_method_draft');

            return;
        }

        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (!$draft instanceof OrderDraft || $draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پیش‌نویس سفارش نامعتبر است.', 'popup_invalid_custom_draft_for_payment');

            return;
        }

        $plan = $draft->getPlan();
        if (!$plan->isActive()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan_select_payment_method_draft');

            return;
        }

        $amount = (int) ($draft->getCalculatedAmount() ?? 0);
        $metadata = [
            'custom' => true,
            'customUsername' => $draft->getCustomUsernamePrefix(),
            'customUsernamePrefix' => $draft->getCustomUsernamePrefix(),
            'finalUsername' => $draft->getFinalUsername(),
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
            'unlimitedDuration' => $plan->isUnlimitedDuration() || null === $draft->getDurationDays(),
            'calculatedAmount' => $amount,
            'orderDraftId' => $draft->getId(),
        ];

        $order = (new Order())
            ->setUser($account->getUser())
            ->setPlan($plan)
            ->setAmount($amount)
            ->setType(OrderType::NEW_SERVICE)
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);

        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($paymentMethod)
            ->setAmount($amount)
            ->setStatus(PaymentStatus::PENDING);

        $draft
            ->setStatus(OrderDraftStatus::CONFIRMED)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($order);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
        $this->debugLog(sprintf('custom_order_confirmed draft_id=%d order_id=%d payment_id=%d amount=%d', $draft->getId() ?? 0, $order->getId() ?? 0, $payment->getId() ?? 0, $amount));

        $cardNumber = $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
        $cardHolder = $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
        $description = $this->settingValueProvider->get('payment.description', $this->paymentDescription);

        $message = sprintf(
            "پلن: %s\nنام کاربری: %s\nحجم: %s گیگ\nمدت: %s\nمبلغ: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            $plan->getTitle(),
            (string) ($draft->getFinalUsername() ?? '-'),
            null === $draft->getTrafficGb() ? '-' : (string) $draft->getTrafficGb(),
            null === $draft->getDurationDays() || $plan->isUnlimitedDuration() ? 'نامحدود' : ((string) $draft->getDurationDays().' روز'),
            $amount,
            $cardNumber ?: '-',
            $cardHolder ?: '-',
            $description ? 'توضیحات: '.$description : ''
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId()));
    }

    private function handleSelectPaymentMethod(TelegramAccount $account, string $chatId, string $data, ?string $callbackId = null): void
    {
        $parts = explode(':', $data);
        if (3 !== count($parts)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_method_callback');

            return;
        }

        $planId = (int) $parts[1];
        $paymentMethod = $parts[2];

        if ('manual_card' !== $paymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_method');

            return;
        }

        $plan = $this->entityManager->getRepository(Plan::class)->find($planId);
        if (!$plan instanceof Plan || !$plan->isActive()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan_select_payment_method');

            return;
        }

        $order = (new Order())
            ->setUser($account->getUser())
            ->setPlan($plan)
            ->setAmount($plan->getPrice())
            ->setType(OrderType::NEW_SERVICE)
            ->setStatus(OrderStatus::WAITING_PAYMENT);

        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($paymentMethod)
            ->setAmount($plan->getPrice())
            ->setStatus(PaymentStatus::PENDING);

        $this->entityManager->persist($order);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $cardNumber = $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
        $cardHolder = $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
        $description = $this->settingValueProvider->get('payment.description', $this->paymentDescription);

        $message = sprintf(
            "پلن: %s\nمبلغ: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            $plan->getTitle(),
            $plan->getPrice(),
            $cardNumber ?: '-',
            $cardHolder ?: '-',
            $description ? 'توضیحات: '.$description : ''
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId()));
    }

    private function handlePaymentSubmitReceipt(TelegramAccount $account, string $chatId, int $paymentId, string $callbackId): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->showPopupOrMessage($chatId, $callbackId, BotTexts::ADMIN_PAYMENT_NOT_FOUND, 'popup_payment_submit_receipt_not_found');

            return;
        }

        if ($payment->getOrder()->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'popup_payment_submit_receipt_unauthorized');

            return;
        }

        if (PaymentStatus::SUBMITTED === $payment->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'رسید این پرداخت قبلاً ارسال شده است.', 'popup_payment_submit_receipt_already_submitted');

            return;
        }

        if (PaymentStatus::PENDING !== $payment->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان ارسال رسید برای این پرداخت وجود ندارد.', 'popup_payment_submit_receipt_invalid_status');

            return;
        }

        $payment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->entityManager->flush();

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'لطفاً تصویر رسید یا کد پیگیری را در همین چت ارسال کنید.', true);
    }

    private function handlePaymentCancel(TelegramAccount $account, string $chatId, int $paymentId, string $callbackId): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->showPopupOrMessage($chatId, $callbackId, BotTexts::ADMIN_PAYMENT_NOT_FOUND, 'popup_payment_cancel_not_found');

            return;
        }

        if ($payment->getOrder()->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'popup_payment_cancel_unauthorized');

            return;
        }

        if (in_array($payment->getStatus(), [PaymentStatus::SUBMITTED, PaymentStatus::CONFIRMED, PaymentStatus::REJECTED], true)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان لغو این پرداخت وجود ندارد.', 'popup_payment_cancel_not_allowed');

            return;
        }

        $payment->setStatus(PaymentStatus::REJECTED);
        $payment->getOrder()->setStatus(OrderStatus::CANCELLED);
        $this->entityManager->flush();

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'پرداخت لغو شد.', true);
    }

    private function handleMyServices(TelegramAccount $account, string $chatId, ?string $callbackId = null): void
    {
        $this->serviceManagementService->handleMyServices($account, $chatId, $callbackId);
    }

    private function handleSupport(string $chatId, ?string $callbackId = null): void
    {
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, BotTexts::SUPPORT, $this->keyboardFactory->backToMainMenu());
    }

    private function handleAdminMenu(string $chatId, ?string $callbackId = null): void
    {
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'منوی مدیریت:', $this->keyboardFactory->adminMenu());
    }

    private function handleAdminPendingPayments(string $chatId, ?string $callbackId = null): void
    {
        $payments = $this->entityManager->getRepository(Payment::class)->findBy([
            'status' => [PaymentStatus::SUBMITTED, PaymentStatus::PENDING],
        ], ['id' => 'DESC'], 10);

        if ([] === $payments) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت در انتظاری وجود ندارد.', 'popup_no_pending_payments');

            return;
        }

        $lines = ["پرداختهای در انتظار:\n"];
        $paymentIds = [];
        foreach ($payments as $payment) {
            $paymentIds[] = (int) $payment->getId();
            $order = $payment->getOrder();
            $telegramAccount = $order->getUser()->getTelegramAccount();
            $tracking = $payment->getTrackingCode() ?: '-';
            $meta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            $isRenewal = OrderType::RENEWAL === $order->getType();
            $renewalSuffix = '';
            if ($isRenewal) {
                $duration = true === ($meta['unlimitedDuration'] ?? false)
                    ? 'نامحدود'
                    : ((string) ($meta['durationDays'] ?? '-').' روز');
                $renewalSuffix = sprintf(
                    "\nتمدید سرویس #%s | حجم: %sGB | مدت: %s",
                    (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                    (string) ($meta['trafficGb'] ?? '-'),
                    $duration
                );
            }
            $lines[] = sprintf(
                "#%d | سفارش #%d\nکاربر: %s\nنوع سفارش: %s\nپلن: %s\nمبلغ: %d\nوضعیت: %s\nکد پیگیری: %s%s\n",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getType(),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $tracking,
                $renewalSuffix
            );
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->adminPendingPayments($paymentIds));
    }

    private function handleAdminViewPayment(string $chatId, int $paymentId, ?string $callbackId = null): void
    {
        $this->acknowledgeCallback($callbackId);
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_NOT_FOUND);

            return;
        }

        $order = $payment->getOrder();
        $telegramAccount = $order->getUser()->getTelegramAccount();
        $customMeta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $isRenewal = OrderType::RENEWAL === $order->getType();
        $durationText = true === ($customMeta['unlimitedDuration'] ?? false)
            ? 'نامحدود'
            : (string) ($customMeta['durationDays'] ?? '-');
        $customSummary = true === ($customMeta['custom'] ?? false)
            ? sprintf(
                "Custom: yes\nUsername: %s\nTraffic: %s GB\nDuration: %s\nCalculated amount: %s",
                (string) ($customMeta['finalUsername'] ?? '-'),
                (string) ($customMeta['trafficGb'] ?? '-'),
                $durationText,
                (string) ($customMeta['calculatedAmount'] ?? '-')
            )
            : "Custom: no";
        if ($isRenewal) {
            $detail = sprintf(
                "پرداخت تمدید سرویس\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nAmount: %d تومان\nDuration: %s\nTraffic: %s GB\nStatus: %s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                $payment->getAmount(),
                $durationText,
                (string) ($customMeta['trafficGb'] ?? '-'),
                $payment->getStatus(),
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        } else {
            $detail = sprintf(
                "Payment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان\nStatus: %s\n%s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $customSummary,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        }
        $this->telegramApiClient->sendMessage($chatId, $detail, $this->keyboardFactory->adminPaymentActions((int) $payment->getId()));

        $receiptFileId = (string) ($payment->getReceiptFileId() ?? '');
        if ('' === $receiptFileId) {
            $this->debugLog(sprintf('admin_view_payment_missing_receipt_file payment_id=%d', $paymentId));

            return;
        }

        $caption = $isRenewal
            ? sprintf(
                "رسید پرداخت تمدید سرویس\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nAmount: %d تومان",
                $payment->getId(),
                $order->getId(),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                $payment->getAmount()
            )
            : sprintf(
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

    private function handleAdminConfirmPayment(string $chatId, int $paymentId, ?string $callbackId = null): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_NOT_FOUND);

            return;
        }

        $result = $this->paymentConfirmationService->confirm($payment, 'telegram_payment_approval');
        $this->debugLog(sprintf(
            'admin_confirm_result payment_id=%d processed=%s already_processed=%s message="%s"',
            $paymentId,
            $result->processed ? 'true' : 'false',
            $result->alreadyProcessed ? 'true' : 'false',
            $result->message
        ));
        if ($result->alreadyProcessed) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پرداخت قبلاً بررسی شده است.', 'popup_payment_already_processed_confirm');

            return;
        }

        if (!$result->processed) {
            $this->showPopupOrMessage($chatId, $callbackId, $result->message, 'popup_payment_confirm_panel_failed');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_CONFIRMED);
    }

    private function handleAdminRejectPayment(string $chatId, int $paymentId, ?string $callbackId = null): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->acknowledgeCallback($callbackId);
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
        if ($result->alreadyProcessed) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پرداخت قبلاً بررسی شده است.', 'popup_payment_already_processed_reject');

            return;
        }

        if (!$result->processed) {
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات انجام نشد.', 'popup_payment_reject_failed');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, BotTexts::ADMIN_PAYMENT_REJECTED);
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

    private function handleAdminUsers(string $chatId, ?string $callbackId = null): void
    {
        $accounts = $this->entityManager->getRepository(TelegramAccount::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $accounts) {
            $this->showPopupOrMessage($chatId, $callbackId, 'کاربری برای نمایش وجود ندارد.', 'popup_no_users');

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

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->backToAdminMenu());
    }

    private function handleAdminServices(string $chatId, ?string $callbackId = null): void
    {
        $this->serviceManagementService->handleAdminServices($chatId, $callbackId);
    }

    private function handleAdminOrders(string $chatId, ?string $callbackId = null): void
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $orders) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارشی برای نمایش وجود ندارد.', 'popup_no_orders');

            return;
        }

        $lines = ["آخرین سفارشها:\n"];
        foreach ($orders as $order) {
            $meta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            $durationText = true === ($meta['unlimitedDuration'] ?? false)
                ? 'نامحدود'
                : (string) ($meta['durationDays'] ?? '-');
            $customInfo = true === ($meta['custom'] ?? false)
                ? sprintf(
                    " | custom: yes | username: %s | traffic: %sGB | duration: %s | calc: %s",
                    (string) ($meta['finalUsername'] ?? '-'),
                    (string) ($meta['trafficGb'] ?? '-'),
                    $durationText,
                    (string) ($meta['calculatedAmount'] ?? '-')
                )
                : ' | custom: no';
            $lines[] = sprintf(
                "Order ID: %d\nUser: %s\nPlan: %s\nAmount: %d\nStatus: %s%s\nCreated at: %s\n",
                $order->getId(),
                $this->formatTelegramIdentity($order->getUser()->getTelegramAccount()),
                $order->getPlan()->getTitle(),
                $order->getAmount(),
                $order->getStatus(),
                $customInfo,
                $order->getCreatedAt()->format('Y-m-d H:i:s')
            );
        }

        $this->acknowledgeCallback($callbackId);
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

    private function findActiveOrderDraft(TelegramAccount $account): ?OrderDraft
    {
        $this->expireUserDrafts($account);

        return $this->entityManager->getRepository(OrderDraft::class)
            ->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')
            ->setParameter('user', $account->getUser())
            ->setParameter('status', OrderDraftStatus::PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('d.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function expireUserDrafts(TelegramAccount $account): void
    {
        $drafts = $this->entityManager->getRepository(OrderDraft::class)
            ->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NOT NULL AND d.expiresAt <= :now')
            ->setParameter('user', $account->getUser())
            ->setParameter('status', OrderDraftStatus::PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        if ([] === $drafts) {
            return;
        }

        foreach ($drafts as $draft) {
            if ($draft instanceof OrderDraft) {
                $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            }
        }
        $this->entityManager->flush();
    }

    private function parsePositiveInt(string $text): ?int
    {
        $value = trim($text);
        if (!preg_match('/^\d+$/', $value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function shouldAskCustomTraffic(Plan $plan): bool
    {
        return $this->hasCustomTrafficPricing($plan)
            && $plan->getMinTrafficGb() !== $plan->getMaxTrafficGb();
    }

    private function hasCustomTrafficPricing(Plan $plan): bool
    {
        return $plan->isCustomizable()
            && null !== $plan->getMinTrafficGb()
            && null !== $plan->getMaxTrafficGb()
            && (int) ($plan->getPricePerGb() ?? 0) > 0;
    }

    private function shouldAskCustomDuration(Plan $plan): bool
    {
        return !$plan->isUnlimitedDuration()
            && $this->hasCustomDurationPricing($plan)
            && $plan->getMinDurationDays() !== $plan->getMaxDurationDays();
    }

    private function hasCustomDurationPricing(Plan $plan): bool
    {
        return $plan->isCustomizable()
            && null !== $plan->getMinDurationDays()
            && null !== $plan->getMaxDurationDays()
            && (int) ($plan->getPricePerDay() ?? 0) > 0;
    }

    private function resolveDefaultTrafficGb(Plan $plan): ?int
    {
        if ($this->hasCustomTrafficPricing($plan) && $plan->getMinTrafficGb() === $plan->getMaxTrafficGb()) {
            return $plan->getMinTrafficGb();
        }

        return $plan->getTrafficGb();
    }

    private function resolveDefaultDurationDays(Plan $plan): ?int
    {
        if ($plan->isUnlimitedDuration()) {
            return null;
        }

        if (null !== $plan->getMinDurationDays() && $plan->getMinDurationDays() === $plan->getMaxDurationDays()) {
            return $plan->getMinDurationDays();
        }

        return $plan->getDurationDays();
    }

    private function calculateDraftAmount(Plan $plan, ?int $trafficGb, ?int $durationDays): int
    {
        if (!$plan->isCustomizable()) {
            return $plan->getPrice();
        }

        $amount = 0;
        $pricePerGb = (int) ($plan->getPricePerGb() ?? 0);
        $pricePerDay = (int) ($plan->getPricePerDay() ?? 0);

        if ($this->hasCustomTrafficPricing($plan) && $pricePerGb > 0 && null !== $trafficGb) {
            $amount += max(0, $trafficGb) * $pricePerGb;
        }

        if (!$plan->isUnlimitedDuration() && $this->hasCustomDurationPricing($plan) && $pricePerDay > 0 && null !== $durationDays) {
            $amount += max(0, $durationDays) * $pricePerDay;
        }

        return $amount > 0 ? $amount : $plan->getPrice();
    }

    private function normalizeUsernamePrefix(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
        if (!preg_match('/^[a-z0-9_]{3,24}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function buildFinalUsername(string $prefix): string
    {
        return sprintf('%s_%d', $prefix, random_int(10000, 99999));
    }

    private function notifyAdmin(Payment $payment, string $kind): void
    {
        if (null === $this->adminChatId || '' === trim($this->adminChatId)) {
            return;
        }

        $order = $payment->getOrder();
        $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
        $meta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $isRenewal = OrderType::RENEWAL === $order->getType();
        $durationText = true === ($meta['unlimitedDuration'] ?? false)
            ? 'نامحدود'
            : sprintf('%s روز', (string) ($meta['durationDays'] ?? '-'));
        $customSummary = true === ($meta['custom'] ?? false)
            ? sprintf("Custom: yes\nUsername: %s\nTraffic: %s GB\nDuration: %s\nCalculated amount: %s\n", (string) ($meta['finalUsername'] ?? '-'), (string) ($meta['trafficGb'] ?? '-'), $durationText, (string) ($meta['calculatedAmount'] ?? '-'))
            : "Custom: no\n";
        if ($isRenewal) {
            $message = sprintf(
                "پرداخت تمدید سرویس\n\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nAmount: %d تومان\nRenewal duration: %s\nRenewal traffic: %s GB\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                $payment->getAmount(),
                $durationText,
                (string) ($meta['trafficGb'] ?? '-'),
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        } else {
            $message = sprintf(
                "پرداخت جدید ثبت شد\n\nPayment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان\n%sTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $customSummary,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        }

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

    private function acknowledgeCallback(?string $callbackId): void
    {
        if (null === $callbackId || '' === $callbackId) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId);
    }

    private function showPopupOrMessage(string $chatId, ?string $callbackId, string $text, string $logKey): void
    {
        if (null !== $callbackId && '' !== $callbackId) {
            $this->debugLog(sprintf('%s callback_alert="%s"', $logKey, $text));
            $this->telegramApiClient->answerCallbackQuery($callbackId, $text, true);

            return;
        }

        $this->debugLog(sprintf('%s text_message="%s"', $logKey, $text));
        $this->telegramApiClient->sendMessage($chatId, $text);
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
