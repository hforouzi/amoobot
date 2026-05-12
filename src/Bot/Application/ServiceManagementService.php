<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
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
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\RenewalPriceResult;
use App\Shop\Application\TrafficAddonPricingService;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class ServiceManagementService
{
    private const BYTES_PER_GB = 1073741824;
    public const STEP_WAITING_ADD_TRAFFIC_AMOUNT = 'waiting_add_traffic_amount';
    public const STEP_WAITING_DISCOUNT_DECISION = 'waiting_discount_decision';
    public const STEP_WAITING_DISCOUNT_CODE = 'waiting_discount_code';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly ServiceUsageSyncService $serviceUsageSyncService,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly PlanPricingService $planPricingService,
        private readonly TrafficAddonPricingService $trafficAddonPricingService,
        private readonly DiscountCodeService $discountCodeService,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly StorePaymentMethodResolver $storePaymentMethodResolver,
        private readonly string $paymentCardNumber = '',
        private readonly string $paymentCardHolder = '',
        private readonly ?string $paymentDescription = null,
    ) {
    }

    public function handleMyServices(TelegramAccount $account, string $chatId, ?string $callbackId = null): void
    {
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->andWhere('service.user = :user')
            ->andWhere('service.status IN (:statuses)')
            ->setParameter('user', $account->getUser())
            ->setParameter('statuses', [
                VpnServiceStatus::ACTIVE,
                VpnServiceStatus::SUSPENDED,
                VpnServiceStatus::EXPIRED,
            ])
            ->orderBy('service.id', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

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
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->formatServiceDetailForUser($service),
            $this->keyboardFactory->userServiceDetail(
                (int) $service->getId(),
                VpnServiceStatus::DELETED !== $service->getStatus(),
                VpnServiceStatus::DELETED !== $service->getStatus()
            )
        );
    }

    public function showAdminServiceDetail(int $serviceId, string $chatId, ?string $callbackId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_view_admin');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->formatServiceDetailForAdmin($service),
            $this->keyboardFactory->adminServiceDetail((int) $service->getId(), (int) $service->getUser()->getId())
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

        $subscriptionUrl = trim((string) $service->getSubscriptionUrl());
        if ('' === $subscriptionUrl) {
            $this->showPopupOrMessage($chatId, $callbackId, 'لینک اشتراک برای این سرویس موجود نیست.', 'missing_subscription_url');
            $configLinks = $this->collectConfigLinks($service);
            if ([] !== $configLinks) {
                $this->telegramApiClient->sendMessage(
                    $chatId,
                    "لینک اشتراک موجود نیست، ولی کانفیگ‌ها در دسترس است:\n".implode("\n", $configLinks)
                );
            }

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, "🔗 لینک اشتراک شما:\n{$subscriptionUrl}");
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

        $configLinks = $service->getConfigLinks();
        if (!is_array($configLinks) || [] === $configLinks) {
            $this->showPopupOrMessage($chatId, $callbackId, 'اطلاعات اتصال کامل نیست. لطفاً با پشتیبانی تماس بگیرید.', 'missing_config');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, "کانفیگهای سرویس #{$serviceId}:\n".implode("\n", $configLinks));
        $this->debugLog(sprintf('service_resend_config service_id=%d actor_user_id=%d admin_mode=%s', $serviceId, $account->getUser()->getId(), $adminMode ? 'true' : 'false'));
    }

    public function sendSubscriptionQr(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService || $service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_subscription_qr');

            return;
        }

        $subscriptionUrl = trim((string) ($service->getSubscriptionUrl() ?? ''));
        if ('' === $subscriptionUrl) {
            $this->showPopupOrMessage($chatId, $callbackId, 'لینک اشتراک برای ساخت QR موجود نیست.', 'missing_subscription_qr');

            return;
        }

        $qrPath = $this->createSubscriptionQrTempFile($subscriptionUrl, (int) ($service->getId() ?? 0));
        if (null === $qrPath) {
            $this->showPopupOrMessage($chatId, $callbackId, 'ساخت QR انجام نشد. لطفاً دوباره تلاش کنید.', 'subscription_qr_create_failed');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendPhotoFile($chatId, $qrPath, 'QR لینک اشتراک سرویس شما');
    }

    public function sendConfigLinks(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->resendConfig($account, $serviceId, $chatId, $callbackId, false);
    }

    public function refreshUserService(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->syncServiceUsage($account, $serviceId, $chatId, $callbackId, false);
    }

    public function syncServiceUsage(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode): void
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
            $this->telegramApiClient->sendMessage(
                $chatId,
                $this->formatServiceDetailForAdmin($service),
                $this->keyboardFactory->adminServiceDetail((int) $service->getId(), (int) $service->getUser()->getId())
            );

            return;
        }

        $this->telegramApiClient->sendMessage(
            $chatId,
            $this->formatServiceDetailForUser($service),
            $this->keyboardFactory->userServiceDetail(
                (int) $service->getId(),
                VpnServiceStatus::DELETED !== $service->getStatus(),
                VpnServiceStatus::DELETED !== $service->getStatus()
            )
        );
    }

    public function showRenewalSummary(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode): void
    {
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

        $package = $this->resolveRenewalPackage($service);
        if (null === $package) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان تمدید خودکار برای این سرویس وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.', 'renewal_package_missing');

            return;
        }

        $durationText = $package['unlimitedDuration'] ? 'نامحدود' : ((string) $package['durationDays'].' روز');
        $carryTrafficText = $package['carryRemainingTraffic'] ? 'بله' : 'خیر';
        $carryDaysText = $package['carryRemainingDays'] ? 'بله' : 'خیر';
        $summary = sprintf(
            "تمدید سرویس #%d\nحجم تمدید: %d گیگ\nمدت تمدید: %s\nحفظ حجم باقیمانده: %s\nحفظ روزهای باقیمانده: %s\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: -\nمبلغ نهایی: %d تومان",
            $serviceId,
            $package['trafficGb'],
            $durationText,
            $carryTrafficText,
            $carryDaysText,
            $package['baseAmount'],
            $package['globalDiscountAmount'],
            $package['amount']
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

        $package = $this->resolveRenewalPackage($service);
        if (null === $package) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان تمدید خودکار برای این سرویس وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.', 'renewal_confirm_package_missing');

            return;
        }

        /** @var Plan $plan */
        $plan = $package['plan'];
        $draft = (new OrderDraft())
            ->setUser($service->getUser())
            ->setPlan($plan)
            ->setStatus(OrderDraftStatus::PENDING)
            ->setStep(self::STEP_WAITING_DISCOUNT_DECISION)
            ->setTrafficGb($package['trafficGb'])
            ->setDurationDays($package['durationDays'])
            ->setCalculatedAmount($package['amount'])
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($package['amount'])
            ->setPriceSnapshot([
                'baseAmount' => $package['baseAmount'],
                'globalDiscountPercent' => $package['globalDiscountPercent'],
                'globalDiscountAmount' => $package['globalDiscountAmount'],
                'afterGlobalDiscountAmount' => $package['amount'],
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $package['amount'],
                'planPriceSource' => 'current_plan',
            ])
            ->setData([
                'draftType' => 'renewal',
                'orderType' => OrderType::RENEWAL,
                'targetServiceId' => $service->getId(),
                'trafficGb' => $package['trafficGb'],
                'durationDays' => $package['durationDays'],
                'unlimitedDuration' => $package['unlimitedDuration'],
                'renewalPolicy' => [
                    'carryRemainingTraffic' => $package['carryRemainingTraffic'],
                    'carryRemainingDays' => $package['carryRemainingDays'],
                ],
                'adminMode' => $adminMode,
            ])
            ->setExpiresAt((new \DateTimeImmutable())->modify('+1 hour'))
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

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

        $sourceOrder = $service->getOrder();
        $plan = $sourceOrder?->getPlan();
        if (!$plan instanceof Plan) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان خرید حجم اضافه برای این سرویس وجود ندارد.', 'traffic_addon_missing_plan');

            return;
        }

        $draft = (new OrderDraft())
            ->setUser($account->getUser())
            ->setPlan($plan)
            ->setStatus(OrderDraftStatus::PENDING)
            ->setStep(self::STEP_WAITING_ADD_TRAFFIC_AMOUNT)
            ->setTrafficGb(null)
            ->setCalculatedAmount(null)
            ->setData([
                'draftType' => 'add_traffic',
                'targetServiceId' => $serviceId,
            ])
            ->setExpiresAt((new \DateTimeImmutable())->modify('+1 hour'))
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

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
        $minGb = $this->trafficAddonSettingsProvider->minGb();
        $maxGb = $this->trafficAddonSettingsProvider->maxGb();
        if (null === $trafficGb || $trafficGb < $minGb || $trafficGb > $maxGb) {
            $this->telegramApiClient->sendMessage($chatId, sprintf('عدد صحیح بین %d تا %d وارد کنید.', $minGb, $maxGb));

            return;
        }

        $price = $this->trafficAddonPricingService->calculate($trafficGb);
        if ($price->finalAmount <= 0) {
            $this->telegramApiClient->sendMessage($chatId, 'خرید حجم اضافه فعال نیست.');

            return;
        }

        $priceSnapshot = [
            'baseAmount' => $price->baseAmount,
            'globalDiscountPercent' => $price->globalDiscountPercent,
            'globalDiscountAmount' => $price->globalDiscountAmount,
            'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
            'discountCode' => null,
            'discountCodeAmount' => 0,
            'finalAmount' => $price->finalAmount,
        ];

        $draft
            ->setTrafficGb($trafficGb)
            ->setCalculatedAmount($price->finalAmount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($price->finalAmount)
            ->setPriceSnapshot($priceSnapshot)
            ->setData(array_merge($data, ['priceSnapshot' => $priceSnapshot]))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        $sourceOrder = $service->getOrder();
        $plan = $sourceOrder?->getPlan();
        if (!$plan instanceof Plan) {
            $this->showPopupOrMessage($chatId, $callbackId, 'امکان خرید حجم اضافه برای این سرویس وجود ندارد.', 'invalid_add_traffic_plan_confirm');

            return;
        }

        $trafficGb = (int) ($draft->getTrafficGb() ?? 0);
        $minGb = $this->trafficAddonSettingsProvider->minGb();
        $maxGb = $this->trafficAddonSettingsProvider->maxGb();
        if ($trafficGb <= 0 || $trafficGb < $minGb || $trafficGb > $maxGb) {
            $this->showPopupOrMessage($chatId, $callbackId, 'حجم درخواستی معتبر نیست.', 'invalid_add_traffic_amount_confirm');

            return;
        }

        $price = $this->trafficAddonPricingService->calculate($trafficGb);
        if ($price->finalAmount <= 0) {
            $this->showPopupOrMessage($chatId, $callbackId, 'خرید حجم اضافه فعال نیست.', 'add_traffic_price_zero_confirm');

            return;
        }

        $draft
            ->setStep(self::STEP_WAITING_DISCOUNT_DECISION)
            ->setCalculatedAmount($price->finalAmount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($price->finalAmount)
            ->setPriceSnapshot([
                'baseAmount' => $price->baseAmount,
                'globalDiscountPercent' => $price->globalDiscountPercent,
                'globalDiscountAmount' => $price->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $price->finalAmount,
            ])
            ->setData(array_merge($data, [
                'orderType' => OrderType::ADD_TRAFFIC,
                'targetServiceId' => $service->getId(),
                'trafficGb' => $trafficGb,
            ]))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        $draft
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount((int) ($draft->getCalculatedAmount() ?? 0))
            ->setStep(self::STEP_WAITING_DISCOUNT_DECISION)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendPaymentGatewaySelection($draft, $chatId, $callbackId);
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

        $orderType = (string) ($data['orderType'] ?? OrderType::NEW_SERVICE);
        $amountBeforeCode = (int) ($draft->getCalculatedAmount() ?? 0);
        $result = $this->discountCodeService->validateCode($codeInput, $draft->getUser(), $orderType, $draft->getPlan(), $amountBeforeCode);
        if (!$result->valid || null === $result->discountCode) {
            $cancelCallback = 'renewal' === $draftType
                ? ((bool) ($data['adminMode'] ?? false) ? 'admin_service_view:'.((int) ($data['targetServiceId'] ?? 0)) : 'service_view:'.((int) ($data['targetServiceId'] ?? 0)))
                : 'service_view:'.((int) ($data['targetServiceId'] ?? 0));
            $this->telegramApiClient->sendMessage($chatId, $result->message, $this->keyboardFactory->discountCodePrompt((int) ($draft->getId() ?? 0), $cancelCallback));

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
            ->setStep(self::STEP_WAITING_DISCOUNT_DECISION)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendPaymentGatewaySelection($draft, $chatId, null);
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
        if (!$gateway->isActive() || !$gateway->isConfigured()) {
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

    private function sendPaymentGatewaySelection(OrderDraft $draft, string $chatId, ?string $callbackId): void
    {
        $order = $this->createOrderFromDraft($draft);
        if (!$order instanceof Order) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سفارش نامعتبر است.', 'invalid_order_from_draft');

            return;
        }

        $methods = $this->findAvailableStorePaymentMethods($order);
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
            $this->showPopupOrMessage($chatId, $callbackId, 'در حال حاضر درگاه پرداخت فعالی وجود ندارد.', 'no_active_payment_gateway');

            return;
        }

        $cancelCallback = $this->resolveCancelCallbackForDraft($draft);
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage(
            $chatId,
            'روش پرداخت را انتخاب کنید:',
            $this->keyboardFactory->paymentGatewaySelectionMenu((int) ($order->getId() ?? 0), $methods, $cancelCallback)
        );
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

    private function createOrderFromDraft(OrderDraft $draft): ?Order
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if (!in_array($draftType, ['renewal', 'add_traffic'], true)) {
            return null;
        }

        $targetServiceId = (int) ($data['targetServiceId'] ?? 0);
        $targetService = $targetServiceId > 0 ? $this->entityManager->getRepository(VpnService::class)->find($targetServiceId) : null;
        if (!$targetService instanceof VpnService) {
            return null;
        }

        $priceSnapshot = is_array($draft->getPriceSnapshot()) ? $draft->getPriceSnapshot() : [];
        $finalAmount = max(0, (int) ($draft->getFinalAmount() ?? $draft->getCalculatedAmount() ?? 0));
        $metadata = [
            'targetServiceId' => $targetService->getId(),
            'trafficGb' => (int) ($data['trafficGb'] ?? $draft->getTrafficGb() ?? 0),
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
        ];

        $orderType = 'renewal' === $draftType ? OrderType::RENEWAL : OrderType::ADD_TRAFFIC;
        if (OrderType::RENEWAL === $orderType) {
            $metadata['renewal'] = true;
            $metadata['durationDays'] = (int) ($data['durationDays'] ?? 0);
            $metadata['unlimitedDuration'] = (bool) ($data['unlimitedDuration'] ?? false);
            $metadata['renewalPolicy'] = is_array($data['renewalPolicy'] ?? null) ? $data['renewalPolicy'] : [];
        } else {
            $metadata['addTraffic'] = true;
        }

        $order = (new Order())
            ->setUser($draft->getUser())
            ->setPlan($draft->getPlan())
            ->setTargetService($targetService)
            ->setType($orderType)
            ->setAmount($finalAmount)
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);

        $draft->setStatus(OrderDraftStatus::CONFIRMED)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function createOrReuseOrderPayment(Order $order, PaymentGateway $gateway, StorePaymentMethod $storePaymentMethod, string $chatId, ?string $callbackId): void
    {
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
                ->setAmount($order->getAmount())
                ->setPayableAmount($order->getAmount())
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

        if (in_array($gateway->getType(), [PaymentGatewayType::ZIBAL, PaymentGatewayType::CUSTOM_API], true)) {
            if (!$requestResult->success || null === $payment->getPaymentUrl()) {
                $this->showPopupOrMessage($chatId, $callbackId, $requestResult->message ?: 'ایجاد لینک پرداخت آنلاین انجام نشد.', 'zibal_request_failed');

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

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $cardNumber = $gateway->getManualCardNumber() ?? $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
        $cardHolder = $gateway->getManualCardHolder() ?? $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
        $description = $gateway->getManualInstructions() ?? $this->settingValueProvider->get('payment.description', $this->paymentDescription);
        $message = sprintf(
            "شناسه سرویس: %d\nمبلغ پایه: %d تومان\nتخفیف سراسری: %d تومان\nکد تخفیف: %s (%d تومان)\nمبلغ نهایی: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            (int) ($metadata['targetServiceId'] ?? 0),
            (int) ($priceSnapshot['baseAmount'] ?? 0),
            (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
            (string) ($priceSnapshot['discountCode'] ?? '-'),
            (int) ($priceSnapshot['discountCodeAmount'] ?? 0),
            (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount()),
            $cardNumber ?: '-',
            $cardHolder ?: '-',
            $description ? 'توضیحات: '.$description : ''
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) ($payment->getId() ?? 0)));
    }

    public function suspendService(int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->findServiceOrPopup($serviceId, $chatId, $callbackId, 'invalid_service_suspend');
        if (!$service instanceof VpnService) {
            return;
        }

        if (VpnServiceStatus::SUSPENDED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس قبلاً غیرفعال شده است.', 'already_suspended');

            return;
        }

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات نامعتبر است.', 'invalid_suspend_deleted');

            return;
        }

        if ($this->shouldSyncWithPanel($service)) {
            try {
                $driver = $this->driverRegistry->resolve($service->getPanel());
                $driver->suspendService((string) $service->getRemoteId(), $service->getPanel());
            } catch (\Throwable $e) {
                $this->debugLog(sprintf('service_sync_failure action=suspend service_id=%d message="%s"', $serviceId, $e->getMessage()));
                $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_suspend_failed');

                return;
            }
        }

        $service
            ->setStatus(VpnServiceStatus::SUSPENDED)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        if (VpnServiceStatus::ACTIVE === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس قبلاً فعال است.', 'already_active');

            return;
        }

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'عملیات نامعتبر است.', 'invalid_activate_deleted');

            return;
        }

        if ($this->shouldSyncWithPanel($service)) {
            try {
                $driver = $this->driverRegistry->resolve($service->getPanel());
                $driver->renewService((string) $service->getRemoteId(), new RenewVpnServiceRequest(
                    durationDays: 0,
                    trafficLimitGb: $service->getTrafficLimitGb(),
                    serviceId: (int) ($service->getId() ?? 0),
                ), $service->getPanel());
            } catch (\Throwable $e) {
                $this->debugLog(sprintf('service_sync_failure action=activate service_id=%d message="%s"', $serviceId, $e->getMessage()));
                $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_activate_failed');

                return;
            }
        }

        $service
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس قبلاً حذف شده است.', 'already_deleted');
            $this->debugLog(sprintf('admin_service_delete_already_deleted service_id=%d', $serviceId));

            return;
        }

        if ($this->shouldSyncWithPanel($service)) {
            try {
                $driver = $this->driverRegistry->resolve($service->getPanel());
                $driver->deleteService((string) $service->getRemoteId(), $service->getPanel());
            } catch (\Throwable $e) {
                $this->debugLog(sprintf('service_sync_failure action=delete service_id=%d message="%s"', $serviceId, $e->getMessage()));
                $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_delete_failed');

                return;
            }
        }

        $service
            ->setStatus(VpnServiceStatus::DELETED)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        if ($this->shouldSyncWithPanel($service)) {
            try {
                $driver = $this->driverRegistry->resolve($service->getPanel());
                $driver->resetUsage((string) $service->getRemoteId(), $service->getPanel());
            } catch (\Throwable $e) {
                $this->debugLog(sprintf('service_sync_failure action=reset_usage service_id=%d message="%s"', $serviceId, $e->getMessage()));
                $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_reset_usage_failed');

                return;
            }
        }

        $service
            ->setTrafficUsedGb(0)
            ->setTrafficUsedBytes(0)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        $base = $service->getExpiresAt();
        $now = new \DateTimeImmutable();
        if (!$base instanceof \DateTimeImmutable || $base < $now) {
            $base = $now;
        }

        $service
            ->setExpiresAt($base->modify(sprintf('+%d days', $days)))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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

        $currentLimit = $service->getTrafficLimitGb() ?? 0;
        $newLimitGb = $currentLimit + $trafficGb;
        $maxSafeGbForBytes = intdiv(PHP_INT_MAX, self::BYTES_PER_GB);
        if ($newLimitGb > $maxSafeGbForBytes) {
            $this->showPopupOrMessage($chatId, $callbackId, 'حداکثر حجم پشتیبانی‌شده عبور کرده است.', 'traffic_limit_overflow');

            return;
        }
        $service
            ->setTrafficLimitGb($newLimitGb)
            ->setTrafficLimitBytes($newLimitGb * self::BYTES_PER_GB)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->telegramApiClient->answerCallbackQuery($callbackId, 'افزایش حجم انجام شد.', true);
        $this->debugLog(sprintf('admin_service_add_traffic service_id=%d traffic_gb=%d', $serviceId, $trafficGb));
        $this->showAdminServiceDetail($serviceId, $chatId, null);
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
                $order->getPlan()->getTitle(),
                $order->getAmount(),
                $order->getStatus()
            );
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->adminUserDetail($userId, $backServiceId));
    }

    /**
     * amount and afterGlobalDiscountAmount are intentionally the same at this stage (before discount code).
     *
     * @return array{plan: Plan, amount: int, trafficGb: int, durationDays: int, unlimitedDuration: bool, baseAmount: int, globalDiscountPercent: int, globalDiscountAmount: int, afterGlobalDiscountAmount: int, carryRemainingTraffic: bool, carryRemainingDays: bool}|null
     */
    private function resolveRenewalPackage(VpnService $service): ?array
    {
        $sourceOrder = $service->getOrder();
        if (!$sourceOrder instanceof Order) {
            return null;
        }

        $plan = $sourceOrder->getPlan();
        if (!$plan instanceof Plan) {
            return null;
        }

        $priceResult = $this->planPricingService->calculateRenewalAmount($service, $plan);
        if (!$priceResult instanceof RenewalPriceResult) {
            return null;
        }
        if ($priceResult->finalAmount <= 0) {
            return null;
        }
        $carryRemainingTraffic = $this->renewalSettingsProvider->carryRemainingTraffic();
        $carryRemainingDays = $this->renewalSettingsProvider->carryRemainingDays();

        return [
            'plan' => $plan,
            'amount' => $priceResult->finalAmount,
            'trafficGb' => $priceResult->trafficGb,
            'durationDays' => $priceResult->durationDays,
            'unlimitedDuration' => $priceResult->unlimitedDuration,
            'baseAmount' => $priceResult->baseAmount,
            'globalDiscountPercent' => $priceResult->globalDiscountPercent,
            'globalDiscountAmount' => $priceResult->globalDiscountAmount,
            'afterGlobalDiscountAmount' => $priceResult->afterGlobalDiscountAmount,
            'carryRemainingTraffic' => $carryRemainingTraffic,
            'carryRemainingDays' => $carryRemainingDays,
        ];
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
            "سرویس #%d\nوضعیت: %s\nسرور/کشور: %s\nپروتکل/شبکه/امنیت: %s / %s / %s\nتاریخ انقضا: %s\nحجم کل: %s\nمصرف: %s\nباقیمانده: %s\nآخرین بروزرسانی مصرف: %s\nمحدودیت IP: %s\nلینک اشتراک: %s",
            $service->getId(),
            $this->statusLabel($service->getStatus()),
            sprintf('%s / %s', $inbound?->getHost() ?? '-', $inbound?->getCountry() ?? '-'),
            $inbound?->getProtocol() ?? '-',
            $inbound?->getNetwork() ?? '-',
            $inbound?->getSecurity() ?? '-',
            $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود',
            $limit,
            $used,
            $remaining,
            $service->getLastUsageSyncedAt()?->format('Y-m-d H:i:s') ?? '-',
            null === $ipLimit ? 'پیشفرض/نامحدود' : (string) $ipLimit,
            $service->getSubscriptionUrl() ?: '-'
        );
    }

    private function formatServiceDetailForAdmin(VpnService $service): string
    {
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
            $this->preview($service->getConfigText(), 120),
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

    private function shouldSyncWithPanel(VpnService $service): bool
    {
        $panel = $service->getPanel();

        return null !== $panel && 'sanaei_3xui' === $panel->getType();
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
    private function collectConfigLinks(VpnService $service): array
    {
        $configLinks = $service->getConfigLinks();
        if (!is_array($configLinks)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $link): string => trim((string) $link),
            $configLinks
        ), static fn (string $link): bool => '' !== $link));
    }

    private function createSubscriptionQrTempFile(string $subscriptionUrl, int $serviceId): ?string
    {
        $tmpDir = '/tmp/amoobot-qr';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        if (!is_dir($tmpDir)) {
            return null;
        }

        $targetPath = sprintf('%s/service-subscription-%d-%d.png', $tmpDir, $serviceId, time());
        try {
            $writer = new PngWriter();
            $qrCode = QrCode::create($subscriptionUrl)
                ->setSize(420)
                ->setMargin(12);
            $writer->write($qrCode)->saveToFile($targetPath);
        } catch (\Throwable $e) {
            $this->debugLog(sprintf('subscription_qr_generation_failed service_id=%d message="%s"', $serviceId, $e->getMessage()));

            return null;
        }

        return is_file($targetPath) ? $targetPath : null;
    }
}
