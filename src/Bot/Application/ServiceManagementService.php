<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\RenewalPriceResult;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class ServiceManagementService
{
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly ServiceUsageSyncService $serviceUsageSyncService,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
        private readonly PlanPricingService $planPricingService,
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
            $this->keyboardFactory->userServiceDetail((int) $service->getId(), VpnServiceStatus::DELETED !== $service->getStatus())
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
            $this->keyboardFactory->userServiceDetail((int) $service->getId(), VpnServiceStatus::DELETED !== $service->getStatus())
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
            "تمدید سرویس #%d\nحجم تمدید: %d گیگ\nمدت تمدید: %s\nحفظ حجم باقیمانده: %s\nحفظ روزهای باقیمانده: %s\nمبلغ پایه: %d تومان\nتخفیف: %d%%\nمبلغ تخفیف: %d تومان\nمبلغ نهایی: %d تومان",
            $serviceId,
            $package['trafficGb'],
            $durationText,
            $carryTrafficText,
            $carryDaysText,
            $package['baseAmount'],
            $package['discountPercent'],
            $package['discountAmount'],
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
        $metadata = [
            'renewal' => true,
            'targetServiceId' => $service->getId(),
            'trafficGb' => $package['trafficGb'],
            'durationDays' => $package['durationDays'],
            'unlimitedDuration' => $package['unlimitedDuration'],
            'priceSnapshot' => [
                'baseAmount' => $package['baseAmount'],
                'discountPercent' => $package['discountPercent'],
                'discountAmount' => $package['discountAmount'],
                'finalAmount' => $package['amount'],
                'planPriceSource' => 'current_plan',
            ],
            'renewalPolicy' => [
                'carryRemainingTraffic' => $package['carryRemainingTraffic'],
                'carryRemainingDays' => $package['carryRemainingDays'],
            ],
        ];

        $order = (new Order())
            ->setUser($service->getUser())
            ->setPlan($plan)
            ->setTargetService($service)
            ->setType(OrderType::RENEWAL)
            ->setAmount($package['amount'])
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);

        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod('manual_card')
            ->setAmount($package['amount'])
            ->setStatus(PaymentStatus::PENDING);

        $this->entityManager->persist($order);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $cardNumber = $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber);
        $cardHolder = $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder);
        $description = $this->settingValueProvider->get('payment.description', $this->paymentDescription);
        $durationText = $package['unlimitedDuration'] ? 'نامحدود' : ((string) $package['durationDays'].' روز');

        $message = sprintf(
            "تمدید سرویس #%d\nحجم تمدید: %d گیگ\nمدت تمدید: %s\nحفظ حجم باقیمانده: %s\nحفظ روزهای باقیمانده: %s\nمبلغ پایه: %d تومان\nتخفیف: %d%%\nمبلغ تخفیف: %d تومان\nمبلغ نهایی: %d تومان\nشماره کارت: %s\nبه نام: %s\n%s\n\nبرای ارسال رسید روی «✅ تایید و ارسال رسید» بزنید.",
            $serviceId,
            $package['trafficGb'],
            $durationText,
            $package['carryRemainingTraffic'] ? 'بله' : 'خیر',
            $package['carryRemainingDays'] ? 'بله' : 'خیر',
            $package['baseAmount'],
            $package['discountPercent'],
            $package['discountAmount'],
            $package['amount'],
            $cardNumber ?: '-',
            $cardHolder ?: '-',
            $description ? 'توضیحات: '.$description : ''
        );

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, trim($message), $this->keyboardFactory->paymentActionMenu((int) $payment->getId()));
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
     * @return array{plan: Plan, amount: int, trafficGb: int, durationDays: int, unlimitedDuration: bool, baseAmount: int, discountPercent: int, discountAmount: int, carryRemainingTraffic: bool, carryRemainingDays: bool}|null
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
            'discountPercent' => $priceResult->discountPercent,
            'discountAmount' => $priceResult->discountAmount,
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
