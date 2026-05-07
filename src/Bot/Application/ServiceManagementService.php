<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;

class ServiceManagementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly VpnPanelDriverRegistry $driverRegistry,
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
        $this->telegramApiClient->sendMessage($chatId, $this->formatServiceDetailForUser($service), $this->keyboardFactory->userServiceDetail((int) $service->getId()));
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
            $this->showPopupOrMessage($chatId, $callbackId, 'لینک اشتراک موجود نیست.', 'missing_subscription_url');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, "لینک اشتراک سرویس #{$serviceId}:\n{$subscriptionUrl}");
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

        $configText = trim((string) $service->getConfigText());
        if ('' === $configText) {
            $this->showPopupOrMessage($chatId, $callbackId, 'کانفیگ موجود نیست.', 'missing_config');

            return;
        }

        $this->acknowledgeCallback($callbackId);
        $message = sprintf(
            "خلاصه سرویس #%d\nوضعیت: %s\nانقضا: %s\nاشتراک: %s\n\nکانفیگ:\n%s",
            $serviceId,
            $this->statusLabel($service->getStatus()),
            $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? '-',
            $service->getSubscriptionUrl() ?: '-',
            $configText
        );

        $this->telegramApiClient->sendMessage($chatId, $message);
        $this->debugLog(sprintf('service_resend_config service_id=%d actor_user_id=%d admin_mode=%s', $serviceId, $account->getUser()->getId(), $adminMode ? 'true' : 'false'));
    }

    public function refreshUserService(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->showPopupOrMessage($chatId, $callbackId, 'سرویس معتبر نیست.', 'invalid_service_refresh_user');

            return;
        }

        if ($service->getUser()->getId() !== $account->getUser()->getId()) {
            $this->showPopupOrMessage($chatId, $callbackId, 'دسترسی غیرمجاز.', 'unauthorized_service_refresh_user');

            return;
        }

        if ($this->shouldSyncWithPanel($service)) {
            try {
                $this->syncServiceUsageFromPanel($service);
            } catch (\Throwable $e) {
                $this->debugLog(sprintf('service_sync_failure action=refresh service_id=%d message="%s"', $serviceId, $e->getMessage()));
                $this->showPopupOrMessage($chatId, $callbackId, 'عملیات روی پنل انجام نشد. لاگ را بررسی کنید.', 'panel_refresh_failed');

                return;
            }
        }

        $this->entityManager->refresh($service);
        $this->acknowledgeCallback($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->formatServiceDetailForUser($service), $this->keyboardFactory->userServiceDetail((int) $service->getId()));
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
        $service
            ->setTrafficLimitGb($currentLimit + $trafficGb)
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
        $limit = $service->getTrafficLimitGb();
        $used = $service->getTrafficUsedGb() ?? 0;
        $remaining = null === $limit ? 'نامحدود' : max($limit - $used, 0).' GB';

        return sprintf(
            "سرویس #%d\nوضعیت: %s\nتاریخ انقضا: %s\nحجم کل: %s\nمصرف: %s\nباقیمانده: %s\nلینک اشتراک: %s\nپیشنمایش کانفیگ: %s",
            $service->getId(),
            $this->statusLabel($service->getStatus()),
            $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? '-',
            null === $limit ? 'نامحدود' : $limit.' GB',
            $used.' GB',
            $remaining,
            $service->getSubscriptionUrl() ?: '-',
            $this->preview($service->getConfigText(), 120)
        );
    }

    private function formatServiceDetailForAdmin(VpnService $service): string
    {
        $limit = $service->getTrafficLimitGb();
        $used = $service->getTrafficUsedGb() ?? 0;

        return sprintf(
            "سرویس #%d\nکاربر: %s\nوضعیت: %s\nانقضا: %s\nترافیک: %s / %s GB\nاشتراک: %s\nپیشنمایش کانفیگ: %s\nایجاد: %s",
            $service->getId(),
            $this->formatTelegramIdentity($service->getUser()->getTelegramAccount()),
            $this->statusLabel($service->getStatus()),
            $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? '-',
            $used,
            null === $limit ? '∞' : (string) $limit,
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
            VpnServiceStatus::ACTIVE => '🟢 active',
            VpnServiceStatus::SUSPENDED => '⏸ suspended',
            VpnServiceStatus::EXPIRED => '🔴 expired',
            VpnServiceStatus::DELETED => '🗑 deleted',
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

    private function syncServiceUsageFromPanel(VpnService $service): void
    {
        $driver = $this->driverRegistry->resolve($service->getPanel());
        $usage = $driver->getUsage((string) $service->getRemoteId(), $service->getPanel());

        $service
            ->setTrafficUsedGb($usage->trafficUsedGb ?? $service->getTrafficUsedGb())
            ->setTrafficLimitGb($usage->trafficLimitGb ?? $service->getTrafficLimitGb())
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private function shouldSyncWithPanel(VpnService $service): bool
    {
        $panel = $service->getPanel();

        return null !== $panel && 'sanaei_3xui' === $panel->getType();
    }
}
