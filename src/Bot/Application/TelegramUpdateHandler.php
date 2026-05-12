<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Application\ServiceAction\ServiceActionContext;
use App\Bot\Application\ServiceAction\ServiceActionResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\TelegramAccount;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Application\PlanPricingService;
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
    private const STEP_WAITING_DISCOUNT_DECISION = 'waiting_discount_decision';
    private const STEP_WAITING_DISCOUNT_CODE = 'waiting_discount_code';

    public function __construct(
        private readonly TelegramUserResolver $telegramUserResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly ServiceManagementService $serviceManagementService,
        private readonly ServiceActionResolver $serviceActionResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentConfirmationService $paymentConfirmationService,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly PlanPricingService $planPricingService,
        private readonly DiscountCodeService $discountCodeService,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly StorePaymentMethodResolver $storePaymentMethodResolver,
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
        if ('' !== $text) {
            $waitingOrder = $this->findWaitingDiscountCodeOrder($account);
            if ($waitingOrder instanceof Order) {
                $this->handleOrderDiscountCodeInput($account, $waitingOrder, $text, $chatId);

                return;
            }
        }

        if ('' !== $text && $activeDraft instanceof OrderDraft) {
            if (ServiceManagementService::STEP_WAITING_ADD_TRAFFIC_AMOUNT === $activeDraft->getStep()) {
                $this->serviceManagementService->handleAddTrafficDraftAmountInput($account, $activeDraft, $text, $chatId);

                return;
            }

            if (ServiceManagementService::STEP_WAITING_DISCOUNT_CODE === $activeDraft->getStep()) {
                $data = is_array($activeDraft->getData()) ? $activeDraft->getData() : [];
                $draftType = (string) ($data['draftType'] ?? '');
                if (in_array($draftType, ['renewal', 'add_traffic'], true)) {
                    $this->serviceManagementService->handleDiscountCodeInput($account, $activeDraft, $text, $chatId);

                    return;
                }
            }

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

        if (str_starts_with($data, 'select_store_payment_method:')) {
            $this->handleSelectStorePaymentMethod($account, $chatId, $data, $callbackId);

            return;
        }

        if (str_starts_with($data, 'select_payment_gateway:')) {
            $this->handleSelectPaymentGateway($account, $chatId, $data, $callbackId);

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

        if (str_starts_with($data, 'payment_check:')) {
            $this->handlePaymentCheck($account, $chatId, (int) str_replace('payment_check:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'discount_enter:')) {
            $this->handleDiscountEnter($account, $chatId, (int) str_replace('discount_enter:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'discount_skip:')) {
            $this->handleDiscountSkip($account, $chatId, (int) str_replace('discount_skip:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'discount_enter_order:')) {
            $this->handleDiscountEnterOrder($account, $chatId, (int) str_replace('discount_enter_order:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'apply_discount_order:')) {
            $this->handleDiscountEnterOrder($account, $chatId, (int) str_replace('apply_discount_order:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'discount_skip_order:')) {
            $this->handleDiscountSkipOrder($account, $chatId, (int) str_replace('discount_skip_order:', '', $data), $callbackId);

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
            ->setDiscountCode(null)
            ->setDiscountAmount(null)
            ->setFinalAmount(null)
            ->setPriceSnapshot(null)
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

        if (self::STEP_WAITING_DISCOUNT_CODE === $draft->getStep()) {
            $data = is_array($draft->getData()) ? $draft->getData() : [];
            if ('new_service' === (string) ($data['draftType'] ?? '')) {
                $this->handleNewServiceDraftDiscountCodeInput($account, $draft, $text, $chatId);
            }

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
            $amount = $this->planPricingService->calculateNewOrderAmount($plan, [
                'trafficGb' => $trafficGb,
                'durationDays' => $durationDays,
            ]);
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
            $amount = $this->planPricingService->calculateNewOrderAmount($plan, [
                'trafficGb' => $draft->getTrafficGb(),
                'durationDays' => $durationDays,
            ]);
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

            $amount = $this->planPricingService->calculateNewOrderAmount($plan, [
                'trafficGb' => $traffic,
                'durationDays' => $duration,
            ]);
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
        $price = $this->planPricingService->calculateNewOrderPrice($plan, [
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
        ]);
        $durationText = null === $draft->getDurationDays() || $plan->isUnlimitedDuration()
            ? 'نامحدود'
            : sprintf('%d روز', (int) $draft->getDurationDays());
        $discountLine = sprintf(
            "\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: -\nمبلغ نهایی: %d تومان",
            $price->baseAmount,
            $price->globalDiscountAmount,
            $price->finalAmount
        );
        $summary = sprintf(
            "خلاصه سفارش:\nنام اکانت: %s\nنام نهایی: %s\nحجم: %s گیگ\nمدت: %s%s",
            (string) ($draft->getCustomUsernamePrefix() ?? '-'),
            (string) ($draft->getFinalUsername() ?? '-'),
            null === $draft->getTrafficGb() ? '-' : (string) $draft->getTrafficGb(),
            $durationText,
            $discountLine
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

        $price = $this->planPricingService->calculateNewOrderPrice($draft->getPlan(), [
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
        ]);
        $draft
            ->setCalculatedAmount($price->afterGlobalDiscountAmount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($price->afterGlobalDiscountAmount)
            ->setPriceSnapshot([
                'baseAmount' => $price->baseAmount,
                'globalDiscountPercent' => $price->globalDiscountPercent,
                'globalDiscountAmount' => $price->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $price->afterGlobalDiscountAmount,
                'planPriceSource' => 'current_plan',
            ])
            ->setData(array_merge((array) ($draft->getData() ?? []), [
                'draftType' => 'new_service',
                'orderType' => OrderType::NEW_SERVICE,
            ]))
            ->setUpdatedAt(new \DateTimeImmutable());
        $order = $this->createOrderFromNewServiceDraft($draft);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_from_new_service_draft');

            return;
        }

        $this->clearOrderDiscountCodeWaitingState($order);
        $this->entityManager->flush();

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            "کد تخفیف دارید؟",
            $this->keyboardFactory->discountCodePromptForOrder((int) ($order->getId() ?? 0), 'main_menu')
        );
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

        $price = $this->planPricingService->calculateNewOrderPrice($plan, [
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
        ]);
        $amount = $price->afterGlobalDiscountAmount;
        $metadata = [
            'custom' => true,
            'customUsername' => $draft->getCustomUsernamePrefix(),
            'customUsernamePrefix' => $draft->getCustomUsernamePrefix(),
            'finalUsername' => $draft->getFinalUsername(),
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
            'unlimitedDuration' => $plan->isUnlimitedDuration() || null === $draft->getDurationDays(),
            'calculatedAmount' => $amount,
            'priceSnapshot' => [
                'baseAmount' => $price->baseAmount,
                'globalDiscountPercent' => $price->globalDiscountPercent,
                'globalDiscountAmount' => $price->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $amount,
                'planPriceSource' => 'current_plan',
            ],
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

        $discountLine = sprintf(
            "\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: -\nمبلغ نهایی: %d تومان",
            $price->baseAmount,
            $price->globalDiscountAmount,
            $amount
        );
        $message = sprintf(
            "پلن: %s\nنام کاربری: %s\nحجم: %s گیگ\nمدت: %s%s\nمبلغ: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            $plan->getTitle(),
            (string) ($draft->getFinalUsername() ?? '-'),
            null === $draft->getTrafficGb() ? '-' : (string) $draft->getTrafficGb(),
            null === $draft->getDurationDays() || $plan->isUnlimitedDuration() ? 'نامحدود' : ((string) $draft->getDurationDays().' روز'),
            $discountLine,
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

        $price = $this->planPricingService->calculateNewOrderPrice($plan);
        $metadata = [
            'orderType' => OrderType::NEW_SERVICE,
            'priceSnapshot' => [
                'baseAmount' => $price->baseAmount,
                'globalDiscountPercent' => $price->globalDiscountPercent,
                'globalDiscountAmount' => $price->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $price->afterGlobalDiscountAmount,
                'planPriceSource' => 'current_plan',
            ],
        ];

        $order = (new Order())
            ->setUser($account->getUser())
            ->setPlan($plan)
            ->setAmount($price->afterGlobalDiscountAmount)
            ->setType(OrderType::NEW_SERVICE)
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);

        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($paymentMethod)
            ->setAmount($price->afterGlobalDiscountAmount)
            ->setStatus(PaymentStatus::PENDING);

        $this->entityManager->persist($order);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $cardNumber = $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
        $cardHolder = $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
        $description = $this->settingValueProvider->get('payment.description', $this->paymentDescription);

        $discountLine = sprintf(
            "\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: -\nمبلغ نهایی: %d تومان",
            $price->baseAmount,
            $price->globalDiscountAmount,
            $price->afterGlobalDiscountAmount
        );
        $message = sprintf(
            "پلن: %s%s\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            $plan->getTitle(),
            $discountLine,
            $cardNumber ?: '-',
            $cardHolder ?: '-',
            $description ? 'توضیحات: '.$description : ''
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId()));
    }

    private function handleDiscountEnter(TelegramAccount $account, string $chatId, int $draftId, ?string $callbackId = null): void
    {
        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (
            !$draft instanceof OrderDraft
            || $draft->getUser()->getId() !== $account->getUser()->getId()
            || OrderDraftStatus::PENDING !== $draft->getStatus()
        ) {
            $order = $this->findOrderByDraftId($account, $draftId);
            if ($order instanceof Order) {
                $this->handleDiscountEnterOrder($account, $chatId, (int) ($order->getId() ?? 0), $callbackId);

                return;
            }

            $this->showPopupOrMessage($chatId, $callbackId, 'پیش‌نویس سفارش نامعتبر است.', 'invalid_discount_enter_draft');

            return;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if ('new_service' !== $draftType) {
            $this->serviceManagementService->handleDiscountDecision($account, $draftId, true, $chatId, $callbackId);

            return;
        }

        $draft->setStep(self::STEP_WAITING_DISCOUNT_CODE)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'کد تخفیف را ارسال کنید.');
    }

    private function handleDiscountSkip(TelegramAccount $account, string $chatId, int $draftId, ?string $callbackId = null): void
    {
        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (
            !$draft instanceof OrderDraft
            || $draft->getUser()->getId() !== $account->getUser()->getId()
            || OrderDraftStatus::PENDING !== $draft->getStatus()
        ) {
            $order = $this->findOrderByDraftId($account, $draftId);
            if ($order instanceof Order) {
                $this->handleDiscountSkipOrder($account, $chatId, (int) ($order->getId() ?? 0), $callbackId);

                return;
            }

            $this->showPopupOrMessage($chatId, $callbackId, 'پیش‌نویس سفارش نامعتبر است.', 'invalid_discount_skip_draft');

            return;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if ('new_service' !== $draftType) {
            $this->serviceManagementService->handleDiscountDecision($account, $draftId, false, $chatId, $callbackId);

            return;
        }

        $draft
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount((int) ($draft->getCalculatedAmount() ?? 0))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendPaymentGatewaySelectionForNewServiceDraft($draft, $chatId, $callbackId);
    }

    private function handleDiscountEnterOrder(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId = null): void
    {
        $order = $this->findPendingNewServiceOrder($account, $orderId);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_discount_enter_order');

            return;
        }

        $this->markOrderWaitingDiscountCode($order);
        $this->entityManager->flush();

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'کد تخفیف را ارسال کنید.');
    }

    private function handleDiscountSkipOrder(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId = null): void
    {
        $order = $this->findPendingNewServiceOrder($account, $orderId);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_discount_skip_order');

            return;
        }

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $baseFinal = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $order->getAmount());
        $priceSnapshot['discountCode'] = null;
        $priceSnapshot['discountCodeAmount'] = 0;
        $priceSnapshot['finalAmount'] = $baseFinal;
        $metadata['priceSnapshot'] = $priceSnapshot;
        $metadata['discountCode'] = null;
        $metadata['discountAmount'] = 0;
        unset($metadata['inputState']);

        $order
            ->setMetadata($metadata)
            ->setAmount($baseFinal);

        $this->entityManager->flush();
        $this->sendPaymentGatewaySelectionForOrder($order, $chatId, $callbackId);
    }

    private function handleNewServiceDraftDiscountCodeInput(TelegramAccount $account, OrderDraft $draft, string $codeInput, string $chatId): void
    {
        if ($draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return;
        }

        $amountBeforeCode = (int) ($draft->getCalculatedAmount() ?? 0);
        $result = $this->discountCodeService->validateCode($codeInput, $draft->getUser(), OrderType::NEW_SERVICE, $draft->getPlan(), $amountBeforeCode);
        if (!$result->valid || !$result->discountCode instanceof \App\Entity\DiscountCode) {
            $this->telegramApiClient->sendMessage(
                $chatId,
                $result->message,
                $this->keyboardFactory->discountCodePrompt((int) ($draft->getId() ?? 0), 'custom_order_cancel:'.((int) ($draft->getId() ?? 0)))
            );

            return;
        }

        $snapshot = is_array($draft->getPriceSnapshot()) ? $draft->getPriceSnapshot() : [];
        $snapshot['discountCode'] = $result->discountCode->getCode();
        $snapshot['discountCodeAmount'] = $result->discountAmount;
        $snapshot['finalAmount'] = $result->finalAmount;

        $draft
            ->setDiscountCode($result->discountCode->getCode())
            ->setDiscountAmount($result->discountAmount)
            ->setFinalAmount($result->finalAmount)
            ->setPriceSnapshot($snapshot)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendPaymentGatewaySelectionForNewServiceDraft($draft, $chatId, null);
    }

    private function handleOrderDiscountCodeInput(TelegramAccount $account, Order $order, string $codeInput, string $chatId): void
    {
        if ($order->getUser()->getId() !== $account->getUser()->getId()) {
            return;
        }
        if (OrderStatus::WAITING_PAYMENT !== $order->getStatus() || OrderType::NEW_SERVICE !== $order->getType()) {
            return;
        }

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $amountBeforeCode = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $order->getAmount());
        $result = $this->discountCodeService->validateCode($codeInput, $order->getUser(), OrderType::NEW_SERVICE, $order->getPlan(), $amountBeforeCode);
        if (!$result->valid || !$result->discountCode instanceof \App\Entity\DiscountCode) {
            $this->telegramApiClient->sendMessage(
                $chatId,
                $result->message,
                $this->keyboardFactory->discountCodePromptForOrder((int) ($order->getId() ?? 0), 'main_menu')
            );

            return;
        }

        $priceSnapshot['discountCode'] = $result->discountCode->getCode();
        $priceSnapshot['discountCodeAmount'] = $result->discountAmount;
        $priceSnapshot['finalAmount'] = $result->finalAmount;
        $metadata['priceSnapshot'] = $priceSnapshot;
        $metadata['discountCode'] = $result->discountCode->getCode();
        $metadata['discountAmount'] = $result->discountAmount;
        unset($metadata['inputState']);

        $order
            ->setMetadata($metadata)
            ->setAmount($result->finalAmount);

        $this->entityManager->flush();
        $this->sendPaymentGatewaySelectionForOrder($order, $chatId, null);
    }

    private function createOrReuseNewServiceOrderPayment(Order $order, PaymentGateway $gateway, StorePaymentMethod $storePaymentMethod, string $chatId, ?string $callbackId = null): void
    {
        $plan = $order->getPlan();
        if (!$plan->isActive()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan_select_payment_method_draft');

            return;
        }

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $finalAmount = (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount());

        $payment = $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->where('p.order = :order')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('order', $order)
            ->setParameter('statuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment instanceof Payment) {
            $payment = (new Payment())
                ->setOrder($order)
                ->setGateway($gateway)
                ->setStorePaymentMethod($storePaymentMethod)
                ->setGatewayType($gateway->getType())
                ->setMethod($gateway->getType())
                ->setCurrency($gateway->getCurrency())
                ->setAmount($finalAmount)
                ->setPayableAmount($finalAmount)
                ->setStatus(PaymentStatus::PENDING);
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
        } else {
            $payment
                ->setGateway($gateway)
                ->setStorePaymentMethod($storePaymentMethod)
                ->setGatewayType($gateway->getType())
                ->setMethod($gateway->getType())
                ->setCurrency($gateway->getCurrency());
        }

        $requestResult = $this->paymentGatewayRegistry
            ->resolve($gateway)
            ->createPayment($payment, $order);

        if ($requestResult->rawResponse !== null) {
            $payment->setRequestPayload($requestResult->rawResponse);
        }
        if ($requestResult->transactionId) {
            $payment->setGatewayTransactionId($requestResult->transactionId);
        }
        if ($requestResult->authority) {
            $payment->setAuthority($requestResult->authority);
        }
        if ($requestResult->paymentUrl) {
            $payment->setPaymentUrl($requestResult->paymentUrl);
        }
        if (!$requestResult->success) {
            $payment->setAdminNote($requestResult->message);
        }
        $this->entityManager->flush();
        $this->debugLog(sprintf('payment_gateway_selected order_id=%d payment_id=%d amount=%d', $order->getId() ?? 0, $payment->getId() ?? 0, $finalAmount));

        if (in_array($gateway->getType(), [PaymentGatewayType::ZIBAL, PaymentGatewayType::CUSTOM_API], true)) {
            if (!$requestResult->success || null === $payment->getPaymentUrl()) {
                $this->showPopupOrMessage($chatId, $callbackId, $requestResult->message ?: 'ایجاد لینک پرداخت آنلاین انجام نشد.', 'zibal_request_failed_new_service');

                return;
            }

            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage(
                $chatId,
                'برای پرداخت آنلاین روی دکمه زیر بزنید.',
                $this->keyboardFactory->paymentOnlineActionMenu((int) ($payment->getId() ?? 0), (string) $payment->getPaymentUrl())
            );

            return;
        }

        $cardNumber = $gateway->getManualCardNumber() ?? $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
        $cardHolder = $gateway->getManualCardHolder() ?? $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
        $description = $gateway->getManualInstructions() ?? $this->settingValueProvider->get('payment.description', $this->paymentDescription);
        $message = sprintf(
            "پلن: %s\nنام کاربری: %s\nحجم: %s گیگ\nمدت: %s\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: %s (%d تومان)\nمبلغ نهایی: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            $plan->getTitle(),
            (string) ($metadata['finalUsername'] ?? '-'),
            null === ($metadata['trafficGb'] ?? null) ? '-' : (string) $metadata['trafficGb'],
            null === ($metadata['durationDays'] ?? null) || $plan->isUnlimitedDuration() ? 'نامحدود' : ((string) $metadata['durationDays'].' روز'),
            (int) ($priceSnapshot['baseAmount'] ?? 0),
            (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (int) ($priceSnapshot['discountCodeAmount'] ?? 0),
            $finalAmount,
            $cardNumber ?: '-',
            $cardHolder ?: '-',
            $description ? 'توضیحات: '.$description : ''
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId()));
    }

    private function sendPaymentGatewaySelectionForNewServiceDraft(OrderDraft $draft, string $chatId, ?string $callbackId): void
    {
        $order = $this->createOrderFromNewServiceDraft($draft);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_from_new_service_draft');

            return;
        }

        $this->sendPaymentGatewaySelectionForOrder($order, $chatId, $callbackId, 'main_menu');
    }

    private function sendPaymentGatewaySelectionForOrder(Order $order, string $chatId, ?string $callbackId, string $cancelCallback = 'main_menu'): void
    {
        $methods = $this->storePaymentMethodResolver->getAvailableMethods($order);
        if ([] === $methods) {
            $diagnostics = $this->storePaymentMethodResolver->getDiagnostics($order);
            $encodedReasons = json_encode($diagnostics['skippedReasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->debugLog(sprintf(
                'no_store_payment_methods order_id=%d amount=%d payable_amount=%d currency=%s active_count=%d skipped_reasons=%s',
                (int) ($diagnostics['orderId'] ?? 0),
                (int) ($diagnostics['amount'] ?? 0),
                (int) ($diagnostics['payableAmount'] ?? 0),
                (string) ($diagnostics['currency'] ?? 'IRR'),
                (int) ($diagnostics['activeStorePaymentMethodCount'] ?? 0),
                false === $encodedReasons ? '[]' : $encodedReasons
            ));
            $this->showPopupOrMessage($chatId, $callbackId, 'در حال حاضر روش پرداخت فعالی وجود ندارد.', 'no_active_store_payment_method_new_service');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            'روش پرداخت را انتخاب کنید:',
            $this->keyboardFactory->paymentGatewaySelectionMenu((int) ($order->getId() ?? 0), $methods, $cancelCallback)
        );
    }

    private function createOrderFromNewServiceDraft(OrderDraft $draft): ?Order
    {
        if (OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return null;
        }

        $plan = $draft->getPlan();
        if (!$plan->isActive()) {
            return null;
        }

        $finalAmount = max(0, (int) ($draft->getFinalAmount() ?? $draft->getCalculatedAmount() ?? 0));
        $priceSnapshot = is_array($draft->getPriceSnapshot()) ? $draft->getPriceSnapshot() : [];
        $metadata = [
            'custom' => true,
            'customUsername' => $draft->getCustomUsernamePrefix(),
            'customUsernamePrefix' => $draft->getCustomUsernamePrefix(),
            'finalUsername' => $draft->getFinalUsername(),
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
            'unlimitedDuration' => $plan->isUnlimitedDuration() || null === $draft->getDurationDays(),
            'calculatedAmount' => $finalAmount,
            'priceSnapshot' => [
                'baseAmount' => (int) ($priceSnapshot['baseAmount'] ?? 0),
                'globalDiscountPercent' => (int) ($priceSnapshot['globalDiscountPercent'] ?? 0),
                'globalDiscountAmount' => (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
                'afterGlobalDiscountAmount' => (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $draft->getCalculatedAmount() ?? 0),
                'discountCode' => $draft->getDiscountCode(),
                'discountCodeAmount' => (int) ($draft->getDiscountAmount() ?? 0),
                'finalAmount' => $finalAmount,
                'planPriceSource' => (string) ($priceSnapshot['planPriceSource'] ?? 'current_plan'),
            ],
            'orderDraftId' => $draft->getId(),
            'orderType' => OrderType::NEW_SERVICE,
        ];

        $order = (new Order())
            ->setUser($draft->getUser())
            ->setPlan($plan)
            ->setAmount($finalAmount)
            ->setType(OrderType::NEW_SERVICE)
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);

        $draft
            ->setStatus(OrderDraftStatus::CONFIRMED)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function handleSelectStorePaymentMethod(TelegramAccount $account, string $chatId, string $data, ?string $callbackId = null): void
    {
        $parts = explode(':', $data);
        if (3 !== count($parts)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_gateway_callback');

            return;
        }

        $orderId = (int) $parts[1];
        $storePaymentMethodId = (int) $parts[2];
        if ($orderId <= 0 || $storePaymentMethodId <= 0) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_gateway_ids');

            return;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || OrderStatus::WAITING_PAYMENT !== $order->getStatus()
        ) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'popup_invalid_payment_gateway_order');

            return;
        }

        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            if (!$this->serviceManagementService->handleSelectStorePaymentMethodForOrder($account, $orderId, $storePaymentMethodId, $chatId, $callbackId)) {
                $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'popup_invalid_payment_gateway_order_type');
            }

            return;
        }

        if (OrderType::NEW_SERVICE !== $order->getType()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'popup_invalid_payment_gateway_new_service_type');

            return;
        }

        $storePaymentMethod = $this->findStorePaymentMethodForOrder($order, $storePaymentMethodId);
        if (!$storePaymentMethod instanceof StorePaymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_store_payment_method');

            return;
        }

        $gateway = $storePaymentMethod->getGateway();
        $this->createOrReuseNewServiceOrderPayment($order, $gateway, $storePaymentMethod, $chatId, $callbackId);
    }

    private function handleSelectPaymentGateway(TelegramAccount $account, string $chatId, string $data, ?string $callbackId = null): void
    {
        $parts = explode(':', $data);
        if (3 !== count($parts)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_gateway_legacy_callback');

            return;
        }

        $orderId = (int) $parts[1];
        $gatewayId = (int) $parts[2];
        if ($orderId <= 0 || $gatewayId <= 0) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_gateway_legacy_ids');

            return;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || OrderStatus::WAITING_PAYMENT !== $order->getStatus()
        ) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'popup_invalid_payment_gateway_legacy_order');

            return;
        }

        $method = $this->findStorePaymentMethodByGatewayForOrder($order, $gatewayId);
        if (!$method instanceof StorePaymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_payment_gateway_legacy_method');

            return;
        }

        $this->handleSelectStorePaymentMethod($account, $chatId, 'select_store_payment_method:'.$orderId.':'.$method->getId(), $callbackId);
    }

    private function handlePaymentCheck(TelegramAccount $account, string $chatId, int $paymentId, ?string $callbackId = null): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت یافت نشد.', 'payment_check_not_found');

            return;
        }

        if ($payment->getOrder()->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'payment_check_unauthorized');

            return;
        }

        $gatewayType = (string) ($payment->getGatewayType() ?? $payment->getMethod());
        if (PaymentGatewayType::MANUAL_CARD === $gatewayType) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت دستی در انتظار بررسی رسید است.', 'payment_check_manual_waiting');

            return;
        }

        try {
            $verify = $this->paymentGatewayRegistry->resolveByType($gatewayType)->verifyPayment($payment);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->debugLog(sprintf('payment_check_error payment_id=%d message="%s"', $paymentId, $e->getMessage()));
            $this->showPopupOrMessage($chatId, $callbackId, 'بررسی پرداخت انجام نشد.', 'payment_check_failed');

            return;
        }

        if (!$verify->success || !$verify->paid) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت هنوز تایید نشده است.', 'payment_check_not_paid');

            return;
        }

        $result = $this->paymentConfirmationService->confirm($payment, 'telegram_payment_check');
        if ($result->processed || $result->alreadyProcessed) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت تایید شد.', 'payment_check_confirmed');

            return;
        }

        $this->showPopupOrMessage($chatId, $callbackId, $result->message, 'payment_check_confirm_failed');
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
            $isAddTraffic = OrderType::ADD_TRAFFIC === $order->getType();
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
            $addTrafficSuffix = '';
            if ($isAddTraffic) {
                $addTrafficSuffix = sprintf(
                    "\nپرداخت خرید حجم اضافه | سرویس #%s | حجم درخواستی: %sGB",
                    (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                    (string) ($meta['trafficGb'] ?? '-')
                );
            }
            $lines[] = sprintf(
                "#%d | سفارش #%d\nکاربر: %s\nنوع سفارش: %s\nپلن: %s\nمبلغ: %d\nوضعیت: %s\nکد پیگیری: %s%s%s\n",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getType(),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $tracking,
                $renewalSuffix,
                $addTrafficSuffix
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
        $isAddTraffic = OrderType::ADD_TRAFFIC === $order->getType();
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
        $priceSnapshot = is_array($customMeta['priceSnapshot'] ?? null) ? $customMeta['priceSnapshot'] : [];
        $snapshotLines = sprintf(
            "Base amount: %s\nGlobal discount: %s\nDiscount code: %s\nDiscount code amount: %s\nFinal amount: %s",
            (string) ($priceSnapshot['baseAmount'] ?? '-'),
            (string) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (string) ($priceSnapshot['discountCodeAmount'] ?? 0),
            (string) ($priceSnapshot['finalAmount'] ?? $payment->getAmount())
        );
        if ($isAddTraffic) {
            $detail = sprintf(
                "پرداخت خرید حجم اضافه\nOrder type: add_traffic\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nRequested traffic: %s GB\nAmount: %d تومان\n%s\nStatus: %s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                (string) ($customMeta['trafficGb'] ?? '-'),
                $payment->getAmount(),
                $snapshotLines,
                $payment->getStatus(),
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        } elseif ($isRenewal) {
            $renewalPolicy = is_array($customMeta['renewalPolicy'] ?? null) ? $customMeta['renewalPolicy'] : [];
            $detail = sprintf(
                "پرداخت تمدید سرویس\nOrder type: renewal\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nAmount: %d تومان\nDuration: %s\nTraffic: %s GB\nPolicy traffic carry: %s\nPolicy days carry: %s\nPrice source: %s\n%s\nStatus: %s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                $payment->getAmount(),
                $durationText,
                (string) ($customMeta['trafficGb'] ?? '-'),
                true === ($renewalPolicy['carryRemainingTraffic'] ?? true) ? 'yes' : 'no',
                true === ($renewalPolicy['carryRemainingDays'] ?? true) ? 'yes' : 'no',
                (string) ($priceSnapshot['planPriceSource'] ?? 'current_plan'),
                $snapshotLines,
                $payment->getStatus(),
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-',
                $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                $payment->getSubmittedAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        } else {
            $detail = sprintf(
                "Payment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان\nStatus: %s\n%s\n%s\nTracking: %s\nReceipt message: %s\nCreated: %s\nSubmitted: %s",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $payment->getStatus(),
                $customSummary,
                $snapshotLines,
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

        $caption = $isAddTraffic
            ? sprintf(
                "رسید پرداخت خرید حجم اضافه\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nRequested traffic: %s GB\nAmount: %d تومان",
                $payment->getId(),
                $order->getId(),
                (string) ($customMeta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                (string) ($customMeta['trafficGb'] ?? '-'),
                $payment->getAmount()
            )
            : ($isRenewal
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
            ));

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
            ->andWhere('(p.gatewayType = :manualGateway OR (p.gatewayType IS NULL AND p.method = :manualMethod))')
            ->setParameter('user', $account->getUser())
            ->setParameter('orderStatus', OrderStatus::WAITING_PAYMENT)
            ->setParameter('paymentStatuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->setParameter('manualGateway', PaymentGatewayType::MANUAL_CARD)
            ->setParameter('manualMethod', PaymentGatewayType::MANUAL_CARD)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<StorePaymentMethod>
     */
    private function findAvailableStorePaymentMethods(Order $order): array
    {
        return $this->storePaymentMethodResolver->getAvailableMethods($order);
    }

    private function findStorePaymentMethodForOrder(Order $order, int $storePaymentMethodId): ?StorePaymentMethod
    {
        foreach ($this->findAvailableStorePaymentMethods($order) as $method) {
            if ((int) ($method->getId() ?? 0) === $storePaymentMethodId) {
                return $method;
            }
        }

        return null;
    }

    private function findStorePaymentMethodByGatewayForOrder(Order $order, int $gatewayId): ?StorePaymentMethod
    {
        foreach ($this->findAvailableStorePaymentMethods($order) as $method) {
            if ((int) ($method->getGateway()->getId() ?? 0) === $gatewayId) {
                return $method;
            }
        }

        return null;
    }

    private function findPendingNewServiceOrder(TelegramAccount $account, int $orderId): ?Order
    {
        if ($orderId <= 0) {
            return null;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || OrderStatus::WAITING_PAYMENT !== $order->getStatus()
            || OrderType::NEW_SERVICE !== $order->getType()
        ) {
            return null;
        }

        return $order;
    }

    private function findOrderByDraftId(TelegramAccount $account, int $draftId): ?Order
    {
        if ($draftId <= 0) {
            return null;
        }

        $orders = $this->entityManager->getRepository(Order::class)->findBy(
            ['user' => $account->getUser(), 'status' => OrderStatus::WAITING_PAYMENT, 'type' => OrderType::NEW_SERVICE],
            ['id' => 'DESC'],
            20
        );

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            if ((int) ($metadata['orderDraftId'] ?? 0) === $draftId) {
                return $order;
            }
        }

        return null;
    }

    private function markOrderWaitingDiscountCode(Order $order): void
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $metadata['inputState'] = sprintf('waiting_discount_code_order:%d', (int) ($order->getId() ?? 0));
        $order->setMetadata($metadata);
    }

    private function clearOrderDiscountCodeWaitingState(Order $order): void
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        unset($metadata['inputState']);
        $order->setMetadata($metadata);
    }

    private function findWaitingDiscountCodeOrder(TelegramAccount $account): ?Order
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy(
            ['user' => $account->getUser(), 'status' => OrderStatus::WAITING_PAYMENT, 'type' => OrderType::NEW_SERVICE],
            ['id' => 'DESC'],
            10
        );

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            $inputState = trim((string) ($metadata['inputState'] ?? ''));
            if (str_starts_with($inputState, 'waiting_discount_code_order:')) {
                $stateOrderId = (int) str_replace('waiting_discount_code_order:', '', $inputState);
                if ($stateOrderId > 0 && $stateOrderId === (int) ($order->getId() ?? 0)) {
                    return $order;
                }
            }
        }

        return null;
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
        $isAddTraffic = OrderType::ADD_TRAFFIC === $order->getType();
        $durationText = true === ($meta['unlimitedDuration'] ?? false)
            ? 'نامحدود'
            : sprintf('%s روز', (string) ($meta['durationDays'] ?? '-'));
        $customSummary = true === ($meta['custom'] ?? false)
            ? sprintf("Custom: yes\nUsername: %s\nTraffic: %s GB\nDuration: %s\nCalculated amount: %s\n", (string) ($meta['finalUsername'] ?? '-'), (string) ($meta['trafficGb'] ?? '-'), $durationText, (string) ($meta['calculatedAmount'] ?? '-'))
            : "Custom: no\n";
        $priceSnapshot = is_array($meta['priceSnapshot'] ?? null) ? $meta['priceSnapshot'] : [];
        $snapshotLines = sprintf(
            "Base amount: %s\nGlobal discount: %s\nDiscount code: %s\nDiscount code amount: %s\nFinal amount: %s",
            (string) ($priceSnapshot['baseAmount'] ?? '-'),
            (string) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (string) ($priceSnapshot['discountCodeAmount'] ?? 0),
            (string) ($priceSnapshot['finalAmount'] ?? $payment->getAmount())
        );
        if ($isAddTraffic) {
            $message = sprintf(
                "پرداخت خرید حجم اضافه\n\nOrder type: add_traffic\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nRequested traffic: %s GB\nAmount: %d تومان\n%s\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                (string) ($meta['trafficGb'] ?? '-'),
                $payment->getAmount(),
                $snapshotLines,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        } elseif ($isRenewal) {
            $renewalPolicy = is_array($meta['renewalPolicy'] ?? null) ? $meta['renewalPolicy'] : [];
            $message = sprintf(
                "پرداخت تمدید سرویس\n\nOrder type: renewal\nPayment ID: %d\nOrder ID: %d\nService ID: %s\nUser: %s\nAmount: %d تومان\nRenewal duration: %s\nRenewal traffic: %s GB\nPolicy traffic carry: %s\nPolicy days carry: %s\nPrice source: %s\n%s\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                (string) ($meta['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                $this->formatTelegramIdentity($telegramAccount),
                $payment->getAmount(),
                $durationText,
                (string) ($meta['trafficGb'] ?? '-'),
                true === ($renewalPolicy['carryRemainingTraffic'] ?? true) ? 'yes' : 'no',
                true === ($renewalPolicy['carryRemainingDays'] ?? true) ? 'yes' : 'no',
                (string) ($priceSnapshot['planPriceSource'] ?? 'current_plan'),
                $snapshotLines,
                $payment->getTrackingCode() ?: '-',
                $payment->getReceiptMessage() ?: '-'
            );
        } else {
            $message = sprintf(
                "پرداخت جدید ثبت شد\n\nPayment ID: %d\nOrder ID: %d\nUser: %s\nPlan: %s\nAmount: %d تومان\n%s%s\nTracking: %s\nReceipt message: %s",
                $payment->getId(),
                $order->getId(),
                $this->formatTelegramIdentity($telegramAccount),
                $order->getPlan()->getTitle(),
                $payment->getAmount(),
                $customSummary,
                $snapshotLines,
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
