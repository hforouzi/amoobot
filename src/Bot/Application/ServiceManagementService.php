<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Application\BotTextResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Wallet\Application\WalletPayment;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Provisioning\Application\CustomerAccessGuard;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Reseller\Application\ServiceOwnership;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceInboundCounts;
use App\Provisioning\Application\VpnConfigLinkSetResult;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\RenewalPriceResult;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Application\TrafficAddonPricingService;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Application\Checkout\DiscountApplication;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\ServiceOrderCheckout;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Shop\Application\OrderTrackingCodeService;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Security\AdminPermission;
use App\Subscription\Application\SubscriptionPageUrl;
use Doctrine\ORM\EntityManagerInterface;

class ServiceManagementService
{
    private const BYTES_PER_GB = 1073741824;
    public const STEP_WAITING_ADD_TRAFFIC_AMOUNT = 'waiting_add_traffic_amount';
    public const STEP_WAITING_DISCOUNT_DECISION = 'waiting_discount_decision';
    public const STEP_WAITING_DISCOUNT_CODE = 'waiting_discount_code';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceAdministration $serviceAdministration,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly BotTextResolver $botTextResolver,
        private readonly ServiceUsageSyncService $serviceUsageSyncService,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
        private readonly SalesSettingsProvider $salesSettingsProvider,
        private readonly VpnAccessMessageFormatter $vpnAccessMessageFormatter,
        private readonly QrCodeSender $qrCodeSender,
        private readonly CustomerAccessGuard $accessGuard,
        private readonly SubscriptionPageUrl $subscriptionPageUrl,
        private readonly CustomerServiceFinder $customerServices,
        private readonly ServiceOrderCheckout $serviceOrderCheckout,
        private readonly DiscountApplication $discountApplication,
        private readonly PaymentStarter $paymentStarter,
        private readonly WalletPayment $walletPayment,
        private readonly ServiceOwnership $serviceOwnership,
        private readonly ServiceInboundCounts $inboundCounts,
    ) {
    }

    public function handleMyServices(TelegramAccount $account, string $chatId, ?string $callbackId = null): void
    {
        $services = $this->customerServices->visibleTo($account->getUser(), 10);

        if ([] === $services) {
            $this->showPopupOrMessage($chatId, $callbackId, 'شما در حال حاضر سرویسی ندارید.', 'popup_no_services_user');

            return;
        }

        $serviceIds = [];
        foreach ($services as $service) {
            $serviceIds[] = (int) $service->getId();
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'سرویس‌های شما:', $this->keyboardFactory->userServicesList($serviceIds));
    }

    public function handleAdminServices(string $chatId, ?string $callbackId = null): void
    {
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->andWhere('service.status != :deletedStatus')
            ->setParameter('deletedStatus', VpnServiceStatus::DELETED)
            ->orderBy('service.id', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        if ([] === $services) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویسی برای نمایش وجود ندارد.', 'popup_no_services');

            return;
        }

        $serviceIds = [];
        foreach ($services as $service) {
            $serviceIds[] = (int) $service->getId();
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'آخرین سرویسها:', $this->keyboardFactory->adminServicesList($serviceIds));
    }

    public function showUserServiceDetail(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_view_user');

            return;
        }

        if ($service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_access_user');

            return;
        }

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'deleted_service_view_user_blocked');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->sendUserServiceDetail($chatId, $service, 'telegram_user_service_detail');
    }

    /**
     * @param list<AdminPermission>|null $granted the admin's permissions, to show only the allowed buttons
     */
    public function showAdminServiceDetail(int $serviceId, string $chatId, ?string $callbackId, ?array $granted = null): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_view_admin');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        if (!$this->ensureFreshConfigBeforeDelivery($chatId, $service, 'telegram_admin_service_detail')) {
            return;
        }
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->formatServiceDetailForAdmin($service),
            $this->keyboardFactory->adminServiceDetail((int) $service->getId(), (int) $service->getUser()->getId(), $granted)
        );
    }

    public function requestDeleteConfirmation(int $serviceId, string $chatId, string $callbackId): void
    {
        $this->debugLog(sprintf('admin_service_delete_request service_id=%d', $serviceId));
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_delete_request');
        if (!$service instanceof VpnService) {
            $this->debugLog(sprintf('admin_service_delete_request_invalid service_id=%d', $serviceId));

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            'آیا از حذف این سرویس مطمئن هستید؟',
            $this->keyboardFactory->serviceDeleteConfirmation((int) $service->getId())
        );
    }

    public function sendSubscription(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_subscription');

            return;
        }

        if ($service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_subscription');

            return;
        }

        if (!$this->ensureFreshConfigBeforeDelivery($chatId, $service, 'telegram_subscription')) {
            $this->acknowledgeCallback($callbackId);

            return;
        }

        $subscriptionUrl = (string) $this->accessGuard->subscriptionUrl($service);
        if ('' === $subscriptionUrl && '' !== trim((string) $service->getSubscriptionUrl())) {
            // Stored but not reachable by a client: the access message says so (contact support) and carries any usable link.
            $this->acknowledgeCallback($callbackId);
            $this->sendAccessMessages($chatId, $service, '✅ سرویس شما آماده شد', 'telegram_subscription_withheld');
            $this->debugLog(sprintf('service_subscription_withheld service_id=%d', $serviceId));

            return;
        }
        if ('' === $subscriptionUrl) {
            $this->showPopupOrMessage($chatId, $callbackId, 'لینک اشتراک برای این سرویس موجود نیست.', 'missing_subscription_url');
            $configLinkSet = $this->collectConfigLinks($service, 'telegram_subscription_fallback_config');
            if ([] !== $configLinkSet->finalLinks) {
                $this->sendAccessMessages($chatId, $service, '✅ سرویس شما آماده شد', 'telegram_subscription_fallback_config');
                $this->debugLog(sprintf(
                    'service_subscription_fallback_configs service_id=%d raw_link_count=%d formatted_link_count=%d final_sent_count=%d dropped_duplicate_count=%d',
                    $serviceId,
                    $configLinkSet->rawCount,
                    $configLinkSet->formattedCount,
                    count($configLinkSet->finalLinks),
                    $configLinkSet->droppedDuplicateCount
                ));
            }

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->sendAccessMessages($chatId, $service, '✅ سرویس شما آماده شد', 'telegram_subscription_only');
        $this->debugLog(sprintf('service_subscription_sent service_id=%d user_id=%d', $serviceId, $account->getUser()->getId()));
    }

    public function resendConfig(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_resend_config');

            return;
        }

        if (!$adminMode && $service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_resend_config');

            return;
        }

        if (!$this->ensureFreshConfigBeforeDelivery($chatId, $service, 'telegram_resend_config')) {
            $this->acknowledgeCallback($callbackId);

            return;
        }

        $configLinkSet = $this->collectConfigLinks($service, 'telegram_resend_config');
        if ([] === $this->accessGuard->configLinks($service, $configLinkSet->finalLinks) && null === $this->accessGuard->subscriptionUrl($service)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'اطلاعات اتصال کامل نیست. لطفاً با پشتیبانی تماس بگیرید.', 'missing_config');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->sendAccessMessages($chatId, $service, '✅ سرویس شما آماده شد', 'telegram_resend_config');
        $this->debugLog(sprintf(
            'service_resend_config service_id=%d actor_user_id=%d admin_mode=%s raw_link_count=%d formatted_link_count=%d final_sent_count=%d dropped_duplicate_count=%d',
            $serviceId,
            $account->getUser()->getId(),
            $adminMode ? 'true' : 'false',
            $configLinkSet->rawCount,
            $configLinkSet->formattedCount,
            count($configLinkSet->finalLinks),
            $configLinkSet->droppedDuplicateCount
        ));
    }

    public function sendSubscriptionQr(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService || $service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_subscription_qr');

            return;
        }

        if (!$this->ensureFreshConfigBeforeDelivery($chatId, $service, 'telegram_subscription_qr')) {
            $this->acknowledgeCallback($callbackId);

            return;
        }

        $subscriptionUrl = (string) $this->accessGuard->subscriptionUrl($service);
        if ('' === $subscriptionUrl) {
            if ('' !== trim((string) ($service->getSubscriptionUrl() ?? ''))) {
                $this->acknowledgeCallback($callbackId);
                $this->telegramApiClient->sendMessage($chatId, CustomerAccessGuard::SUPPORT_NOTICE);
            }
            $configLinkSet = $this->collectConfigLinks($service, 'telegram_qr_fallback_config');
            $qrLinks = $this->accessGuard->configLinks($service, $configLinkSet->finalLinks);
            if ([] === $qrLinks) {
                $this->showPopupOrMessage($chatId, $callbackId, 'برای این سرویس هنوز لینک اشتراک یا کانفیگ قابل ساخت QR موجود نیست.', 'missing_subscription_and_config_qr');

                return;
            }

            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, 'لینک اشتراک برای این سرویس موجود نیست. QR کانفیگ‌ها ارسال می‌شود.');

            $failedCount = 0;
            $total = count($qrLinks);
            foreach ($qrLinks as $index => $configLink) {
                try {
                    $sent = $this->qrCodeSender->send($chatId, $configLink, sprintf('QR کانفیگ %d از %d', $index + 1, $total), sprintf('service-%d-config-%d', $serviceId, $index + 1));
                } catch (\Throwable $e) {
                    ++$failedCount;
                    $this->debugLog(sprintf(
                        'config_qr_send_failed service_id=%d user_id=%d index=%d message="%s"',
                        $serviceId,
                        $account->getUser()->getId(),
                        $index + 1,
                        $e->getMessage()
                    ));
                    continue;
                }
                if (!$sent) {
                    ++$failedCount;
                    $this->debugLog(sprintf('qr_generation_failed service_id=%d suffix="config-%d"', $serviceId, $index + 1));
                    $this->telegramApiClient->sendMessage($chatId, 'ساخت QR Code با خطا مواجه شد. لطفاً از لینک متنی استفاده کنید.');
                }
            }

            $this->debugLog(sprintf(
                'service_config_qr_fallback service_id=%d user_id=%d raw_link_count=%d formatted_link_count=%d final_link_count=%d dropped_duplicate_count=%d failed_count=%d',
                $serviceId,
                $account->getUser()->getId(),
                $configLinkSet->rawCount,
                $configLinkSet->formattedCount,
                $total,
                $configLinkSet->droppedDuplicateCount,
                $failedCount
            ));

            return;
        }

        $this->acknowledgeCallback($callbackId);
        if (!$this->qrCodeSender->send($chatId, $subscriptionUrl, 'QR لینک اشتراک شما', sprintf('service-%d-subscription', $serviceId))) {
            $this->debugLog(sprintf('qr_generation_failed service_id=%d suffix="subscription"', $serviceId));
            $this->telegramApiClient->sendMessage($chatId, 'ساخت QR Code با خطا مواجه شد. لطفاً از لینک متنی استفاده کنید.');

            return;
        }
        $this->debugLog(sprintf('service_subscription_qr_sent service_id=%d user_id=%d link_count=1 failed_count=0', $serviceId, $account->getUser()->getId()));
    }

    public function sendConfigLinks(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->resendConfig($account, $serviceId, $chatId, $callbackId, false);
    }

    public function refreshUserService(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->syncServiceUsage($account, $serviceId, $chatId, $callbackId, false);
    }

    /**
     * @param list<AdminPermission>|null $granted the admin's permissions (admin mode), to show only the allowed buttons
     */
    public function syncServiceUsage(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode, ?array $granted = null): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_refresh_user');

            return;
        }

        if (!$adminMode && $service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_refresh_user');

            return;
        }

        try {
            $result = $this->serviceUsageSyncService->syncOne($service);
        } catch (\Throwable $e) {
            $this->debugLog(sprintf('service_sync_failure action=sync_usage service_id=%d message="%s"', $serviceId, $e->getMessage()));
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_refresh_failed');

            return;
        }

        if ($result->isFailed()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات بروزرسانی مصرف انجام نشد.', 'panel_refresh_failed');

            return;
        }

        $this->entityManager->refresh($service);
        $this->acknowledgeCallback($callbackId);
        if ($adminMode) {
            if (!$this->ensureFreshConfigBeforeDelivery($chatId, $service, 'telegram_admin_service_detail_after_refresh')) {
                return;
            }
            $this->telegramApiClient->sendMessage(
                $chatId,
                $this->formatServiceDetailForAdmin($service),
                $this->keyboardFactory->adminServiceDetail((int) $service->getId(), (int) $service->getUser()->getId(), $granted)
            );

            return;
        }

        $this->sendUserServiceDetail($chatId, $service, 'telegram_user_service_detail_after_refresh');
    }

    public function showRenewalSummary(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode): void
    {
        if (!$this->salesSettingsProvider->renewalsEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, 'renewal', SalesSettingsProvider::RENEWALS_ENABLED);

            return;
        }

        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_renew');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$adminMode && $service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_renew');

            return;
        }

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان تمدید این سرویس وجود ندارد.', 'invalid_service_renew_deleted');

            return;
        }

        $quote = $this->serviceOrderCheckout->renewalQuote($service);
        if (null === $quote) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان تمدید خودکار برای این سرویس وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.', 'renewal_package_missing');

            return;
        }

        $durationText = $quote->unlimitedDuration ? 'نامحدود' : ((string) $quote->durationDays.' روز');
        $carryTrafficText = $quote->carryRemainingTraffic ? 'بله' : 'خیر';
        $carryDaysText = $quote->carryRemainingDays ? 'بله' : 'خیر';
        $summary = sprintf(
            "تمدید سرویس #%d\nحجم تمدید: %d گیگ\nمدت تمدید: %s\nحفظ حجم باقیمانده: %s\nحفظ روزهای باقیمانده: %s\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: -\nمبلغ نهایی: %d تومان",
            $serviceId,
            $quote->trafficGb,
            $durationText,
            $carryTrafficText,
            $carryDaysText,
            $quote->baseAmount,
            $quote->globalDiscountAmount,
            $quote->amount
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            $summary,
            $this->keyboardFactory->renewalSummary($serviceId, $adminMode)
        );
    }

    public function confirmRenewal(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode): void
    {
        if (!$this->salesSettingsProvider->renewalsEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, 'renewal', SalesSettingsProvider::RENEWALS_ENABLED);

            return;
        }

        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_renew_confirm');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$adminMode && $service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_renew_confirm');

            return;
        }

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان تمدید این سرویس وجود ندارد.', 'invalid_service_renew_confirm_deleted');

            return;
        }

        $quote = $this->serviceOrderCheckout->renewalQuote($service);
        if (null === $quote) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان تمدید خودکار برای این سرویس وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.', 'renewal_confirm_package_missing');

            return;
        }

        $draft = $this->serviceOrderCheckout->startRenewalDraft($service, $quote, $adminMode, self::STEP_WAITING_DISCOUNT_DECISION);

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            "کد تخفیف دارید؟",
            $this->keyboardFactory->discountCodePrompt(
                (int) ($draft->getId() ?? 0),
                $adminMode ? 'admin_service_view:'.$serviceId : 'service_view:'.$serviceId
            )
        );
    }

    public function startAddTrafficOrder(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        if (!$this->salesSettingsProvider->addTrafficEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, 'add_traffic', SalesSettingsProvider::ADD_TRAFFIC_ENABLED);

            return;
        }

        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_add_traffic_order');
        if (!$service instanceof VpnService) {
            return;
        }

        if ($service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_add_traffic_order');

            return;
        }

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'deleted_service_add_traffic_order');

            return;
        }

        if (!$this->trafficAddonSettingsProvider->canPurchase()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'خرید حجم اضافه فعال نیست.', 'traffic_addon_disabled');

            return;
        }

        $plan = $this->serviceOrderCheckout->addTrafficPlan($service);
        if (!$plan instanceof Plan) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان خرید حجم اضافه برای این سرویس وجود ندارد.', 'traffic_addon_missing_plan');

            return;
        }

        $draft = $this->serviceOrderCheckout->startAddTrafficDraft($account->getUser(), $service, $plan, self::STEP_WAITING_ADD_TRAFFIC_AMOUNT);

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            sprintf(
                'چند گیگ حجم اضافه میخواهید؟ حداقل %d و حداکثر %d گیگ.',
                $this->trafficAddonSettingsProvider->minGb(),
                $this->trafficAddonSettingsProvider->maxGb()
            ),
            $this->keyboardFactory->customOrderInputMenu((int) ($draft->getId() ?? 0))
        );
    }

    public function handleAddTrafficDraftAmountInput(TelegramAccount $account, OrderDraft $draft, string $text, string $chatId): void
    {
        if (OrderDraftStatus::PENDING !== $draft->getStatus() || $draft->getUser()->getId() !== $account->getUser()->getId()) {
            return;
        }

        if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->telegramApiClient->sendMessage($chatId, 'این درخواست منقضی شده است. دوباره از صفحه سرویس شروع کنید.');

            return;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        if ('add_traffic' !== ($data['draftType'] ?? '')) {
            return;
        }

        if (!$this->trafficAddonSettingsProvider->canPurchase()) {
            $this->telegramApiClient->sendMessage($chatId, 'خرید حجم اضافه فعال نیست.');

            return;
        }

        $serviceId = (int) ($data['targetServiceId'] ?? 0);
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService || $service->getUser()->getId() !== $account->getUser()->getId() || VpnServiceStatus::DELETED === $service->getStatus()) {
            $draft->setStatus(OrderDraftStatus::CANCELLED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->telegramApiClient->sendMessage($chatId, 'این سرویس معتبر نیست.');

            return;
        }

        $trafficGb = $this->parsePositiveInt($text);
        if (!$this->serviceOrderCheckout->isValidAddTrafficAmount($trafficGb)) {
            $this->telegramApiClient->sendMessage($chatId, sprintf('عدد صحیح بین %d تا %d وارد کنید.', $this->trafficAddonSettingsProvider->minGb(), $this->trafficAddonSettingsProvider->maxGb()));

            return;
        }

        $price = $this->serviceOrderCheckout->addTrafficQuote((int) $trafficGb);
        if (null === $price) {
            $this->telegramApiClient->sendMessage($chatId, 'خرید حجم اضافه فعال نیست.');

            return;
        }

        $this->serviceOrderCheckout->setAddTrafficAmount($draft, (int) $trafficGb, $price);

        $summary = sprintf(
            "خلاصه خرید حجم اضافه\nشناسه سرویس: %d\nحجم کل فعلی: %s\nمصرف فعلی: %s\nحجم درخواستی: %d گیگ\nقیمت هر گیگ: %d تومان\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: -\nمبلغ نهایی: %d تومان",
            $serviceId,
            $this->formatTrafficLimit($service),
            $this->formatTrafficUsed($service),
            $trafficGb,
            $this->trafficAddonSettingsProvider->pricePerGb(),
            $price->baseAmount,
            $price->globalDiscountAmount,
            $price->finalAmount
        );

        $this->telegramApiClient->sendMessage(
            $chatId,
            $summary,
            $this->keyboardFactory->addTrafficSummary((int) ($draft->getId() ?? 0), $serviceId)
        );
    }

    public function confirmAddTrafficOrder(TelegramAccount $account, int $draftId, string $chatId, string $callbackId): void
    {
        if (!$this->salesSettingsProvider->addTrafficEnabled()) {
            $this->sendSalesDisabledMessage($account, $chatId, $callbackId, 'add_traffic', SalesSettingsProvider::ADD_TRAFFIC_ENABLED);

            return;
        }

        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (!$draft instanceof OrderDraft || $draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'درخواست نامعتبر است.', 'invalid_add_traffic_draft');

            return;
        }

        if ($draft->getStep() !== self::STEP_WAITING_ADD_TRAFFIC_AMOUNT) {
            $this->showPopupOrMessage($chatId, $callbackId, 'درخواست نامعتبر است.', 'invalid_add_traffic_step');

            return;
        }

        if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->showPopupOrMessage($chatId, $callbackId, 'این درخواست منقضی شده است.', 'expired_add_traffic_draft');

            return;
        }

        if (!$this->trafficAddonSettingsProvider->canPurchase()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'خرید حجم اضافه فعال نیست.', 'traffic_addon_disabled_confirm');

            return;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $serviceId = (int) ($data['targetServiceId'] ?? 0);
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService || $service->getUser()->getId() !== $account->getUser()->getId() || VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_add_traffic_service_confirm');

            return;
        }

        if (!$this->serviceOrderCheckout->addTrafficPlan($service) instanceof Plan) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان خرید حجم اضافه برای این سرویس وجود ندارد.', 'invalid_add_traffic_plan_confirm');

            return;
        }

        $trafficGb = (int) ($draft->getTrafficGb() ?? 0);
        if ($trafficGb <= 0 || !$this->serviceOrderCheckout->isValidAddTrafficAmount($trafficGb)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'حجم درخواستی معتبر نیست.', 'invalid_add_traffic_amount_confirm');

            return;
        }

        $price = $this->serviceOrderCheckout->addTrafficQuote($trafficGb);
        if (null === $price) {
            $this->showPopupOrMessage($chatId, $callbackId, 'خرید حجم اضافه فعال نیست.', 'add_traffic_price_zero_confirm');

            return;
        }

        $this->serviceOrderCheckout->confirmAddTraffic($draft, $service, $trafficGb, $price, self::STEP_WAITING_DISCOUNT_DECISION);

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            "کد تخفیف دارید؟",
            $this->keyboardFactory->discountCodePrompt(
                (int) ($draft->getId() ?? 0),
                'service_view:'.$serviceId
            )
        );
    }

    public function handleDiscountDecision(TelegramAccount $account, int $draftId, bool $enterCode, string $chatId, ?string $callbackId = null): void
    {
        $draft = $this->entityManager->getRepository(OrderDraft::class)->find($draftId);
        if (!$draft instanceof OrderDraft || $draft->getUser()->getId() !== $account->getUser()->getId() || OrderDraftStatus::PENDING !== $draft->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'پیش‌نویس سفارش نامعتبر است.', 'discount_draft_invalid');

            return;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if (!in_array($draftType, ['renewal', 'add_traffic'], true)) {
            return;
        }

        if ($enterCode) {
            $draft->setStep(self::STEP_WAITING_DISCOUNT_CODE)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage($chatId, 'کد تخفیف را ارسال کنید.');

            return;
        }

        $draft->setStep(self::STEP_WAITING_DISCOUNT_DECISION);
        $this->discountApplication->clearOnDraft($draft);

        $this->sendPaymentGatewaySelection($draft, $chatId, $callbackId, 'skipped');
    }

    public function handleDiscountCodeInput(TelegramAccount $account, OrderDraft $draft, string $codeInput, string $chatId): void
    {
        if (OrderDraftStatus::PENDING !== $draft->getStatus() || $draft->getUser()->getId() !== $account->getUser()->getId()) {
            return;
        }
        if (self::STEP_WAITING_DISCOUNT_CODE !== $draft->getStep()) {
            return;
        }

        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if (!in_array($draftType, ['renewal', 'add_traffic'], true)) {
            return;
        }

        $draft->setStep(self::STEP_WAITING_DISCOUNT_DECISION);
        $outcome = $this->discountApplication->applyToDraft($draft, $codeInput, (string) ($data['orderType'] ?? OrderType::NEW_SERVICE));
        $outcome->applied
            ? $this->sendPaymentGatewaySelection($draft, $chatId, null, 'applied')
            : $this->sendPaymentGatewaySelection($draft, $chatId, null, 'invalid', $outcome->reason);
    }

    public function handleSelectStorePaymentMethodForOrder(TelegramAccount $account, int $orderId, int $storePaymentMethodId, string $chatId, ?string $callbackId = null): bool
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || !in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)
            || OrderStatus::WAITING_PAYMENT !== $order->getStatus()
        ) {
            return false;
        }

        $method = $this->findStorePaymentMethodForOrder($order, $storePaymentMethodId);
        if (!$method instanceof StorePaymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'invalid_store_payment_method_order');

            return true;
        }

        $gateway = $method->getGateway();
        if (!$gateway->isActive()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'درگاه پرداخت نامعتبر است.', 'invalid_payment_gateway_order');

            return true;
        }

        $this->createOrReuseOrderPayment($order, $gateway, $method, $chatId, $callbackId);

        return true;
    }

    public function handleSelectPaymentGatewayForOrder(TelegramAccount $account, int $orderId, int $gatewayId, string $chatId, ?string $callbackId = null): bool
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || !in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)
            || OrderStatus::WAITING_PAYMENT !== $order->getStatus()
        ) {
            return false;
        }

        $method = $this->findStorePaymentMethodByGatewayForOrder($order, $gatewayId);
        if (!$method instanceof StorePaymentMethod) {
            $this->showPopupOrMessage($chatId, $callbackId, 'روش پرداخت نامعتبر است.', 'invalid_store_payment_method_legacy_gateway');

            return true;
        }

        return $this->handleSelectStorePaymentMethodForOrder($account, $orderId, (int) ($method->getId() ?? 0), $chatId, $callbackId);
    }

    public function showPaymentMethodsForOrder(TelegramAccount $account, int $orderId, string $chatId, ?string $callbackId = null): bool
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (
            !$order instanceof Order
            || $order->getUser()->getId() !== $account->getUser()->getId()
            || !in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)
            || OrderStatus::WAITING_PAYMENT !== $order->getStatus()
        ) {
            return false;
        }

        $methods = $this->findAvailableStorePaymentMethods($order);
        $offerWallet = $this->walletPayment->canPay($order);
        if ([] === $methods && !$offerWallet) {
            $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('payment.no_methods'), 'no_active_store_method_order_resume');

            return true;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->botTextResolver->message('payment.method_select'),
            $this->keyboardFactory->paymentGatewaySelectionMenu(
                (int) ($order->getId() ?? 0),
                $methods,
                'order_summary:'.((int) ($order->getId() ?? 0)),
                'order_cancel:'.((int) ($order->getId() ?? 0)),
                $offerWallet
            )
        );

        return true;
    }

    private function sendPaymentGatewaySelection(OrderDraft $draft, string $chatId, ?string $callbackId, ?string $discountResult = null, ?string $discountReason = null): void
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if ('renewal' === $draftType && !$this->salesSettingsProvider->renewalsEnabled()) {
            $this->sendSalesDisabledMessageForUserId((int) ($draft->getUser()->getId() ?? 0), $chatId, $callbackId, 'renewal', SalesSettingsProvider::RENEWALS_ENABLED);

            return;
        }
        if ('add_traffic' === $draftType && !$this->salesSettingsProvider->addTrafficEnabled()) {
            $this->sendSalesDisabledMessageForUserId((int) ($draft->getUser()->getId() ?? 0), $chatId, $callbackId, 'add_traffic', SalesSettingsProvider::ADD_TRAFFIC_ENABLED);

            return;
        }

        $order = $this->serviceOrderCheckout->createOrderFromDraft($draft);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_from_draft');

            return;
        }

        $methods = $this->findAvailableStorePaymentMethods($order);
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
            $this->showPopupOrMessage($chatId, $callbackId, $this->botTextResolver->message('payment.no_methods'), 'no_active_payment_gateway');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $message = $this->botTextResolver->message('payment.method_select');
        if (null !== $discountResult) {
            $message = $this->buildDiscountResultPaymentMethodMessage($order, $discountResult, $discountReason);
        }

        $this->telegramApiClient->sendMessage(
            $chatId,
            $message,
            $this->keyboardFactory->paymentGatewaySelectionMenu(
                (int) ($order->getId() ?? 0),
                $methods,
                'order_summary:'.((int) ($order->getId() ?? 0)),
                'order_cancel:'.((int) ($order->getId() ?? 0)),
                $offerWallet
            )
        );
    }

    /**
     * @return list<StorePaymentMethod>
     */
    private function findAvailableStorePaymentMethods(Order $order): array
    {
        return $this->paymentStarter->methodsFor($order);
    }

    private function findStorePaymentMethodForOrder(Order $order, int $storePaymentMethodId): ?StorePaymentMethod
    {
        return $this->paymentStarter->methodFor($order, $storePaymentMethodId);
    }

    private function findStorePaymentMethodByGatewayForOrder(Order $order, int $gatewayId): ?StorePaymentMethod
    {
        return $this->paymentStarter->methodForGateway($order, $gatewayId);
    }

    private function resolveCancelCallbackForDraft(OrderDraft $draft): string
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        $targetServiceId = (int) ($data['targetServiceId'] ?? 0);

        if ('renewal' === $draftType) {
            return true === ($data['adminMode'] ?? false)
                ? 'admin_service_view:'.$targetServiceId
                : 'service_view:'.$targetServiceId;
        }

        if ('add_traffic' === $draftType) {
            return 'service_view:'.$targetServiceId;
        }

        return 'main_menu';
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

        return [
            'discount' => [
                'code' => (string) ($priceSnapshot['discountCode'] ?? '-'),
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
                'accountName' => $this->resolveOrderAccountName($order),
                'volume' => $this->resolveOrderVolume($order, $metadata),
                'duration' => $this->resolveOrderDuration($order, $metadata),
                'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
            ],
            'plan' => [
                'title' => $order->requirePlan()->getTitle(),
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

    private function resolveOrderAccountName(Order $order): string
    {
        $targetService = $order->getTargetService();
        if ($targetService instanceof VpnService && '' !== trim((string) $targetService->getUsername())) {
            return (string) $targetService->getUsername();
        }

        return '-';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveOrderVolume(Order $order, array $metadata): string
    {
        if (OrderType::ADD_TRAFFIC === $order->getType()) {
            return ((string) ((int) ($metadata['trafficGb'] ?? 0))).' گیگ';
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

    private function createOrReuseOrderPayment(Order $order, PaymentGateway $gateway, StorePaymentMethod $storePaymentMethod, string $chatId, ?string $callbackId): void
    {
        $start = $this->paymentStarter->start($order, $storePaymentMethod);
        if (PaymentStart::KIND_WALLET_PAYMENT_PENDING === $start->kind) {
            $this->showPopupOrMessage($chatId, $callbackId, TelegramUpdateHandler::WALLET_PAYMENT_PENDING_TEXT, 'popup_wallet_payment_pending');

            return;
        }
        if (PaymentStart::KIND_WALLET_PAYMENT_HELD === $start->kind) {
            $this->showPopupOrMessage($chatId, $callbackId, TelegramUpdateHandler::WALLET_PAYMENT_HELD_TEXT, 'popup_wallet_payment_held');

            return;
        }
        $payment = $start->payment ?? throw new \LogicException('A started payment has a payment.');

        if (PaymentStart::KIND_ONLINE === $start->kind) {
            if (!$start->success || null === $payment->getPaymentUrl()) {
                $logKey = match ($gateway->getPluginCode()) {
                    null => 'zibal_request_failed',
                    default => 'plugin_request_failed',
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

            $trackingCode = (string) ($order->getTrackingCode() ?? '-');
            $message = null !== $payment->getPaymentUrl() && '' !== trim((string) $payment->getPaymentUrl())
                ? "پرداخت ارز دیجیتال آماده است.\nبرای پرداخت روی دکمه زیر بزنید.\n\nوضعیت: در انتظار پرداخت\nکد پیگیری سفارش: ".$trackingCode
                : sprintf(
                    "💎 پرداخت ارز دیجیتال\n\nمبلغ پرداختی: %s %s\n\nآدرس کیف پول:\n%s\n\nوضعیت: در انتظار پرداخت\nکد پیگیری سفارش: %s",
                    $payment->getCryptoPayAmount() ?? '-',
                    strtoupper($payment->getCryptoPayCurrency() ?? '-'),
                    $payment->getCryptoAddress() ?? '-',
                    $trackingCode
                );

            $this->acknowledgeCallback($callbackId);
            $this->telegramApiClient->sendMessage(
                $chatId,
                $message,
                $this->keyboardFactory->cryptoPaymentActionMenu(
                    (int) ($payment->getId() ?? 0),
                    (int) ($order->getId() ?? 0),
                    $payment->getPaymentUrl()
                )
            );

            return;
        }

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $card = $this->paymentStarter->manualCard($gateway);
        [$cardNumber, $cardHolder, $description] = [$card->cardNumber, $card->cardHolder, $card->instructions];
        $discountCode = trim((string) ($priceSnapshot['discountCode'] ?? ''));
        $message = $this->botTextResolver->message('payment.manual_card.instructions', [
            'order' => [
                'trackingCode' => (string) ($order->getTrackingCode() ?? '-'),
                'serviceId' => (int) ($metadata['targetServiceId'] ?? 0),
                'baseAmount' => (int) ($priceSnapshot['baseAmount'] ?? 0),
                'globalDiscount' => (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
                'discountCode' => '' === $discountCode ? 'ندارد' : $discountCode,
                'discountAmount' => (int) ($priceSnapshot['discountCodeAmount'] ?? 0),
                'finalAmount' => (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount()),
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
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) ($payment->getId() ?? 0), (int) ($order->getId() ?? 0)));
    }

    public function suspendService(int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_suspend');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$this->presentAdminOutcome($this->serviceAdministration->suspend($service), 'suspend', $chatId, $callbackId)) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'سرویس غیرفعال شد.', true);
        $this->debugLog(sprintf('admin_service_suspend service_id=%d', $serviceId));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
    }

    public function activateService(int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_activate');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$this->presentAdminOutcome($this->serviceAdministration->activate($service), 'activate', $chatId, $callbackId)) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'سرویس فعال شد.', true);
        $this->debugLog(sprintf('admin_service_activate service_id=%d', $serviceId));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
    }

    public function deleteService(int $serviceId, string $chatId, string $callbackId): void
    {
        $this->debugLog(sprintf('admin_service_delete_confirm service_id=%d', $serviceId));
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_delete');
        if (!$service instanceof VpnService) {
            $this->debugLog(sprintf('admin_service_delete_confirm_invalid service_id=%d', $serviceId));

            return;
        }

        $outcome = $this->serviceAdministration->delete($service);
        if (ServiceAdminOutcome::ALREADY_DELETED === $outcome) {
            $this->debugLog(sprintf('admin_service_delete_already_deleted service_id=%d', $serviceId));
        }
        if (!$this->presentAdminOutcome($outcome, 'delete', $chatId, $callbackId)) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'سرویس حذف شد.', true);
        $this->debugLog(sprintf('admin_service_delete service_id=%d', $serviceId));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
    }

    public function resetUsage(int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_reset_usage');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$this->presentAdminOutcome($this->serviceAdministration->resetUsage($service), 'reset_usage', $chatId, $callbackId)) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'مصرف سرویس ریست شد.', true);
        $this->debugLog(sprintf('admin_service_reset_usage service_id=%d', $serviceId));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
    }

    public function showExtendMenu(int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_extend_menu');
        if (!$service instanceof VpnService) {
            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, "انتخاب مدت تمدید برای سرویس #{$serviceId}:", $this->keyboardFactory->serviceExtendMenu($serviceId));
    }

    public function extendService(int $serviceId, int $days, string $chatId, string $callbackId): void
    {
        if (!in_array($days, [7, 30, 90], true)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات نامعتبر است.', 'invalid_extend_days');

            return;
        }

        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_extend');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$this->presentAdminOutcome($this->serviceAdministration->extend($service, $days), 'extend', $chatId, $callbackId)) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'تمدید انجام شد.', true);
        $this->debugLog(sprintf('admin_service_extend service_id=%d days=%d', $serviceId, $days));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
    }

    public function showAddTrafficMenu(int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_add_traffic_menu');
        if (!$service instanceof VpnService) {
            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, "انتخاب حجم اضافه برای سرویس #{$serviceId}:", $this->keyboardFactory->serviceAddTrafficMenu($serviceId));
    }

    public function addTraffic(int $serviceId, int $trafficGb, string $chatId, string $callbackId): void
    {
        if (!in_array($trafficGb, [10, 50, 100], true)) {
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات نامعتبر است.', 'invalid_add_traffic_gb');

            return;
        }

        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_add_traffic');
        if (!$service instanceof VpnService) {
            return;
        }

        if (!$this->presentAdminOutcome($this->serviceAdministration->addTraffic($service, $trafficGb), 'add_traffic', $chatId, $callbackId)) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'افزایش حجم انجام شد.', true);
        $this->debugLog(sprintf('admin_service_add_traffic service_id=%d traffic_gb=%d', $serviceId, $trafficGb));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
    }

    /**
     * The bot's answer to an outcome of ServiceAdministration other than DONE; true when it is DONE.
     */
    private function presentAdminOutcome(ServiceAdminOutcome $outcome, string $action, string $chatId, string $callbackId): bool
    {
        match ($outcome) {
            ServiceAdminOutcome::DONE => null,
            ServiceAdminOutcome::ALREADY_SUSPENDED => $this->showPopupOrMessage($chatId, $callbackId, 'سرویس قبلاً غیرفعال شده است.', 'already_suspended'),
            ServiceAdminOutcome::ALREADY_ACTIVE => $this->showPopupOrMessage($chatId, $callbackId, 'سرویس قبلاً فعال است.', 'already_active'),
            ServiceAdminOutcome::ALREADY_DELETED => $this->showPopupOrMessage($chatId, $callbackId, 'سرویس قبلاً حذف شده است.', 'already_deleted'),
            ServiceAdminOutcome::NOT_ALLOWED => $this->showPopupOrMessage($chatId, $callbackId, 'عملیات نامعتبر است.', 'invalid_'.$action.'_deleted'),
            ServiceAdminOutcome::PANEL_FAILED => $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_'.$action.'_failed'),
            ServiceAdminOutcome::LIMIT_OVERFLOW => $this->showPopupOrMessage($chatId, $callbackId, 'حداکثر حجم پشتیبانی‌شده عبور کرده است.', 'traffic_limit_overflow'),
            ServiceAdminOutcome::INVALID_AMOUNT => $this->showPopupOrMessage($chatId, $callbackId, 'عملیات نامعتبر است.', 'invalid_'.$action.'_amount'),
        };

        return $outcome->isDone();
    }

    public function showAdminUserDetail(int $userId, string $chatId, string $callbackId, ?int $backServiceId = null): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            $this->showPopupOrMessage($chatId, $callbackId, 'کاربر معتبر نیست.', 'invalid_admin_user_view');

            return;
        }

        $telegram = $user->getTelegramAccount();
        $orderCount = $this->entityManager->getRepository(Order::class)->count(['user' => $user]);
        $serviceCount = $this->entityManager->getRepository(VpnService::class)->count(['user' => $user]);

        $text = sprintf(
            "کاربر #%d\nUsername: @%s\nTelegram ID: %s\nتعداد سفارشها: %d\nتعداد سرویسها: %d\nآخرین فعالیت: %s",
            $userId,
            $telegram?->getUsername() ?: '-',
            $telegram?->getTelegramId() ?: '-',
            $orderCount,
            $serviceCount,
            $telegram?->getLastActivityAt()?->format('Y-m-d H:i:s') ?? '-'
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $text, $this->keyboardFactory->adminUserDetail($userId, $backServiceId));
    }

    public function showAdminUserServices(int $userId, string $chatId, string $callbackId, ?int $backServiceId = null): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            $this->showPopupOrMessage($chatId, $callbackId, 'کاربر معتبر نیست.', 'invalid_admin_user_services');

            return;
        }

        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->andWhere('service.user = :user')
            ->andWhere('service.status != :deletedStatus')
            ->setParameter('user', $user)
            ->setParameter('deletedStatus', VpnServiceStatus::DELETED)
            ->orderBy('service.id', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        if ([] === $services) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویسی برای این کاربر وجود ندارد.', 'empty_admin_user_services');

            return;
        }

        $lines = [sprintf("سرویسهای کاربر #%d:\n", $userId)];
        foreach ($services as $service) {
            $lines[] = sprintf(
                "#%d | %s | expires: %s",
                $service->getId(),
                $this->statusLabel($service->getStatus()),
                $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->adminUserDetail($userId, $backServiceId));
    }

    public function showAdminUserOrders(int $userId, string $chatId, string $callbackId, ?int $backServiceId = null): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            $this->showPopupOrMessage($chatId, $callbackId, 'کاربر معتبر نیست.', 'invalid_admin_user_orders');

            return;
        }

        $orders = $this->entityManager->getRepository(Order::class)->findBy(['user' => $user], ['id' => 'DESC'], 10);
        if ([] === $orders) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارشی برای این کاربر وجود ندارد.', 'empty_admin_user_orders');

            return;
        }

        $lines = [sprintf("سفارشهای کاربر #%d:\n", $userId)];
        foreach ($orders as $order) {
            $lines[] = sprintf(
                "#%d | plan: %s | amount: %d | status: %s",
                $order->getId(),
                $order->getPlan()?->getTitle() ?? '-',
                $order->getAmount(),
                $order->getStatus()
            );
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->adminUserDetail($userId, $backServiceId));
    }

    private function findServiceOrPopup(int $serviceId, string $chatId, ?string $callbackId, string $logKey): ?VpnService
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if ($service instanceof VpnService) {
            return $service;
        }

        $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', $logKey);

        return null;
    }

    private function formatServiceDetailForUser(VpnService $service): string
    {
        $inbound = $service->getInbound();
        $limit = $this->formatTrafficLimit($service);
        $used = $this->formatTrafficUsed($service);
        $remaining = $this->formatTrafficRemaining($service);
        $ipLimit = $service->getIpLimit();

        return sprintf(
            "سرویس #%d\nوضعیت: %s\nسرور/کشور: %s%s\nپروتکل/شبکه/امنیت: %s / %s / %s\nتاریخ انقضا: %s\nحجم کل: %s\nمصرف: %s\nباقیمانده: %s\nآخرین بروزرسانی مصرف: %s\nمحدودیت IP: %s\nلینک اشتراک: %s",
            $service->getId(),
            $this->statusLabel($service->getStatus()),
            sprintf('%s / %s', $inbound?->getHost() ?? '-', $inbound?->getCountry() ?? '-'),
            $this->serverCountLines($service),
            $inbound?->getProtocol() ?? '-',
            $inbound?->getNetwork() ?? '-',
            $inbound?->getSecurity() ?? '-',
            $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود',
            $limit,
            $used,
            $remaining,
            $service->getLastUsageSyncedAt()?->format('Y-m-d H:i:s') ?? '-',
            null === $ipLimit ? 'پیشفرض/نامحدود' : (string) $ipLimit,
            $this->accessGuard->subscriptionUrl($service) ?? ('' !== trim((string) $service->getSubscriptionUrl()) ? CustomerAccessGuard::SUPPORT_NOTICE : '-')
        );
    }

    /**
     * A plan with several inbounds (switch on): how many servers the service has, and a neutral note while some are
     * still being connected. Never which ones or where. Empty for every other service, so its text stays as it was.
     */
    private function serverCountLines(VpnService $service): string
    {
        $servers = $this->inboundCounts->servers($service);
        if (null === $servers) {
            return '';
        }
        $lines = sprintf("\nتعداد سرورها: %d", $servers['total']);
        if ($servers['notConnected'] > 0) {
            $lines .= sprintf("\n%d سرور هنوز در حال اتصال است و خودکار اضافه می‌شود.", $servers['notConnected']);
        }

        return $lines;
    }

    private function sendUserServiceDetail(string $chatId, VpnService $service, string $sourceFlow): void
    {
        if (!$this->ensureFreshConfigBeforeDelivery($chatId, $service, $sourceFlow)) {
            return;
        }

        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->formatServiceDetailForUser($service),
            $this->keyboardFactory->userServiceDetail(
                (int) $service->getId(),
                VpnServiceStatus::DELETED !== $service->getStatus() && $this->salesSettingsProvider->renewalsEnabled(),
                VpnServiceStatus::DELETED !== $service->getStatus() && $this->salesSettingsProvider->addTrafficEnabled(),
                // A deleted service has no page.
                VpnServiceStatus::DELETED !== $service->getStatus() ? $this->subscriptionPageUrl->forTelegram($service) : null,
                // Claim links: the owner who got it by a link may give it new connection links (D4).
                $this->serviceOwnership->canResetLinks($service->getUser(), $service),
            )
        );

        $configLinkSet = $this->collectConfigLinks($service, $sourceFlow);
        if ([] === $configLinkSet->finalLinks) {
            return;
        }

        $this->sendAccessMessages($chatId, $service, '✅ سرویس شما آماده شد', $sourceFlow);
        $this->debugLog(sprintf(
            'service_detail_configs service_id=%d source_flow=%s raw_link_count=%d formatted_link_count=%d final_sent_count=%d dropped_duplicate_count=%d',
            $service->getId() ?? 0,
            $sourceFlow,
            $configLinkSet->rawCount,
            $configLinkSet->formattedCount,
            count($configLinkSet->finalLinks),
            $configLinkSet->droppedDuplicateCount
        ));
    }

    private function sendAccessMessages(string $chatId, VpnService $service, string $headline, string $sourceFlow): void
    {
        foreach ($this->vpnAccessMessageFormatter->formatServiceAccessMessages($service, $headline, $sourceFlow) as $message) {
            $this->telegramApiClient->sendHtmlMessage($chatId, $message);
        }
    }

    private function formatServiceDetailForAdmin(VpnService $service): string
    {
        $finalLinks = $this->collectConfigLinks($service, 'telegram_admin_service_detail')->finalLinks;

        return sprintf(
            "سرویس #%d\nکاربر: %s\nوضعیت: %s\nانقضا: %s\nحجم کل: %s\nمصرف: %s\nباقیمانده: %s\nآخرین بروزرسانی مصرف: %s\nآخرین بررسی وضعیت: %s\nاشتراک: %s\nپیشنمایش کانفیگ: %s\nایجاد: %s",
            $service->getId(),
            $this->formatTelegramIdentity($service->getUser()->getTelegramAccount()),
            $this->statusLabel($service->getStatus()),
            $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود',
            $this->formatTrafficLimit($service),
            $this->formatTrafficUsed($service),
            $this->formatTrafficRemaining($service),
            $service->getLastUsageSyncedAt()?->format('Y-m-d H:i:s') ?? '-',
            $service->getLastStatusCheckedAt()?->format('Y-m-d H:i:s') ?? '-',
            $this->preview($service->getSubscriptionUrl(), 80),
            $this->preview([] === $finalLinks ? $service->getConfigText() : implode("\n", $finalLinks), 120),
            $service->getCreatedAt()->format('Y-m-d H:i:s')
        );
    }

    private function preview(?string $value, int $limit): string
    {
        $trimmed = trim((string) $value);
        if ('' === $trimmed) {
            return '-';
        }

        if (mb_strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $limit).'...';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            VpnServiceStatus::ACTIVE => '🟢 فعال',
            VpnServiceStatus::SUSPENDED => '⏸ غیرفعال',
            VpnServiceStatus::EXPIRED => '🔴 منقضیشده',
            VpnServiceStatus::DELETED => '🗑 حذفشده',
            default => '❓ '.$status,
        };
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

    private function sendSalesDisabledMessage(TelegramAccount $account, string $chatId, ?string $callbackId, string $action, string $settingKey): void
    {
        $this->sendSalesDisabledMessageForUserId((int) ($account->getUser()->getId() ?? 0), $chatId, $callbackId, $action, $settingKey);
    }

    private function sendSalesDisabledMessageForUserId(int $userId, string $chatId, ?string $callbackId, string $action, string $settingKey): void
    {
        $this->debugLog(sprintf(
            'sales_blocked user_id=%d action=%s setting_key=%s result=blocked',
            $userId,
            $action,
            $settingKey
        ));
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->salesDisabledMessage());
    }

    private function salesDisabledMessage(): string
    {
        return $this->botTextResolver->message('sales.disabled', [
            'sales' => [
                'disabledMessage' => $this->salesSettingsProvider->disabledMessage(),
            ],
        ]);
    }

    private function acknowledgeCallback(?string $callbackId): void
    {
        if (null === $callbackId || '' === $callbackId) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId);
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
        error_log('[ServiceManagementService] '.$message);
    }

    private function parsePositiveInt(string $text): ?int
    {
        return \App\Shop\Application\Checkout\PlanOptions::parsePositiveInt($text);
    }

    private function formatTrafficUsed(VpnService $service): string
    {
        if (null !== $service->getTrafficUsedBytes()) {
            return sprintf('%.2f GB', $service->getTrafficUsedBytes() / self::BYTES_PER_GB);
        }

        if (null !== $service->getTrafficUsedGb()) {
            return sprintf('%d GB', $service->getTrafficUsedGb());
        }

        return '-';
    }

    private function formatTrafficLimit(VpnService $service): string
    {
        if (null !== $service->getTrafficLimitBytes()) {
            return sprintf('%.2f GB', $service->getTrafficLimitBytes() / self::BYTES_PER_GB);
        }

        if (null !== $service->getTrafficLimitGb()) {
            return sprintf('%d GB', $service->getTrafficLimitGb());
        }

        return 'نامحدود';
    }

    private function formatTrafficRemaining(VpnService $service): string
    {
        if (null !== $service->getTrafficLimitBytes()) {
            $usedBytes = $service->getTrafficUsedBytes() ?? 0;
            $remainingBytes = max($service->getTrafficLimitBytes() - $usedBytes, 0);

            return sprintf('%.2f GB', $remainingBytes / self::BYTES_PER_GB);
        }

        if (null !== $service->getTrafficLimitGb()) {
            $remainingGb = max($service->getTrafficLimitGb() - ($service->getTrafficUsedGb() ?? 0), 0);

            return sprintf('%d GB', $remainingGb);
        }

        return 'نامحدود';
    }

    /**
     * @return list<string>
     */
    private function collectConfigLinks(VpnService $service, string $sourceFlow): VpnConfigLinkSetResult
    {
        return $this->finalConfigLinkProvider->getFinalLinkSetForService($service, $sourceFlow);
    }

    private function ensureFreshConfigBeforeDelivery(string $chatId, VpnService $service, string $sourceFlow): bool
    {
        $outcome = $this->deliveryRefresher->refreshBeforeDelivery($service, $sourceFlow);
        if ($outcome->succeeded) {
            $this->entityManager->flush();
            $this->debugLog(sprintf(
                'user_config_refresh_success service_id=%d source_flow="%s" refreshed_link_count=%d',
                $service->getId() ?? 0,
                $sourceFlow,
                $outcome->refreshedLinkCount
            ));

            return true;
        }

        if ($outcome->skipped) {
            return true;
        }

        $this->debugLog(sprintf(
            'user_config_refresh_failed_fallback_to_stored service_id=%d source_flow="%s" fallback_to_stored=%s reason="%s"',
            $service->getId() ?? 0,
            $sourceFlow,
            $outcome->fallbackToStored ? 'yes' : 'no',
            (string) ($outcome->reason ?? 'unknown')
        ));

        if ($outcome->fallbackToStored) {
            $this->telegramApiClient->sendMessage($chatId, 'دریافت آنلاین کانفیگ از پنل انجام نشد؛ آخرین کانفیگ ذخیره‌شده ارسال می‌شود.');

            return true;
        }

        $this->telegramApiClient->sendMessage($chatId, 'دریافت کانفیگ از پنل با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.');

        return false;
    }

    /**
     * @param list<string> $configLinks
     */
    private function formatConfigLinksMessage(array $configLinks, string $title = 'کانفیگ‌های شما:'): string
    {
        $parts = [htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
        foreach ($configLinks as $index => $link) {
            $parts[] = sprintf(
                "%d.\n<code>%s</code>",
                $index + 1,
                htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return implode("\n\n", $parts);
    }
}
