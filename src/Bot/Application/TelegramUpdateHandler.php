<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Provisioning\Domain\PublicUrlPolicy;
use App\Subscription\Application\AppDownloadCatalog;
use App\Bot\Application\Admin\AdminBotHandler;
use App\Bot\Application\DeepLink\StartPayload;
use App\Bot\Application\DeepLink\StartPayloadRouter;
use App\Reseller\UI\ClaimBotHandler;
use App\Bot\Application\ServiceAction\ServiceActionContext;
use App\Bot\Application\ServiceAction\ServiceActionResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Wallet\UI\WalletBotHandler;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\UI\ResellerBotHandler;
use App\Wallet\Application\WalletPayment;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\TelegramAccount;
use App\Entity\TrialPlan;
use App\Entity\User;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Shop\Application\IncompleteOrderService;
use App\Shop\Application\IncompleteOrderContext;
use App\Shop\Application\OrderTrackingCodeService;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Application\Checkout\DiscountApplication;
use App\Shop\Application\Checkout\NewServiceCheckout;
use App\Shop\Application\Checkout\OrderLifecycle;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanOptions;
use App\Shop\Application\Checkout\ReceiptSubmission;
use App\Shop\Application\Checkout\TrialCheckout;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Security\AdminAccessPolicy;
use App\Security\AdminAuditLogger;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUpdateHandler
{
    private const BUTTON_BUY_SERVICE = '🛒 خرید سرویس';
    private const BUTTON_TRIAL_ACCOUNT = '🎁 اکانت تست';
    private const BUTTON_MY_SERVICES = '📦 سرویسهای من';
    private const BUTTON_SUPPORT = '🎧 پشتیبانی';
    private const BUTTON_ADMIN_MENU = '🛠 مدیریت';
    private const BUTTON_RESUME_ORDER = '▶️ ادامه سفارش قبلی';
    private const BUTTON_CANCEL_INCOMPLETE = '🗑 حذف سفارش ناتمام';
    private const BUTTON_TRACK_ORDER = '🔎 پیگیری سفارش';
    private const REPLY_BUTTONS = [self::BUTTON_BUY_SERVICE, self::BUTTON_TRIAL_ACCOUNT, self::BUTTON_MY_SERVICES, self::BUTTON_SUPPORT, self::BUTTON_ADMIN_MENU, self::BUTTON_RESUME_ORDER, self::BUTTON_CANCEL_INCOMPLETE, self::BUTTON_TRACK_ORDER];
    private const STEP_WAITING_CUSTOM_USERNAME = 'waiting_custom_username';
    private const STEP_WAITING_CUSTOM_TRAFFIC = 'waiting_custom_traffic';
    private const STEP_WAITING_CUSTOM_DURATION = 'waiting_custom_duration';
    private const STEP_WAITING_DISCOUNT_DECISION = 'waiting_discount_decision';
    private const STEP_WAITING_DISCOUNT_CODE = 'waiting_discount_code';
    private const STEP_WAITING_PAYMENT_METHOD = 'waiting_payment_method';
    private const NOWPAYMENTS_WAITING_STATUS_TEXT = 'در انتظار پرداخت';
    /** PaymentStart::KIND_WALLET_PAYMENT_PENDING (also in ServiceManagementService). */
    public const WALLET_PAYMENT_PENDING_TEXT = 'پرداخت این سفارش از کیف پول در جریان است. برای تکمیل، دوباره «پرداخت از کیف پول» را بزنید یا سفارش را لغو کنید تا مبلغ به کیف پول برگردد.';
    /** PaymentStart::KIND_WALLET_PAYMENT_HELD (also in ServiceManagementService). */
    public const WALLET_PAYMENT_HELD_TEXT = 'این سفارش از کیف پول شما پرداخت شده و در حال بررسی یا آماده‌سازی سرویس است. نیازی به پرداخت دوباره نیست؛ در صورت نیاز با پشتیبانی تماس بگیرید.';

    public function __construct(
        private readonly TelegramUserResolver $telegramUserResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly ServiceManagementService $serviceManagementService,
        private readonly ServiceActionResolver $serviceActionResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentCustomerNotifier $paymentCustomerNotifier,
        private readonly PaymentSettlementService $paymentSettlementService,
        private readonly IncompleteOrderService $incompleteOrderService,
        private readonly OrderTrackingCodeService $orderTrackingCodeService,
        private readonly PlanPricingService $planPricingService,
        private readonly SalesSettingsProvider $salesSettingsProvider,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly TelegramChannelMembershipGate $membershipGate,
        private readonly MembershipGatePrompt $membershipGatePrompt,
        private readonly BotTextResolver $botTextResolver,
        private readonly BotButtonMatcher $botButtonMatcher,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly AdminAuditLogger $auditLogger,
        private readonly AdminBotHandler $adminBotHandler,
        private readonly SubscriptionPageButton $subscriptionPageButton,
        private readonly AppDownloadCatalog $appDownloads,
        private readonly PlanCatalog $planCatalog,
        private readonly PlanOptions $planOptions,
        private readonly NewServiceCheckout $newServiceCheckout,
        private readonly DiscountApplication $discountApplication,
        private readonly PaymentStarter $paymentStarter,
        private readonly OrderLifecycle $orderLifecycle,
        private readonly ReceiptSubmission $receiptSubmission,
        private readonly TrialCheckout $trialCheckout,
        private readonly WalletBotHandler $walletBotHandler,
        private readonly WalletPayment $walletPayment,
        private readonly ResellerBotHandler $resellerBotHandler,
        private readonly ResellerCheckout $resellerCheckout,
        private readonly StartPayloadRouter $startPayloadRouter,
        private readonly ClaimBotHandler $claimBotHandler,
    ) {
    }

    /**
     * The main reply keyboard, with the reseller button for an active reseller (reseller.enabled on).
     *
     * @return array<string, mixed>
     */
    private function mainKeyboard(TelegramAccount $account, bool $isAdmin, bool $hasIncompleteOrder = false, bool $hasTrackableOrder = false): array
    {
        return $this->keyboardFactory->mainReplyKeyboard($isAdmin, $hasIncompleteOrder, $hasTrackableOrder, null !== $this->resellerCheckout->resellerFor($account->getUser()));
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
        $isAdmin = null !== $this->accessPolicy->botActor($actorId);
        $replyButtonKey = $this->botButtonMatcher->matchReplyButton($text);

        // An admin asked for a value (price, setting, search) answers with plain text; a command or a menu button
        // leaves the prompt and is handled as usual. A reseller asked for the traffic / days of a customizable plan
        // answers the same way; his answer is read exactly as sent (not the trimmed $text). Customers never have one.
        if ('' !== $text) {
            if (str_starts_with($text, '/') || null !== $replyButtonKey || in_array($text, self::REPLY_BUTTONS, true)) {
                $this->adminBotHandler->dropPrompt($actorId);
                $this->resellerBotHandler->dropPrompt($actorId);
            } elseif ($this->adminBotHandler->answerPrompt($actorId, $chatId, $text)) {
                return;
            } elseif ($this->resellerBotHandler->answerPrompt($account, $chatId, (string) ($message['text'] ?? ''))) {
                return;
            }
        }

        if ('/start' === $text) {
            $this->handleStart($account, $chatId, $isAdmin);

            return;
        }

        // "/start <payload>" from a deep link (claim_ now, ref_ later; StartPayloadRouter), read exactly as sent. Taken
        // only by a handler of its prefix that is switched on; anything else goes on below as it always did.
        $rawText = (string) ($message['text'] ?? '');
        if (StartPayload::isStartCommand($rawText) && $this->startPayloadRouter->routeBot($account, $chatId, StartPayload::fromStartCommand($rawText))) {
            return;
        }

        if ('button.main.buy_service' === $replyButtonKey || self::BUTTON_BUY_SERVICE === $text) {
            $this->handleBuyService($account, $chatId, null, $isAdmin);

            return;
        }

        if ('button.main.trial_account' === $replyButtonKey || self::BUTTON_TRIAL_ACCOUNT === $text) {
            $this->handleTrialAccount($account, $chatId, null);

            return;
        }

        if ('button.main.continue_order' === $replyButtonKey || self::BUTTON_RESUME_ORDER === $text) {
            $this->handleResumeIncompleteOrderByText($account, $chatId, $isAdmin);

            return;
        }

        if ('button.main.cancel_incomplete_order' === $replyButtonKey || self::BUTTON_CANCEL_INCOMPLETE === $text) {
            $this->handleCancelIncompleteOrderByText($account, $chatId, $isAdmin);

            return;
        }

        if ('button.main.track_order' === $replyButtonKey || self::BUTTON_TRACK_ORDER === $text) {
            $this->handleTrackOrdersByText($account, $chatId, $isAdmin);

            return;
        }

        if ('button.main.my_services' === $replyButtonKey || self::BUTTON_MY_SERVICES === $text) {
            $this->serviceManagementService->handleMyServices($account, $chatId);

            return;
        }

        if ('button.main.support' === $replyButtonKey || self::BUTTON_SUPPORT === $text) {
            $this->handleSupport($chatId);

            return;
        }

        if ('button.main.wallet' === $replyButtonKey) {
            $this->walletBotHandler->showWallet($account, $chatId, null);

            return;
        }

        if ('button.main.reseller' === $replyButtonKey) {
            $this->resellerBotHandler->showMenu($account, $chatId, null);

            return;
        }

        if ('button.main.downloads' === $replyButtonKey) {
            $this->handleAppDownloads($chatId);

            return;
        }

        $miniAppKeyboard = 'button.main.miniapp' === $replyButtonKey ? $this->keyboardFactory->miniAppOpen() : null;
        if (null !== $miniAppKeyboard) {
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('miniapp.open'), $miniAppKeyboard);

            return;
        }

        if ('button.main.admin' === $replyButtonKey || self::BUTTON_ADMIN_MENU === $text) {
            if (!$isAdmin) {
                $this->debugLog(sprintf('admin_text_unauthorized actor_id="%s"', $actorId));
                $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('admin.unauthorized'), $this->mainKeyboard($account, false, $this->hasActiveIncompleteOrder($account), $this->hasTrackableOrders($account)));

                return;
            }

            $this->adminBotHandler->showMenu($chatId, $this->accessPolicy->botActor($actorId));

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
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('bot.unknown_message'), $this->mainKeyboard($account, $isAdmin, $this->hasActiveIncompleteOrder($account), $this->hasTrackableOrders($account)));
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

        if ($this->adminBotHandler->supports($data)) {
            $this->adminBotHandler->handle($account, $actorId, $chatId, $callbackId, $data);

            return;

        }

        if ($this->walletBotHandler->supports($data)) {
            $this->walletBotHandler->handle($account, $chatId, $callbackId, $data);

            return;
        }

        if ($this->resellerBotHandler->supports($data)) {
            $this->resellerBotHandler->handle($account, $chatId, $callbackId, $data);

            return;
        }

        if ($this->claimBotHandler->supports($data)) {
            $this->claimBotHandler->handle($account, $chatId, $callbackId, $data);

            return;
        }

        $admin = $this->accessPolicy->botActor($actorId);
        $isAdmin = null !== $admin;

        if ('main_menu' === $data) {
            $this->acknowledgeCallback($callbackId);
            $hasIncomplete = $this->hasActiveIncompleteOrder($account);
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('bot.main_menu'), $this->mainKeyboard($account, $isAdmin, $hasIncomplete, $this->hasTrackableOrders($account)));
            $this->sendIncompleteOrderPromptIfAny($account, $chatId);

            return;
        }

        if ('buy_service' === $data) {
            $this->handleBuyService($account, $chatId, $callbackId, $isAdmin);

            return;
        }

        if ('trial_account' === $data) {
            $this->handleTrialAccount($account, $chatId, $callbackId);

            return;
        }

        if (str_starts_with($data, 'membership_check:')) {
            $this->handleMembershipCheck($account, $chatId, (string) str_replace('membership_check:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'select_plan:')) {
            $this->handleSelectPlan($account, $chatId, (int) str_replace('select_plan:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'select_trial_plan:')) {
            $this->handleSelectTrialPlan($account, $chatId, (int) str_replace('select_trial_plan:', '', $data), $callbackId);

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

        if (str_starts_with($data, 'draft_cancel:')) {
            $this->handleCustomOrderCancel($account, $chatId, (int) str_replace('draft_cancel:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'draft_back:')) {
            $this->handleDraftBack($account, $chatId, (int) str_replace('draft_back:', '', $data), $callbackId);

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

        if (str_starts_with($data, 'order_back_to_payment_methods:')) {
            $this->handleOrderBackToPaymentMethods($account, $chatId, (int) str_replace('order_back_to_payment_methods:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'order_payment_methods:')) {
            $this->handleOrderBackToPaymentMethods($account, $chatId, (int) str_replace('order_payment_methods:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'payment_methods_back:')) {
            $this->handleOrderBackToPaymentMethods($account, $chatId, (int) str_replace('payment_methods_back:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'order_cancel:')) {
            $this->handleOrderCancel($account, $chatId, (int) str_replace('order_cancel:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'order_resume:')) {
            $this->handleOrderResume($account, $chatId, (int) str_replace('order_resume:', '', $data), $callbackId);

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

        if (str_starts_with($data, 'discount_back:')) {
            $this->handleDiscountBackOrder($account, $chatId, (int) str_replace('discount_back:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'order_summary:')) {
            $this->handleOrderSummary($account, $chatId, (int) str_replace('order_summary:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'resume_incomplete_order:')) {
            $this->handleResumeIncompleteOrder($account, $chatId, (int) str_replace('resume_incomplete_order:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'cancel_incomplete_order:')) {
            $this->handleCancelIncompleteOrder($account, $chatId, (int) str_replace('cancel_incomplete_order:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'incomplete_order_cancel_confirm:')) {
            $this->handleIncompleteOrderCancelConfirm($account, $chatId, $data, $callbackId, $isAdmin);

            return;
        }

        if ('track_orders' === $data) {
            $this->handleTrackOrdersCallback($account, $chatId, $callbackId);

            return;
        }

        if (str_starts_with($data, 'track_order:')) {
            $this->handleTrackOrderDetail($account, $chatId, (int) str_replace('track_order:', '', $data), $callbackId);

            return;
        }

        if ('start_new_order' === $data) {
            $this->handleBuyService($account, $chatId, $callbackId, $isAdmin);

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
            admin: $admin,
            policy: $this->accessPolicy,
            auditLogger: $this->auditLogger,
        ))) {
            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->debugLog(sprintf('callback_unknown data="%s"', $data));
    }

    private function handleStart(TelegramAccount $account, string $chatId, bool $isAdmin): void
    {
        $hasIncomplete = $this->hasActiveIncompleteOrder($account);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('bot.welcome'), $this->mainKeyboard($account, $isAdmin, $hasIncomplete, $this->hasTrackableOrders($account)));
        $this->sendIncompleteOrderPromptIfAny($account, $chatId);
    }

    private function ensureMembershipGateAllowed(TelegramAccount $account, string $chatId, ?string $callbackId, string $context): bool
    {
        return $this->membershipGatePrompt->allowed($account, $chatId, $callbackId, $context);
    }

    private function handleMembershipCheck(TelegramAccount $account, string $chatId, string $context, ?string $callbackId): void
    {
        $context = TelegramChannelMembershipGate::CONTEXT_TRIAL === $context
            ? TelegramChannelMembershipGate::CONTEXT_TRIAL
            : TelegramChannelMembershipGate::CONTEXT_PURCHASE;

        $result = $this->membershipGate->check($account->getTelegramId(), $context);
        if (!$result->allowed) {
            $this->membershipGatePrompt->send($chatId, $callbackId, $context, $result);

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $message = TelegramChannelMembershipGate::CONTEXT_PURCHASE === $context
            ? 'عضویت شما تایید شد. برای ادامه خرید، دوباره روی «خرید سرویس» بزنید.'
            : 'عضویت شما تایید شد.';
        $this->telegramApiClient->sendMessage($chatId, $message);
    }

    private function handleBuyService(TelegramAccount $account, string $chatId, ?string $callbackId = null, bool $isAdmin = false): void
    {
        if (!$this->salesSettingsProvider->newOrdersEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, $isAdmin, 'new_order', SalesSettingsProvider::NEW_ORDERS_ENABLED);

            return;
        }

        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }

        $plans = $this->planCatalog->forSale();
        if ([] === $plans) {
            $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('plan.list_empty'), 'popup_no_active_plans');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('plan.select'), $this->keyboardFactory->plansMenu($plans));
    }

    private function handleTrialAccount(TelegramAccount $account, string $chatId, ?string $callbackId = null): void
    {
        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_TRIAL)) {
            return;
        }

        $trialPlans = $this->findActiveTrialPlans();
        if ([] === $trialPlans) {
            $this->showPopupOrMessage($chatId, $callbackId, 'در حال حاضر اکانت تست فعالی وجود ندارد.', 'no_active_trial_plans');

            return;
        }

        if (1 === count($trialPlans)) {
            $this->provisionTrialPlan($account, $chatId, $trialPlans[0], $callbackId);

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'پلن تست را انتخاب کنید:', $this->keyboardFactory->trialPlansMenu($trialPlans));
    }

    private function handleSelectTrialPlan(TelegramAccount $account, string $chatId, int $trialPlanId, ?string $callbackId = null): void
    {
        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_TRIAL)) {
            return;
        }

        $trialPlan = $this->trialCheckout->activePlan($trialPlanId);
        if (!$trialPlan instanceof TrialPlan) {
            $this->showPopupOrMessage($chatId, $callbackId, 'در حال حاضر اکانت تست فعالی وجود ندارد.', 'invalid_trial_plan');

            return;
        }

        $this->provisionTrialPlan($account, $chatId, $trialPlan, $callbackId);
    }

    private function provisionTrialPlan(TelegramAccount $account, string $chatId, TrialPlan $trialPlan, ?string $callbackId): void
    {
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'در حال ساخت اکانت تست شما هستیم...');

        $result = $this->trialCheckout->claim($account, $trialPlan);
        if (!$result->isSuccess()) {
            $this->telegramApiClient->sendMessage($chatId, $result->message);

            return;
        }

        $messages = $this->paymentCustomerNotifier->buildNewServiceConfirmedMessages($result->vpnService, '🎁 اکانت تست شما آماده شد');
        $pageKeyboard = $this->subscriptionPageButton->keyboardFor($result->vpnService);
        foreach ($messages as $i => $message) {
            $keyboard = $i === count($messages) - 1 ? $pageKeyboard : null;
            if (str_contains($message, '<code>')) {
                $this->telegramApiClient->sendHtmlMessage($chatId, $message, $keyboard);
            } else {
                $this->telegramApiClient->sendMessage($chatId, $message, $keyboard);
            }
        }
    }

    /**
     * @return list<TrialPlan>
     */
    private function findActiveTrialPlans(): array
    {
        return $this->trialCheckout->activePlans();
    }

    private function handleSelectPlan(TelegramAccount $account, string $chatId, int $planId, ?string $callbackId = null): void
    {
        if (!$this->salesSettingsProvider->newOrdersEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, false, 'new_order', SalesSettingsProvider::NEW_ORDERS_ENABLED);

            return;
        }

        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }

        $plan = $this->planCatalog->findForSale($planId);
        if (!$plan instanceof Plan) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan');

            return;
        }

        $this->startCustomOrderDraft($account, $plan, $chatId, $callbackId);
    }

    private function startCustomOrderDraft(TelegramAccount $account, Plan $plan, string $chatId, ?string $callbackId = null): void
    {
        $draft = $this->newServiceCheckout->startDraft($account->getUser(), $plan, self::STEP_WAITING_CUSTOM_USERNAME);
        $this->debugLog(sprintf('order_draft_start user_id=%d plan_id=%d draft_id=%d', $account->getUser()->getId() ?? 0, $plan->getId() ?? 0, $draft->getId() ?? 0));

        $this->setDraftCurrentStep($draft, self::STEP_WAITING_CUSTOM_USERNAME, false);

        $this->entityManager->flush();
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            'لطفاً نام دلخواه اکانت را وارد کنید.',
            $this->keyboardFactory->customOrderUsernameInputMenu((int) ($draft->getId() ?? 0))
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
            $prefix = $this->newServiceCheckout->setAccountName($draft, $text);
            if (null === $prefix) {
                $this->telegramApiClient->sendMessage($chatId, 'نام نامعتبر است. فقط a-z, 0-9 و _ با طول 3 تا 24 مجاز است.');

                return;
            }
            $this->entityManager->flush();
            $this->debugLog(sprintf('order_draft_username_set draft_id=%d prefix="%s" final="%s"', $draft->getId() ?? 0, $prefix, (string) $draft->getFinalUsername()));

            if ($this->shouldAskCustomTraffic($plan)) {
                $this->setDraftCurrentStep($draft, self::STEP_WAITING_CUSTOM_TRAFFIC);
                $draft->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftTrafficPrompt($chatId, $draft);

                return;
            }

            $draft->setTrafficGb($this->resolveDefaultTrafficGb($plan));

            if ($this->shouldAskCustomDuration($plan)) {
                $this->setDraftCurrentStep($draft, self::STEP_WAITING_CUSTOM_DURATION);
                $draft->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftDurationPrompt($chatId, $draft);

                return;
            }

            $this->newServiceCheckout->price($draft, $draft->getTrafficGb(), $this->resolveDefaultDurationDays($plan));
            $this->setDraftCurrentStep($draft, self::STEP_WAITING_DISCOUNT_DECISION);
            $this->entityManager->flush();
            $this->sendCustomDraftSummary($chatId, $draft);

            return;
        }

        if (self::STEP_WAITING_CUSTOM_TRAFFIC === $draft->getStep()) {
            $traffic = $this->parsePositiveInt($text);
            if (!$this->planOptions->isValidTraffic($plan, $traffic)) {
                $this->telegramApiClient->sendMessage($chatId, sprintf('حجم نامعتبر است. عدد صحیح بین %d تا %d وارد کنید.', (int) ($plan->getMinTrafficGb() ?? 0), (int) ($plan->getMaxTrafficGb() ?? 0)));

                return;
            }

            $draft->setTrafficGb($traffic)->setUpdatedAt(new \DateTimeImmutable());
            $this->debugLog(sprintf('order_draft_traffic_set draft_id=%d traffic=%d', $draft->getId() ?? 0, $traffic));

            if ($this->shouldAskCustomDuration($plan)) {
                $this->setDraftCurrentStep($draft, self::STEP_WAITING_CUSTOM_DURATION);
                $draft->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftDurationPrompt($chatId, $draft);

                return;
            }

            $this->newServiceCheckout->price($draft, $draft->getTrafficGb(), $this->resolveDefaultDurationDays($plan));
            $this->setDraftCurrentStep($draft, self::STEP_WAITING_DISCOUNT_DECISION);
            $this->entityManager->flush();
            $this->sendCustomDraftSummary($chatId, $draft);

            return;
        }

        if (self::STEP_WAITING_CUSTOM_DURATION === $draft->getStep()) {
            $duration = $this->parsePositiveInt($text);
            if (!$this->planOptions->isValidDuration($plan, $duration)) {
                $this->telegramApiClient->sendMessage($chatId, sprintf('مدت نامعتبر است. عدد صحیح بین %d تا %d وارد کنید.', (int) ($plan->getMinDurationDays() ?? 0), (int) ($plan->getMaxDurationDays() ?? 0)));

                return;
            }
            $traffic = (int) ($draft->getTrafficGb() ?? 0);
            if ($traffic <= 0) {
                $this->setDraftCurrentStep($draft, self::STEP_WAITING_CUSTOM_TRAFFIC);
                $draft->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->sendCustomDraftTrafficPrompt($chatId, $draft);

                return;
            }

            $amount = $this->newServiceCheckout->price($draft, $traffic, $duration);
            $this->setDraftCurrentStep($draft, self::STEP_WAITING_DISCOUNT_DECISION);
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
            $this->keyboardFactory->customOrderStepMenu((int) ($draft->getId() ?? 0))
        );
    }

    private function sendCustomDraftDurationPrompt(string $chatId, OrderDraft $draft): void
    {
        $plan = $draft->getPlan();
        $this->telegramApiClient->sendMessage(
            $chatId,
            sprintf('مدت زمان موردنظر را وارد کنید. حداقل %d روز و حداکثر %d روز.', (int) ($plan->getMinDurationDays() ?? 0), (int) ($plan->getMaxDurationDays() ?? 0)),
            $this->keyboardFactory->customOrderStepMenu((int) ($draft->getId() ?? 0))
        );
    }

    private function sendCustomDraftSummary(string $chatId, OrderDraft $draft): void
    {
        $plan = $draft->getPlan();
        $price = $this->newServiceCheckout->quote($plan, $draft->getTrafficGb(), $draft->getDurationDays());
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
        if (!$draft instanceof OrderDraft || $draft->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'درخواست نامعتبر است.', 'popup_custom_order_confirm_invalid');

            return;
        }

        switch ($this->newServiceCheckout->confirmationBlocker($draft)) {
            case NewServiceCheckout::CONFIRM_NOT_PENDING:
                $existingOrder = $this->findOrderByDraftIdForAnyOpenStatus($draft);
                if ($existingOrder instanceof Order) {
                    $this->sendPaymentGatewaySelectionForOrder($existingOrder, $chatId, $callbackId);

                    return;
                }
                $this->showPopupOrMessage($chatId, $callbackId, 'درخواست نامعتبر است.', 'popup_custom_order_confirm_not_pending');

                return;
            case NewServiceCheckout::CONFIRM_EXPIRED:
                $this->showPopupOrMessage($chatId, $callbackId, 'این درخواست منقضی شده است.', 'popup_custom_order_confirm_expired');

                return;
            case NewServiceCheckout::CONFIRM_INCOMPLETE:
                $this->showPopupOrMessage($chatId, $callbackId, 'اطلاعات سفارش ناقص است.', 'popup_custom_order_confirm_incomplete');

                return;
        }

        if (!$this->salesSettingsProvider->newOrdersEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, false, 'new_order', SalesSettingsProvider::NEW_ORDERS_ENABLED);

            return;
        }

        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }

        $order = $this->newServiceCheckout->confirm($draft);
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
            $this->keyboardFactory->discountCodePromptForOrder((int) ($order->getId() ?? 0), 'order_cancel:'.((int) ($order->getId() ?? 0)))
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
        $this->telegramApiClient->sendMessage($chatId, 'سفارش ناتمام حذف شد.', $this->mainKeyboard($account, false, false, $this->hasTrackableOrders($account)));
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

        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
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
        $this->orderTrackingCodeService->assignIfMissing($order);

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

        $card = $this->paymentStarter->manualCard(null);
        [$cardNumber, $cardHolder, $description] = [$card->cardNumber, $card->cardHolder, $card->instructions];

        $message = $this->botTextResolver->message('payment.manual_card.custom_order_instructions', [
            'order' => [
                'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
                'accountName' => (string) ($draft->getFinalUsername() ?? '-'),
                'trafficGb' => null === $draft->getTrafficGb() ? '-' : (string) $draft->getTrafficGb(),
                'duration' => null === $draft->getDurationDays() || $plan->isUnlimitedDuration() ? 'نامحدود' : ((string) $draft->getDurationDays().' روز'),
                'baseAmount' => $price->baseAmount,
                'globalDiscount' => $price->globalDiscountAmount,
                'discountCode' => 'ندارد',
                'discountAmount' => 0,
                'finalAmount' => $amount,
            ],
            'plan' => [
                'title' => $plan->getTitle(),
            ],
            'payment' => [
                'cardNumber' => $cardNumber ?: '-',
                'cardHolder' => $cardHolder ?: '-',
                'extraInstructions' => $description ?: '',
            ],
            'button' => [
                'confirmAndSendReceipt' => $this->botTextResolver->button('button.confirm_and_send_receipt'),
            ],
        ]);

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId(), (int) ($order->getId() ?? 0)));
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
        if (!$plan instanceof Plan || !$plan->isAvailableForSale()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan_select_payment_method');

            return;
        }

        if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
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
        $this->orderTrackingCodeService->assignIfMissing($order);

        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($paymentMethod)
            ->setAmount($price->afterGlobalDiscountAmount)
            ->setStatus(PaymentStatus::PENDING);

        $this->entityManager->persist($order);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $card = $this->paymentStarter->manualCard(null);
        [$cardNumber, $cardHolder, $description] = [$card->cardNumber, $card->cardHolder, $card->instructions];

        $message = $this->botTextResolver->message('payment.manual_card.plan_instructions', [
            'order' => [
                'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
                'baseAmount' => $price->baseAmount,
                'globalDiscount' => $price->globalDiscountAmount,
                'discountCode' => 'ندارد',
                'discountAmount' => 0,
                'finalAmount' => $price->afterGlobalDiscountAmount,
            ],
            'plan' => [
                'title' => $plan->getTitle(),
            ],
            'payment' => [
                'cardNumber' => $cardNumber ?: '-',
                'cardHolder' => $cardHolder ?: '-',
                'extraInstructions' => $description ?: '',
            ],
            'button' => [
                'confirmAndSendReceipt' => $this->botTextResolver->button('button.confirm_and_send_receipt'),
            ],
        ]);

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId(), (int) ($order->getId() ?? 0)));
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

        $this->setDraftCurrentStep($draft, self::STEP_WAITING_DISCOUNT_CODE);
        $draft->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'کد تخفیف را ارسال کنید.', $this->keyboardFactory->discountCodeInputMenu((int) ($draft->getId() ?? 0)));
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

        $this->discountApplication->clearOnDraft($draft);
        $this->setDraftCurrentStep($draft, self::STEP_WAITING_PAYMENT_METHOD);
        $this->entityManager->flush();

        $this->sendPaymentGatewaySelectionForNewServiceDraft($draft, $chatId, $callbackId, 'skipped');
    }

    private function handleDiscountEnterOrder(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId = null): void
    {
        $order = $this->findPendingEditableOrder($account, $orderId);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_discount_enter_order');

            return;
        }

        $this->markOrderWaitingDiscountCode($order);
        $this->entityManager->flush();

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'کد تخفیف را ارسال کنید.', $this->keyboardFactory->discountCodeInputMenuForOrder((int) ($order->getId() ?? 0)));
    }

    private function handleDiscountSkipOrder(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId = null): void
    {
        $order = $this->findPendingEditableOrder($account, $orderId);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_discount_skip_order');

            return;
        }

        $this->discountApplication->clearOnOrder($order);
        $this->sendPaymentGatewaySelectionForOrder($order, $chatId, $callbackId, null, null, 'skipped');
    }

    private function handleNewServiceDraftDiscountCodeInput(TelegramAccount $account, OrderDraft $draft, string $codeInput, string $chatId): void
    {
        if ($draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return;
        }

        $outcome = $this->discountApplication->applyToDraft($draft, $codeInput, OrderType::NEW_SERVICE);
        $this->setDraftCurrentStep($draft, self::STEP_WAITING_PAYMENT_METHOD);
        $this->entityManager->flush();

        $outcome->applied
            ? $this->sendPaymentGatewaySelectionForNewServiceDraft($draft, $chatId, null, 'applied')
            : $this->sendPaymentGatewaySelectionForNewServiceDraft($draft, $chatId, null, 'invalid', $outcome->reason);
    }

    private function handleOrderDiscountCodeInput(TelegramAccount $account, Order $order, string $codeInput, string $chatId): void
    {
        if ($order->getUser()->getId() !== $account->getUser()->getId()) {
            return;
        }
        if (!$this->canEditOrderForPayment($order)) {
            return;
        }

        $outcome = $this->discountApplication->applyToOrder($order, $codeInput);
        $outcome->applied
            ? $this->sendPaymentGatewaySelectionForOrder($order, $chatId, null, null, null, 'applied')
            : $this->sendPaymentGatewaySelectionForOrder($order, $chatId, null, null, null, 'invalid', $outcome->reason);
    }

    private function createOrReuseNewServiceOrderPayment(Order $order, PaymentGateway $gateway, StorePaymentMethod $storePaymentMethod, string $chatId, ?string $callbackId = null): void
    {
        $start = $this->paymentStarter->start($order, $storePaymentMethod);
        if (PaymentStart::KIND_PLAN_INACTIVE === $start->kind) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این پلن دیگر فعال نیست یا وجود ندارد.', 'popup_invalid_plan_select_payment_method_draft');

            return;
        }
        if (PaymentStart::KIND_WALLET_PAYMENT_PENDING === $start->kind) {
            $this->showPopupOrMessage($chatId, $callbackId, self::WALLET_PAYMENT_PENDING_TEXT, 'popup_wallet_payment_pending');

            return;
        }
        if (PaymentStart::KIND_WALLET_PAYMENT_HELD === $start->kind) {
            $this->showPopupOrMessage($chatId, $callbackId, self::WALLET_PAYMENT_HELD_TEXT, 'popup_wallet_payment_held');

            return;
        }
        $plan = $order->getPlan();
        $payment = $start->payment ?? throw new \LogicException('A started payment has a payment.');
        $finalAmount = $start->amount;
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $this->debugLog(sprintf('payment_gateway_selected order_id=%d payment_id=%d amount=%d', $order->getId() ?? 0, $payment->getId() ?? 0, $finalAmount));

        if (PaymentStart::KIND_ONLINE === $start->kind) {
            if (!$start->success || null === $payment->getPaymentUrl()) {
                $logKey = match ($gateway->getPluginCode()) {
                    null => 'zibal_request_failed_new_service',
                    default => 'plugin_request_failed_new_service',
                };
                $this->showPopupOrMessage($chatId, $callbackId, $start->message ?: 'ایجاد لینک پرداخت آنلاین انجام نشد.', $logKey);

                return;
            }

            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage(
                $chatId,
                sprintf("برای پرداخت آنلاین روی دکمه زیر بزنید.\nکد پیگیری: %s", (string) ($order->getTrackingCode() ?? '-')),
                $this->keyboardFactory->paymentOnlineActionMenu((int) ($payment->getId() ?? 0), (int) ($order->getId() ?? 0), (string) $payment->getPaymentUrl())
            );

            return;
        }

        if (PaymentStart::KIND_CRYPTO === $start->kind) {
            if (!$start->success) {
                $this->showPopupOrMessage($chatId, $callbackId, $start->message ?: 'ایجاد پرداخت ارز دیجیتال انجام نشد.', 'nowpayments_create_failed');

                return;
            }

            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage(
                $chatId,
                $this->buildCryptoPaymentMessage($payment, $order),
                $this->keyboardFactory->cryptoPaymentActionMenu(
                    (int) ($payment->getId() ?? 0),
                    (int) ($order->getId() ?? 0),
                    $payment->getPaymentUrl()
                )
            );

            return;
        }

        $card = $this->paymentStarter->manualCard($gateway);
        [$cardNumber, $cardHolder, $description] = [$card->cardNumber, $card->cardHolder, $card->instructions];
        if (!$plan instanceof Plan) {
            // A wallet top-up: no plan or account lines, only the amount to transfer.
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, trim($this->botTextResolver->message('wallet.topup_manual_card', [
                'order' => ['trackingCode' => (string) ($order->getTrackingCode() ?? '-'), 'finalAmount' => $finalAmount],
                'payment' => ['cardNumber' => $cardNumber ?: '-', 'cardHolder' => $cardHolder ?: '-', 'extraInstructions' => $description ?: ''],
                'button' => ['confirmAndSendReceipt' => $this->botTextResolver->button('button.confirm_and_send_receipt')],
            ])), $this->keyboardFactory->paymentActionMenu((int) $payment->getId(), (int) ($order->getId() ?? 0)));

            return;
        }
        $discountCode = trim((string) ($priceSnapshot['discountCode'] ?? ''));
        $message = $this->botTextResolver->message('payment.manual_card.custom_order_instructions', [
            'order' => [
                'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
                'accountName' => (string) ($metadata['finalUsername'] ?? '-'),
                'trafficGb' => null === ($metadata['trafficGb'] ?? null) ? '-' : (string) $metadata['trafficGb'],
                'duration' => null === ($metadata['durationDays'] ?? null) || $plan->isUnlimitedDuration() ? 'نامحدود' : ((string) $metadata['durationDays'].' روز'),
                'baseAmount' => (int) ($priceSnapshot['baseAmount'] ?? 0),
                'globalDiscount' => (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
                'discountCode' => '' === $discountCode ? 'ندارد' : $discountCode,
                'discountAmount' => (int) ($priceSnapshot['discountCodeAmount'] ?? 0),
                'finalAmount' => $finalAmount,
            ],
            'plan' => [
                'title' => $plan->getTitle(),
            ],
            'payment' => [
                'cardNumber' => $cardNumber ?: '-',
                'cardHolder' => $cardHolder ?: '-',
                'extraInstructions' => $description ?: '',
            ],
            'button' => [
                'confirmAndSendReceipt' => $this->botTextResolver->button('button.confirm_and_send_receipt'),
            ],
        ]);

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId(), (int) ($order->getId() ?? 0)));
    }

    private function sendPaymentGatewaySelectionForNewServiceDraft(OrderDraft $draft, string $chatId, ?string $callbackId, ?string $discountResult = null, ?string $discountReason = null): void
    {
        $existingOrder = $this->findOrderByDraftIdForAnyOpenStatus($draft);
        if (!$existingOrder instanceof Order && !$this->salesSettingsProvider->newOrdersEnabled()) {
            $this->logSalesBlocked((int) ($draft->getUser()->getId() ?? 0), 'new_order', SalesSettingsProvider::NEW_ORDERS_ENABLED);
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, $this->salesDisabledMessage());

            return;
        }

        $order = $this->createOrderFromNewServiceDraft($draft);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_from_new_service_draft');

            return;
        }

        $this->sendPaymentGatewaySelectionForOrder($order, $chatId, $callbackId, null, null, $discountResult, $discountReason);
    }

    private function sendPaymentGatewaySelectionForOrder(Order $order, string $chatId, ?string $callbackId, ?string $backCallback = null, ?string $cancelCallback = null, ?string $discountResult = null, ?string $discountReason = null): void
    {
        if (OrderType::NEW_SERVICE === $order->getType()) {
            $telegramAccount = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
            if ($telegramAccount instanceof TelegramAccount && !$this->ensureMembershipGateAllowed($telegramAccount, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
                return;
            }
        }

        $methods = $this->paymentStarter->methodsFor($order);
        $offerWallet = $this->walletPayment->canPay($order);
        if ([] === $methods && !$offerWallet) {
            $diagnostics = $this->paymentStarter->diagnostics($order);
            $encodedReasons = json_encode($diagnostics['skippedReasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $encodedMethods = json_encode($diagnostics['methods'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->debugLog(sprintf(
                'no_store_payment_methods order_id=%d amount=%d payable_amount=%d currency=%s active_count=%d skipped_reasons=%s methods=%s',
                (int) ($diagnostics['orderId'] ?? 0),
                (int) ($diagnostics['amount'] ?? 0),
                (int) ($diagnostics['payableAmount'] ?? 0),
                (string) ($diagnostics['currency'] ?? 'IRR'),
                (int) ($diagnostics['activeStorePaymentMethodCount'] ?? 0),
                false === $encodedReasons ? '[]' : $encodedReasons,
                false === $encodedMethods ? '[]' : $encodedMethods
            ));
            $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('payment.no_methods'), 'no_active_store_payment_method_new_service');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $backCallback ??= 'order_summary:'.((int) ($order->getId() ?? 0));
        $cancelCallback ??= 'order_cancel:'.((int) ($order->getId() ?? 0));
        $message = $this->botTextResolver->message('payment.method_select');
        if (null !== $discountResult) {
            $message = $this->buildDiscountResultPaymentMethodMessage($order, $discountResult, $discountReason);
        }

        $this->telegramApiClient->sendMessage(
            $chatId,
            $message,
            $this->keyboardFactory->paymentGatewaySelectionMenu((int) ($order->getId() ?? 0), $methods, $backCallback, $cancelCallback, $offerWallet)
        );
    }

    private function createOrderFromNewServiceDraft(OrderDraft $draft): ?Order
    {
        return $this->newServiceCheckout->createOrderFromDraft($draft);
    }

    private function buildDiscountResultPaymentMethodMessage(Order $order, string $discountResult, ?string $discountReason = null): string
    {
        $variables = $this->buildDiscountSummaryVariables($order, $discountResult, $discountReason);
        $parts = [];

        if ('applied' === $discountResult) {
            $parts[] = $this->botTextResolver->message('discount.applied', $variables);
        } elseif ('invalid' === $discountResult) {
            $parts[] = $this->botTextResolver->message('discount.invalid', $variables);
        } elseif ('skipped' === $discountResult) {
            $parts[] = $this->botTextResolver->message('discount.skipped', $variables);
        }

        $parts[] = $this->botTextResolver->message('order.summary_after_discount', $variables);
        $parts[] = $this->botTextResolver->message('payment.method_select', $variables);

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => '' !== trim($part))));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDiscountSummaryVariables(Order $order, string $discountResult, ?string $discountReason = null): array
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $originalAmount = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $order->getAmount());
        $discountAmount = (int) ($priceSnapshot['discountCodeAmount'] ?? 0);
        $payableAmount = (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount());
        $plan = $order->requirePlan();

        return [
            'discount' => [
                'code' => (string) ($priceSnapshot['discountCode'] ?? $metadata['discountCode'] ?? '-'),
                'amount' => $this->formatMoney($discountAmount),
                'reason' => trim((string) $discountReason),
            ],
            'payment' => [
                'originalAmount' => $this->formatMoney($originalAmount),
                'discountAmount' => $this->formatMoney($discountAmount),
                'payableAmount' => $this->formatMoney($payableAmount),
                'amountBlock' => $this->buildPaymentAmountBlock($discountResult, $originalAmount, $discountAmount, $payableAmount),
            ],
            'order' => [
                'id' => (int) ($order->getId() ?? 0),
                'accountName' => $this->resolveOrderAccountName($order, $metadata),
                'volume' => $this->resolveOrderVolume($order, $metadata),
                'duration' => $this->resolveOrderDuration($order, $metadata),
                'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
            ],
            'plan' => [
                'title' => $plan->getTitle(),
            ],
        ];
    }

    private function buildPaymentAmountBlock(string $discountResult, int $originalAmount, int $discountAmount, int $payableAmount): string
    {
        if ('applied' === $discountResult) {
            return implode("\n", [
                '💰 مبلغ اولیه: '.$this->formatMoney($originalAmount).' تومان',
                '🎁 میزان تخفیف: '.$this->formatMoney($discountAmount).' تومان',
                '💳 مبلغ نهایی: '.$this->formatMoney($payableAmount).' تومان',
            ]);
        }

        return '💰 مبلغ نهایی: '.$this->formatMoney($payableAmount).' تومان';
    }

    private function formatMoney(int $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveOrderAccountName(Order $order, array $metadata): string
    {
        $candidate = trim((string) ($metadata['finalUsername'] ?? $metadata['customUsername'] ?? ''));
        if ('' !== $candidate) {
            return $candidate;
        }

        $targetService = $order->getTargetService();
        if (null !== $targetService && '' !== trim((string) $targetService->getUsername())) {
            return (string) $targetService->getUsername();
        }

        return '-';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveOrderVolume(Order $order, array $metadata): string
    {
        if (array_key_exists('trafficGb', $metadata) && null !== $metadata['trafficGb']) {
            return ((string) $metadata['trafficGb']).' گیگ';
        }

        $planTraffic = $order->requirePlan()->getTrafficGb();
        if (null !== $planTraffic) {
            return ((string) $planTraffic).' گیگ';
        }

        return 'نامحدود';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveOrderDuration(Order $order, array $metadata): string
    {
        if ((bool) ($metadata['unlimitedDuration'] ?? false) || $order->requirePlan()->isUnlimitedDuration()) {
            return 'نامحدود';
        }

        if (array_key_exists('durationDays', $metadata) && null !== $metadata['durationDays']) {
            return ((string) $metadata['durationDays']).' روز';
        }

        return ((string) $order->requirePlan()->getDurationDays()).' روز';
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

        // A wallet top-up is paid with the same store methods as a new service (WalletBotHandler created it).
        if (OrderType::NEW_SERVICE !== $order->getType() && !$order->isWalletTopUp()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'popup_invalid_payment_gateway_new_service_type');

            return;
        }

        $storePaymentMethod = $this->findStorePaymentMethodForOrder($order, $storePaymentMethodId);
        if (!$storePaymentMethod instanceof StorePaymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'popup_invalid_store_payment_method');

            return;
        }

        if (OrderType::NEW_SERVICE === $order->getType() && !$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
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

        if (PaymentGatewayType::NOWPAYMENTS === $gatewayType) {
            $cryptoStatus = $payment->getCryptoPaymentStatus() ?? ($verify->message ?? '');

            if (!$verify->success && NowPaymentsGateway::PAYMENT_NOT_REPORTED_MESSAGE === ($verify->message ?? '')) {
                $this->showPopupOrMessage($chatId, $callbackId, NowPaymentsGateway::PAYMENT_NOT_REPORTED_MESSAGE, 'payment_check_nowpayments_not_reported');

                return;
            }

            // partially_paid is reported as paid with a pro-rated amount; settlement parks it for review.
            if (in_array(strtolower($cryptoStatus), NowPaymentsGateway::FAILED_STATUSES, true)) {
                $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت منقضی یا ناموفق شده است.', 'payment_check_failed_expired');

                return;
            }

            if (!$verify->success || !$verify->paid) {
                $displayStatus = '' !== $cryptoStatus ? $cryptoStatus : 'نامشخص';
                $this->showPopupOrMessage($chatId, $callbackId, sprintf('پرداخت هنوز تایید نشده است. وضعیت: %s', $displayStatus), 'payment_check_not_paid');

                return;
            }
        } elseif (!$verify->success || !$verify->paid) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پرداخت هنوز تایید نشده است.', 'payment_check_not_paid');

            return;
        }

        $result = $this->paymentSettlementService->settle($payment, VerifiedPayment::fromGateway($verify), 'telegram_payment_check');
        [$text, $event] = match ($result->outcome) {
            SettlementOutcome::CONFIRMED => ['پرداخت تایید شد.', 'payment_check_confirmed'],
            SettlementOutcome::ALREADY_PROCESSED => [match ($result->payment?->getStatus()) {
                PaymentStatus::CONFIRMED => 'پرداخت تایید شد.',
                PaymentStatus::NEEDS_REVIEW => 'پرداخت دریافت شده و در حال بررسی توسط پشتیبانی است.',
                PaymentStatus::PROVISIONING_FAILED => 'پرداخت دریافت شده؛ ساخت سرویس توسط پشتیبانی پیگیری می‌شود.',
                PaymentStatus::REJECTED => 'این پرداخت رد یا لغو شده است. اگر مبلغی از حساب شما کسر شده، با پشتیبانی تماس بگیرید.',
                default => 'وضعیت پرداخت تغییر نکرد. لطفاً کمی بعد دوباره بررسی کنید.',
            }, 'payment_check_already_processed'],
            SettlementOutcome::NEEDS_REVIEW => [$result->payment?->isLatePaymentInReview()
                ? 'پرداخت پس از لغو یا پایان مهلت سفارش دریافت شد؛ از بین نرفته و برای بررسی به پشتیبانی ارسال شد.'
                : 'مبلغ یا ارز پرداختی با سفارش مطابقت ندارد؛ پرداخت برای بررسی به پشتیبانی ارسال شد.', 'payment_check_needs_review'],
            SettlementOutcome::PROVISIONING_FAILED => ['پرداخت دریافت شد اما ساخت سرویس با خطا مواجه شد؛ پشتیبانی مطلع شد.', 'payment_check_provisioning_failed'],
            default => ['بررسی پرداخت انجام نشد. لطفاً کمی بعد دوباره تلاش کنید.', 'payment_check_confirm_failed'],
        };
        $this->showPopupOrMessage($chatId, $callbackId, $text, $event);
    }

    private function buildCryptoPaymentMessage(Payment $payment, Order $order): string
    {
        if (null !== $payment->getPaymentUrl() && '' !== trim((string) $payment->getPaymentUrl())) {
            return implode("\n", [
                'پرداخت ارز دیجیتال آماده است.',
                'برای پرداخت روی دکمه زیر بزنید.',
                '',
                'وضعیت: '.self::NOWPAYMENTS_WAITING_STATUS_TEXT,
                sprintf('کد پیگیری سفارش: %s', (string) ($order->getTrackingCode() ?? $order->getId() ?? '-')),
            ]);
        }

        $payAmount = $payment->getCryptoPayAmount() ?? '-';
        $payCurrency = strtoupper($payment->getCryptoPayCurrency() ?? '-');
        $address = $payment->getCryptoAddress() ?? '-';
        $trackingCode = (string) ($order->getTrackingCode() ?? $order->getId() ?? '-');

        $lines = [
            '💎 پرداخت ارز دیجیتال',
            '',
            sprintf('مبلغ پرداختی: %s %s', $payAmount, $payCurrency),
            '',
            'آدرس کیف پول:',
            $address,
            '',
            'وضعیت: '.self::NOWPAYMENTS_WAITING_STATUS_TEXT,
            sprintf('کد پیگیری سفارش: %s', $trackingCode),
        ];

        return implode("\n", $lines);
    }

    private function handlePaymentSubmitReceipt(TelegramAccount $account, string $chatId, int $paymentId, string $callbackId): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('payment.failed'), 'popup_payment_submit_receipt_not_found');

            return;
        }

        switch ($this->receiptSubmission->check($payment, $account->getUser())) {
            case ReceiptSubmission::NOT_YOURS:
                $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'popup_payment_submit_receipt_unauthorized');

                return;
            case ReceiptSubmission::ALREADY_SUBMITTED:
                $this->showPopupOrMessage($chatId, $callbackId, 'رسید این پرداخت قبلاً ارسال شده است.', 'popup_payment_submit_receipt_already_submitted');

                return;
            case ReceiptSubmission::NOT_PENDING:
                $this->showPopupOrMessage($chatId, $callbackId, 'امکان ارسال رسید برای این پرداخت وجود ندارد.', 'popup_payment_submit_receipt_invalid_status');

                return;
            case ReceiptSubmission::WALLET_PAYMENT_HELD:
                $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('payment.receipt_wallet_held'), 'popup_payment_submit_receipt_wallet_held');

                return;
        }

        $this->receiptSubmission->expectReceipt($payment);

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'لطفاً تصویر رسید یا کد پیگیری را در همین چت ارسال کنید.', true);
    }

    private function handlePaymentCancel(TelegramAccount $account, string $chatId, int $paymentId, string $callbackId): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('payment.failed'), 'popup_payment_cancel_not_found');

            return;
        }

        if ($payment->getOrder()->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'popup_payment_cancel_unauthorized');

            return;
        }

        $order = $payment->getOrder();
        if (!$this->canCancelIncompleteOrder($order, $payment)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان لغو این پرداخت وجود ندارد.', 'popup_payment_cancel_not_allowed');

            return;
        }

        if (OrderLifecycle::CANCEL_CANCELLED !== $this->orderLifecycle->cancel($order)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان لغو این پرداخت وجود ندارد.', 'popup_payment_cancel_not_allowed');

            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'سفارش ناتمام حذف شد.', true);
    }

    private function handleMyServices(TelegramAccount $account, string $chatId, ?string $callbackId = null): void
    {
        $this->serviceManagementService->handleMyServices($account, $chatId, $callbackId);
    }

    /**
     * Active app downloads by platform, as URL buttons to /d/{id}. Without a public shop address (DEFAULT_URI)
     * Telegram would refuse the buttons, so only the titles are listed.
     */
    private function handleAppDownloads(string $chatId): void
    {
        $downloads = [];
        foreach ($this->appDownloads->active() as $download) {
            $downloads[] = ['title' => $download->getTitle(), 'platform' => $download->getPlatform(), 'url' => $this->appDownloads->absoluteUrl($download)];
        }
        if ([] === $downloads) {
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('downloads.empty'));

            return;
        }

        if (!PublicUrlPolicy::isPubliclyUsableUrl($downloads[0]['url'])) {
            $titles = array_map(static fn (array $d): string => '• '.$d['title'], $downloads);
            $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('downloads.list')."\n\n".implode("\n", $titles));

            return;
        }

        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('downloads.list'), $this->keyboardFactory->appDownloads($downloads));
    }

    private function handleSupport(string $chatId, ?string $callbackId = null): void
    {
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('bot.support'), $this->keyboardFactory->backToMainMenu());
    }

    private function handleReceiptPhoto(Payment $openPayment, array $message, string $chatId): void
    {
        $photos = $message['photo'];
        $lastPhoto = end($photos);
        $fileId = is_array($lastPhoto) ? (string) ($lastPhoto['file_id'] ?? '') : '';
        if ('' === $fileId) {
            return;
        }

        $this->answerReceipt($this->receiptSubmission->submitPhoto($openPayment, $fileId), $chatId);
    }

    private function handleReceiptText(Payment $openPayment, string $text, string $chatId): void
    {
        $this->answerReceipt($this->receiptSubmission->submitTrackingCode($openPayment, $text), $chatId);
    }

    /** ReceiptSubmission::submit*()'s answer: recorded, or refused (nothing changed). */
    private function answerReceipt(string $result, string $chatId): void
    {
        $this->telegramApiClient->sendMessage($chatId, match ($result) {
            ReceiptSubmission::READY => $this->botTextResolver->message('payment.receipt_received'),
            ReceiptSubmission::WALLET_PAYMENT_HELD => $this->botTextResolver->message('payment.receipt_wallet_held'),
            default => 'امکان ارسال رسید برای این پرداخت وجود ندارد.',
        });
    }

    private function findOpenPayment(TelegramAccount $account): ?Payment
    {
        return $this->receiptSubmission->openManualPayment($account->getUser());
    }

    private function findStorePaymentMethodForOrder(Order $order, int $storePaymentMethodId): ?StorePaymentMethod
    {
        return $this->paymentStarter->methodFor($order, $storePaymentMethodId);
    }

    private function findStorePaymentMethodByGatewayForOrder(Order $order, int $gatewayId): ?StorePaymentMethod
    {
        return $this->paymentStarter->methodForGateway($order, $gatewayId);
    }

    private function findPendingNewServiceOrder(TelegramAccount $account, int $orderId): ?Order
    {
        $order = $this->findPendingEditableOrder($account, $orderId);
        if (!$order instanceof Order || OrderType::NEW_SERVICE !== $order->getType()) {
            return null;
        }

        return $order;
    }

    private function findPendingEditableOrder(TelegramAccount $account, int $orderId): ?Order
    {
        return $this->orderLifecycle->editableOrder($account->getUser(), $orderId);
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

    private function findOrderByDraftIdForAnyOpenStatus(OrderDraft $draft): ?Order
    {
        return $this->newServiceCheckout->openOrderForDraft($draft);
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
            ['user' => $account->getUser()],
            ['id' => 'DESC'],
            20
        );

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            if (!$this->canEditOrderForPayment($order)) {
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

    private function canEditOrderForPayment(Order $order): bool
    {
        return $this->orderLifecycle->canEditForPayment($order);
    }

    private function findActiveOrderDraft(TelegramAccount $account): ?OrderDraft
    {
        return $this->newServiceCheckout->activeDraft($account->getUser());
    }

    private function handleDraftBack(TelegramAccount $account, string $chatId, int $draftId, ?string $callbackId): void
    {
        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (
            !$draft instanceof OrderDraft
            || $draft->getUser()->getId() !== $account->getUser()->getId()
            || OrderDraftStatus::PENDING !== $draft->getStatus()
        ) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پیش‌نویس سفارش نامعتبر است.', 'invalid_draft_back');

            return;
        }

        $previousStep = $this->popDraftPreviousStep($draft);
        if (null === $previousStep) {
            $this->showPopupOrMessage($chatId, $callbackId, 'مرحله قبلی وجود ندارد.', 'draft_back_no_history');

            return;
        }

        $draft->setStep($previousStep)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->acknowledgeCallback($callbackId);
        $this->renderDraftStep($account, $draft, $chatId);
    }

    private function handleOrderBackToPaymentMethods(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId): void
    {
        $order = $this->findPendingEditableOrder($account, $orderId);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_back_to_methods');

            return;
        }

        if (OrderType::NEW_SERVICE === $order->getType()) {
            if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
                return;
            }

            $this->sendPaymentGatewaySelectionForOrder($order, $chatId, $callbackId, 'order_summary:'.$orderId, 'order_cancel:'.$orderId);

            return;
        }

        if (!$this->serviceManagementService->showPaymentMethodsForOrder($account, $orderId, $chatId, $callbackId)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_back_to_methods_service');
        }
    }

    private function handleOrderCancel(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order || $order->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_cancel');

            return;
        }

        match ($this->orderLifecycle->cancel($order)) {
            OrderLifecycle::CANCEL_ALREADY_CLOSED => $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام حذف شد.', 'order_cancel_already_done'),
            OrderLifecycle::CANCEL_CANCELLED => $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام حذف شد.', 'order_cancelled'),
            default => $this->showPopupOrMessage($chatId, $callbackId, 'امکان لغو این سفارش وجود ندارد.', 'order_cancel_not_allowed'),
        };
    }

    private function handleOrderResume(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || !$this->canEditOrderForPayment($order)
        ) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام یافت نشد.', 'resume_order_not_found');

            return;
        }

        // A reseller's order is paid from his wallet only: never a payment-method or card-to-card screen; its state
        // (money held while processed, with Cancel) as the reseller menu words it.
        if ($order->isResellerOrder()) {
            $this->resellerBotHandler->showHeldOrder($order, $chatId, $callbackId);

            return;
        }

        if (OrderType::NEW_SERVICE === $order->getType() && !$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }

        $payment = $this->findLatestOrderPayment($order);
        if (!$payment instanceof Payment) {
            if (OrderType::NEW_SERVICE === $order->getType()) {
                $this->sendPaymentGatewaySelectionForOrder($order, $chatId, $callbackId, 'order_summary:'.$orderId, 'order_cancel:'.$orderId);
            } else {
                $this->serviceManagementService->showPaymentMethodsForOrder($account, $orderId, $chatId, $callbackId);
            }

            return;
        }

        $gatewayType = (string) ($payment->getGatewayType() ?? $payment->getMethod());
        if (PaymentStatus::PENDING !== $payment->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این سفارش در حال بررسی است.', 'resume_order_payment_not_pending');

            return;
        }

        $gateway = $payment->getGateway();
        $this->acknowledgeCallback($callbackId);
        if ((in_array($gatewayType, [PaymentGatewayType::ZIBAL, PaymentGatewayType::CUSTOM_API], true) || null !== $gateway?->getPluginCode()) && null !== $payment->getPaymentUrl()) {
            $this->telegramApiClient->sendMessage(
                $chatId,
                sprintf("پرداخت آنلاین ناتمام شما آماده است.\nکد پیگیری: %s", (string) ($order->getTrackingCode() ?? '-')),
                $this->keyboardFactory->paymentOnlineActionMenu((int) ($payment->getId() ?? 0), (int) ($order->getId() ?? 0), (string) $payment->getPaymentUrl())
            );

            return;
        }

        $card = $this->paymentStarter->manualCard($gateway);
        [$cardNumber, $cardHolder, $description] = [$card->cardNumber, $card->cardHolder, $card->instructions];
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->botTextResolver->message('payment.manual_card.incomplete_instructions', [
                'order' => [
                    'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
                    'finalAmount' => $payment->getAmount(),
                ],
                'payment' => [
                    'cardNumber' => $cardNumber ?: '-',
                    'cardHolder' => $cardHolder ?: '-',
                    'extraInstructions' => $description ?: '',
                ],
                'button' => [
                    'confirmAndSendReceipt' => $this->botTextResolver->button('button.confirm_and_send_receipt'),
                ],
            ]),
            $this->keyboardFactory->paymentActionMenu((int) ($payment->getId() ?? 0), (int) ($order->getId() ?? 0))
        );
    }

    private function handleDiscountBackOrder(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId): void
    {
        $this->handleOrderSummary($account, $chatId, $orderId, $callbackId);
    }

    private function handleOrderSummary(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order || $order->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'order_summary_invalid_order');

            return;
        }

        if (!$this->canEditOrderForPayment($order)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'این سفارش قبلاً پردازش شده است.', 'order_summary_already_processed');

            return;
        }

        $this->debugLog(sprintf(
            'action=render_order_summary order_id=%d user_id=%d status=%s',
            (int) ($order->getId() ?? 0),
            (int) ($account->getUser()->getId() ?? 0),
            $order->getStatus()
        ));
        $this->clearOrderDiscountCodeWaitingState($order);
        $this->entityManager->flush();
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->renderOrderSummary($order),
            $this->keyboardFactory->orderSummaryMenu((int) ($order->getId() ?? 0))
        );
    }

    private function renderOrderSummary(Order $order): string
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $payment = $this->findLatestOrderPayment($order);
        $payableAmount = $payment instanceof Payment ? $payment->getPayableAmount() : null;
        $finalAmount = $order->getAmount();
        if (null !== $payableAmount && $payableAmount > 0) {
            $finalAmount = $payableAmount;
        } elseif (isset($priceSnapshot['finalAmount'])) {
            $finalAmount = (int) $priceSnapshot['finalAmount'];
        }

        $typeSummary = match ($order->getType()) {
            OrderType::RENEWAL => sprintf(
                "نوع سفارش: تمدید\nشناسه سرویس: %s\nحجم تمدید: %s گیگ\nمدت تمدید: %s",
                (string) ($metadata['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                (string) ($metadata['trafficGb'] ?? '-'),
                true === ($metadata['unlimitedDuration'] ?? false)
                    ? 'نامحدود'
                    : ((string) ($metadata['durationDays'] ?? '-').' روز')
            ),
            OrderType::ADD_TRAFFIC => sprintf(
                "نوع سفارش: خرید حجم اضافه\nشناسه سرویس: %s\nحجم درخواستی: %s گیگ",
                (string) ($metadata['targetServiceId'] ?? ($order->getTargetService()?->getId() ?? '-')),
                (string) ($metadata['trafficGb'] ?? '-')
            ),
            OrderType::WALLET_TOPUP => 'نوع سفارش: شارژ کیف پول',
            OrderType::TRIAL => sprintf(
                "نوع سفارش: اکانت تست\nپلن تست: %s\nحجم: %s گیگ\nمدت: %s",
                (string) ($metadata['trialPlanTitle'] ?? $order->getPlan()?->getTitle() ?? '-'),
                (string) ($metadata['trafficGb'] ?? '-'),
                ((string) ($metadata['durationDays'] ?? '-').' روز')
            ),
            default => sprintf(
                "نوع سفارش: خرید سرویس جدید\nنام کاربری: %s\nحجم: %s گیگ\nمدت: %s",
                (string) ($metadata['finalUsername'] ?? '-'),
                (string) ($metadata['trafficGb'] ?? '-'),
                true === ($metadata['unlimitedDuration'] ?? false)
                    ? 'نامحدود'
                    : ((string) ($metadata['durationDays'] ?? '-').' روز')
            ),
        };

        return sprintf(
            "خلاصه سفارش #%d\nکد پیگیری: %s\nپلن: %s\n%s\n\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: %s (%d تومان)\nمبلغ نهایی: %d تومان",
            (int) ($order->getId() ?? 0),
            (string) ($order->getTrackingCode() ?? '-'),
            ($order->getPlan()?->getTitle() ?? '-'),
            $typeSummary,
            (int) ($priceSnapshot['baseAmount'] ?? $finalAmount),
            (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (int) ($priceSnapshot['discountCodeAmount'] ?? 0),
            $finalAmount
        );
    }

    private function handleResumeIncompleteOrder(TelegramAccount $account, string $chatId, int $id, ?string $callbackId): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($id);
        if (
            $order instanceof Order
            && $order->getUser()->getId() === $account->getUser()->getId()
            && in_array($order->getStatus(), [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING, OrderStatus::PENDING, OrderStatus::DRAFT], true)
        ) {
            $this->handleOrderResume($account, $chatId, $id, $callbackId);

            return;
        }

        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($id);
        if (
            $draft instanceof OrderDraft
            && $draft->getUser()->getId() === $account->getUser()->getId()
            && OrderDraftStatus::PENDING === $draft->getStatus()
        ) {
            if (!$this->ensureMembershipGateAllowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
                return;
            }

            $this->acknowledgeCallback($callbackId);
            $this->renderDraftStep($account, $draft, $chatId);

            return;
        }

        $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام یافت نشد.', 'resume_incomplete_not_found');
    }

    private function handleCancelIncompleteOrder(TelegramAccount $account, string $chatId, int $id, ?string $callbackId): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($id);
        if ($order instanceof Order && $order->getUser()->getId() === $account->getUser()->getId()) {
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage(
                $chatId,
                'آیا از حذف سفارش ناتمام مطمئن هستید؟',
                ['inline_keyboard' => [[
                    ['text' => '✅ بله، حذف شود', 'callback_data' => 'incomplete_order_cancel_confirm:order:'.$id],
                    ['text' => '❌ انصراف', 'callback_data' => 'main_menu'],
                ]]]
            );

            return;
        }

        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($id);
        if ($draft instanceof OrderDraft && $draft->getUser()->getId() === $account->getUser()->getId()) {
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage(
                $chatId,
                'آیا از حذف سفارش ناتمام مطمئن هستید؟',
                ['inline_keyboard' => [[
                    ['text' => '✅ بله، حذف شود', 'callback_data' => 'incomplete_order_cancel_confirm:draft:'.$id],
                    ['text' => '❌ انصراف', 'callback_data' => 'main_menu'],
                ]]]
            );

            return;
        }

        $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام یافت نشد.', 'cancel_incomplete_not_found');
    }

    private function hasActiveIncompleteOrder(TelegramAccount $account): bool
    {
        $has = $this->incompleteOrderService->hasActiveIncompleteForUser($account->getUser());
        $this->debugLog(sprintf('incomplete_detection user_id=%d has_incomplete=%s', (int) ($account->getUser()->getId() ?? 0), $has ? 'yes' : 'no'));

        return $has;
    }

    private function hasTrackableOrders(TelegramAccount $account): bool
    {
        return [] !== $this->findRecentTrackableOrders($account->getUser(), 1);
    }

    private function handleResumeIncompleteOrderByText(TelegramAccount $account, string $chatId, bool $isAdmin): void
    {
        $context = $this->incompleteOrderService->resume($account->getUser());
        if ($context instanceof IncompleteOrderContext && 'order' === $context->type) {
            $this->debugLog(sprintf('resume_action_target user_id=%d type=order id=%d', (int) ($account->getUser()->getId() ?? 0), $context->id));
            $this->handleOrderResume($account, $chatId, $context->id, null);

            return;
        }

        if ($context instanceof IncompleteOrderContext && 'draft' === $context->type) {
            $draft = $this->entityManager->getRepository(OrderDraft::class)->find($context->id);
            if ($draft instanceof OrderDraft) {
                $this->debugLog(sprintf('resume_action_target user_id=%d type=draft id=%d', (int) ($account->getUser()->getId() ?? 0), $context->id));
                if (!$this->ensureMembershipGateAllowed($account, $chatId, null, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
                    return;
                }

                $this->renderDraftStep($account, $draft, $chatId);

                return;
            }
        }

        $this->telegramApiClient->sendMessage($chatId, 'سفارش ناتمامی وجود ندارد.', $this->mainKeyboard($account, $isAdmin, false, $this->hasTrackableOrders($account)));
    }

    private function handleTrackOrdersByText(TelegramAccount $account, string $chatId, bool $isAdmin): void
    {
        $orders = $this->findRecentTrackableOrders($account->getUser(), 5);
        $this->debugLog(sprintf('trackable_order_count user_id=%d count=%d', (int) ($account->getUser()->getId() ?? 0), count($orders)));
        if ([] === $orders) {
            $this->telegramApiClient->sendMessage($chatId, 'سفارش قابل پیگیری وجود ندارد.', $this->mainKeyboard($account, $isAdmin, $this->hasActiveIncompleteOrder($account), false));

            return;
        }
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'text' => sprintf('%s | %s', (string) ($order->getTrackingCode() ?? ('#'.$order->getId())), $this->translateOrderStatus($order->getStatus())),
                'callback_data' => 'track_order:'.$order->getId(),
            ];
        }
        $this->telegramApiClient->sendMessage($chatId, 'سفارش‌های اخیر شما:', $this->keyboardFactory->trackOrdersMenu($rows));
    }

    private function handleTrackOrdersCallback(TelegramAccount $account, string $chatId, ?string $callbackId): void
    {
        $this->acknowledgeCallback($callbackId);
        $orders = $this->findRecentTrackableOrders($account->getUser(), 5);
        if ([] === $orders) {
            $this->telegramApiClient->sendMessage($chatId, 'سفارش قابل پیگیری وجود ندارد.');

            return;
        }
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'text' => sprintf('%s | %s', (string) ($order->getTrackingCode() ?? ('#'.$order->getId())), $this->translateOrderStatus($order->getStatus())),
                'callback_data' => 'track_order:'.$order->getId(),
            ];
        }
        $this->telegramApiClient->sendMessage($chatId, 'سفارش‌های اخیر شما:', $this->keyboardFactory->trackOrdersMenu($rows));
    }

    private function handleTrackOrderDetail(TelegramAccount $account, string $chatId, int $orderId, ?string $callbackId): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order || $order->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'track_order_invalid');

            return;
        }

        $payment = $this->findLatestOrderPayment($order);
        $amount = $payment instanceof Payment && null !== $payment->getPayableAmount() ? $payment->getPayableAmount() : $order->getAmount();
        $message = sprintf(
            "پیگیری سفارش\n\nکد پیگیری: %s\nشماره سفارش: #%d\nنوع سفارش: %s\nوضعیت سفارش: %s\nمبلغ: %d تومان\nروش پرداخت: %s\nوضعیت پرداخت: %s\nایجاد: %s\nآخرین تغییر: %s\nشناسه سرویس: %s\n\n%s",
            (string) ($order->getTrackingCode() ?? '-'),
            (int) ($order->getId() ?? 0),
            $this->translateOrderType($order->getType()),
            $this->translateOrderStatus($order->getStatus()),
            (int) $amount,
            $this->resolvePaymentMethodLabel($payment),
            $this->translatePaymentStatus($payment?->getStatus()),
            $order->getCreatedAt()->format('Y-m-d H:i'),
            $this->resolveOrderUpdatedAt($order, $payment)->format('Y-m-d H:i'),
            (string) ($order->getTargetService()?->getId() ?? '-'),
            $this->resolveTrackOrderNextActionText($order, $payment)
        );

        $this->acknowledgeCallback($callbackId);
        $canResume = in_array($order->getStatus(), [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING], true)
            && (!$payment instanceof Payment || PaymentStatus::PENDING === $payment->getStatus());
        $this->telegramApiClient->sendMessage(
            $chatId,
            $message,
            // The service button only for its owner (a reseller's renewal of a service his customer claimed: no way in).
            $this->keyboardFactory->trackOrderDetailMenu((int) ($order->getId() ?? 0), $order->getTargetService()?->getUser()->getId() === $account->getUser()->getId() ? $order->getTargetService()?->getId() : null, $canResume)
        );
    }

    private function handleCancelIncompleteOrderByText(TelegramAccount $account, string $chatId, bool $isAdmin): void
    {
        $context = $this->incompleteOrderService->findActiveIncompleteForUser($account->getUser());
        if ($context instanceof IncompleteOrderContext) {
            $this->debugLog(sprintf('cancel_action_target user_id=%d type=%s id=%d', (int) ($account->getUser()->getId() ?? 0), $context->type, $context->id));
            $this->telegramApiClient->sendMessage(
                $chatId,
                'آیا از حذف سفارش ناتمام مطمئن هستید؟',
                ['inline_keyboard' => [
                    [
                        ['text' => '✅ بله، حذف شود', 'callback_data' => sprintf('incomplete_order_cancel_confirm:%s:%d', $context->type, $context->id)],
                        ['text' => '❌ انصراف', 'callback_data' => 'main_menu'],
                    ],
                ]]
            );

            return;
        }

        $this->telegramApiClient->sendMessage($chatId, 'سفارش ناتمامی وجود ندارد.', $this->mainKeyboard($account, $isAdmin, false, $this->hasTrackableOrders($account)));
    }

    private function handleIncompleteOrderCancelConfirm(TelegramAccount $account, string $chatId, string $data, ?string $callbackId, bool $isAdmin): void
    {
        $raw = str_replace('incomplete_order_cancel_confirm:', '', $data);
        $parts = explode(':', $raw);
        $context = null;
        if (2 === count($parts)) {
            $context = new IncompleteOrderContext((string) $parts[0], (int) $parts[1]);
        } else {
            $id = (int) $raw;
            if ($id > 0) {
                $order = $this->entityManager->getRepository(Order::class)->find($id);
                $context = $order instanceof Order ? new IncompleteOrderContext('order', $id) : new IncompleteOrderContext('draft', $id);
            }
        }
        if (!$context instanceof IncompleteOrderContext || !in_array($context->type, ['order', 'draft'], true)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام یافت نشد.', 'incomplete_cancel_confirm_not_found');

            return;
        }

        $cancelled = $this->incompleteOrderService->cancelContext($account->getUser(), $context);
        if ($cancelled instanceof IncompleteOrderContext) {
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, 'سفارش ناتمام حذف شد.', $this->mainKeyboard($account, $isAdmin, false, $this->hasTrackableOrders($account)));

            return;
        }

        $this->showPopupOrMessage($chatId, $callbackId, 'سفارش ناتمام یافت نشد.', 'incomplete_cancel_confirm_not_found');
    }

    private function sendIncompleteOrderPromptIfAny(TelegramAccount $account, string $chatId): void
    {
        $order = $this->findLatestIncompleteOrder($account);
        if ($order instanceof Order) {
            $this->telegramApiClient->sendMessage($chatId, 'شما یک سفارش ناتمام دارید.', $this->keyboardFactory->incompleteOrderPrompt((int) ($order->getId() ?? 0)));

            return;
        }

        $draft = $this->findActiveOrderDraft($account);
        if ($draft instanceof OrderDraft) {
            $this->telegramApiClient->sendMessage($chatId, 'شما یک سفارش ناتمام دارید.', $this->keyboardFactory->incompleteOrderPrompt((int) ($draft->getId() ?? 0)));
        }
    }

    /**
     * @return list<Order>
     */
    private function findRecentTrackableOrders(User $user, int $limit = 5): array
    {
        $orders = $this->entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->where('o.user = :user')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                OrderStatus::WAITING_PAYMENT,
                OrderStatus::PAYMENT_PENDING,
                OrderStatus::PAID,
                OrderStatus::PROCESSING,
                OrderStatus::COMPLETED,
                OrderStatus::FAILED,
                OrderStatus::CANCELLED,
                OrderStatus::EXPIRED,
            ])
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(array_filter($orders, static fn (mixed $order): bool => $order instanceof Order));
    }

    private function translateOrderType(string $type): string
    {
        return match ($type) {
            OrderType::RENEWAL => 'تمدید',
            OrderType::ADD_TRAFFIC => 'خرید حجم',
            OrderType::TRIAL => 'اکانت تست',
            OrderType::WALLET_TOPUP => 'شارژ کیف پول',
            default => 'خرید سرویس',
        };
    }

    private function translateOrderStatus(string $status): string
    {
        return match ($status) {
            OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING => 'در انتظار پرداخت',
            OrderStatus::PAID => 'پرداخت شده',
            OrderStatus::PROCESSING => 'در حال پردازش',
            OrderStatus::COMPLETED, OrderStatus::PROVISIONED => 'تکمیل شده',
            OrderStatus::FAILED => 'رد شده',
            OrderStatus::CANCELLED => 'لغو شده',
            OrderStatus::EXPIRED => 'منقضی شده',
            OrderStatus::PROVISIONING_FAILED => 'پرداخت شده، در حال پیگیری ساخت سرویس',
            default => $status,
        };
    }

    private function translatePaymentStatus(?string $status): string
    {
        return match ($status) {
            PaymentStatus::PENDING => 'در انتظار پرداخت',
            PaymentStatus::SUBMITTED => 'ارسال رسید',
            PaymentStatus::CONFIRMED => 'تایید شده',
            PaymentStatus::REJECTED => 'رد شده',
            PaymentStatus::NEEDS_REVIEW => 'در حال بررسی توسط پشتیبانی',
            PaymentStatus::PROVISIONING_FAILED => 'پرداخت شده، در حال پیگیری ساخت سرویس',
            default => '-',
        };
    }

    private function resolvePaymentMethodLabel(?Payment $payment): string
    {
        if (!$payment instanceof Payment) {
            return '-';
        }

        $gatewayType = (string) ($payment->getGatewayType() ?? $payment->getMethod());

        return match ($gatewayType) {
            PaymentGatewayType::MANUAL_CARD => 'کارت به کارت',
            PaymentGatewayType::ZIBAL => 'زیبال',
            PaymentGatewayType::CUSTOM_API => 'پرداخت آنلاین',
            default => $gatewayType,
        };
    }

    private function resolveOrderUpdatedAt(Order $order, ?Payment $payment): \DateTimeImmutable
    {
        if ($payment instanceof Payment && null !== $payment->getConfirmedAt()) {
            return $payment->getConfirmedAt();
        }
        if ($payment instanceof Payment && null !== $payment->getSubmittedAt()) {
            return $payment->getSubmittedAt();
        }

        return $order->getCreatedAt();
    }

    private function resolveTrackOrderNextActionText(Order $order, ?Payment $payment): string
    {
        if (in_array($order->getStatus(), [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING], true)) {
            if ($payment instanceof Payment && PaymentStatus::SUBMITTED === $payment->getStatus()) {
                return 'رسید شما ارسال شده و منتظر تایید ادمین است.';
            }

            return 'برای ادامه می‌توانید از دکمه «ادامه پرداخت» استفاده کنید.';
        }
        if (PaymentStatus::REJECTED === $payment?->getStatus() || OrderStatus::FAILED === $order->getStatus()) {
            return (string) ($payment?->getAdminNote() ?: 'پرداخت رد شده است. می‌توانید سفارش جدید ثبت کنید.');
        }
        if (in_array($order->getStatus(), [OrderStatus::COMPLETED, OrderStatus::PROVISIONED], true) && null !== $order->getTargetService()) {
            return 'برای مشاهده سرویس از دکمه «مشاهده سرویس» استفاده کنید.';
        }

        return 'وضعیت سفارش ثبت شده است.';
    }

    private function findLatestIncompleteOrder(TelegramAccount $account): ?Order
    {
        return $this->orderLifecycle->incompleteOrder($account->getUser());
    }

    private function canCancelIncompleteOrder(Order $order, ?Payment $payment): bool
    {
        return $this->orderLifecycle->canCancel($order, $payment);
    }

    private function findLatestOrderPayment(Order $order): ?Payment
    {
        return $this->orderLifecycle->latestPayment($order);
    }

    private function renderDraftStep(TelegramAccount $account, OrderDraft $draft, string $chatId): void
    {
        if ($draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return;
        }

        if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->telegramApiClient->sendMessage($chatId, 'این سفارش منقضی شده است.');

            return;
        }

        $step = $this->readDraftNavigation($draft)['currentStep'] ?? $draft->getStep();
        if (!is_string($step) || '' === trim($step)) {
            $step = $draft->getStep();
        }

        match ($step) {
            self::STEP_WAITING_CUSTOM_USERNAME => $this->telegramApiClient->sendMessage(
                $chatId,
                'لطفاً نام دلخواه اکانت را وارد کنید.',
                $this->keyboardFactory->customOrderUsernameInputMenu((int) ($draft->getId() ?? 0))
            ),
            self::STEP_WAITING_CUSTOM_TRAFFIC => $this->sendCustomDraftTrafficPrompt($chatId, $draft),
            self::STEP_WAITING_CUSTOM_DURATION => $this->sendCustomDraftDurationPrompt($chatId, $draft),
            self::STEP_WAITING_DISCOUNT_CODE => $this->telegramApiClient->sendMessage(
                $chatId,
                'کد تخفیف را ارسال کنید.',
                $this->keyboardFactory->discountCodeInputMenu((int) ($draft->getId() ?? 0))
            ),
            self::STEP_WAITING_PAYMENT_METHOD => $this->sendPaymentGatewaySelectionForNewServiceDraft($draft, $chatId, null),
            default => $this->sendCustomDraftSummary($chatId, $draft),
        };
    }

    private function readDraftNavigation(OrderDraft $draft): array
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $navigation = is_array($data['navigation'] ?? null) ? $data['navigation'] : [];
        $history = array_values(array_filter((array) ($navigation['history'] ?? []), static fn (mixed $value): bool => is_string($value) && '' !== trim($value)));

        return [
            'currentStep' => is_string($navigation['currentStep'] ?? null) ? $navigation['currentStep'] : $draft->getStep(),
            'history' => $history,
        ];
    }

    private function setDraftCurrentStep(OrderDraft $draft, string $step, bool $pushHistory = true): void
    {
        $navigation = $this->readDraftNavigation($draft);
        $currentStep = is_string($navigation['currentStep']) ? $navigation['currentStep'] : null;
        $history = $navigation['history'];
        if ($pushHistory && null !== $currentStep && '' !== trim($currentStep) && $currentStep !== $step) {
            $history[] = $currentStep;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $data['navigation'] = [
            'currentStep' => $step,
            'history' => $history,
        ];
        $draft->setStep($step)->setData($data);
    }

    private function popDraftPreviousStep(OrderDraft $draft): ?string
    {
        $navigation = $this->readDraftNavigation($draft);
        $history = $navigation['history'];
        $previousStep = array_pop($history);
        if (!is_string($previousStep) || '' === trim($previousStep)) {
            return null;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $data['navigation'] = [
            'currentStep' => $previousStep,
            'history' => $history,
        ];
        $draft->setData($data);

        return $previousStep;
    }

    private function parsePositiveInt(string $text): ?int
    {
        return PlanOptions::parsePositiveInt($text);
    }

    private function shouldAskCustomTraffic(Plan $plan): bool
    {
        return $this->planOptions->asksTraffic($plan);
    }

    private function shouldAskCustomDuration(Plan $plan): bool
    {
        return $this->planOptions->asksDuration($plan);
    }

    private function resolveDefaultTrafficGb(Plan $plan): ?int
    {
        return $this->planOptions->defaultTrafficGb($plan);
    }

    private function resolveDefaultDurationDays(Plan $plan): ?int
    {
        return $this->planOptions->defaultDurationDays($plan);
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

    private function sendSalesDisabledMessage(TelegramAccount $account, string $chatId, ?string $callbackId, bool $isAdmin, string $action, string $settingKey): void
    {
        $this->logSalesBlocked((int) ($account->getUser()->getId() ?? 0), $action, $settingKey);
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->salesDisabledMessage(),
            $this->mainKeyboard($account, $isAdmin, $this->hasActiveIncompleteOrder($account), $this->hasTrackableOrders($account))
        );
    }

    private function salesDisabledMessage(): string
    {
        return $this->botTextResolver->message('sales.disabled', [
            'sales' => [
                'disabledMessage' => $this->salesSettingsProvider->disabledMessage(),
            ],
        ]);
    }

    private function logSalesBlocked(int $userId, string $action, string $settingKey): void
    {
        $this->debugLog(sprintf(
            'sales_blocked user_id=%d action=%s setting_key=%s result=blocked',
            $userId,
            $action,
            $settingKey
        ));
    }

    private function debugLog(string $message): void
    {
        error_log('[TelegramUpdateHandler] '.$message);
    }
}
