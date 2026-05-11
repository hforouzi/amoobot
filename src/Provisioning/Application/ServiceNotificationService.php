<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\ServiceNotificationLog;
use App\Entity\TelegramAccount;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shared\Infrastructure\SettingValueProvider;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceNotificationService
{
    private const SETTING_EXPIRY_DAYS = 'service.notify.expiry_days';
    private const SETTING_TRAFFIC_THRESHOLDS = 'service.notify.traffic_thresholds';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly string $serviceNotifyExpiryDays = '3,1',
        private readonly string $serviceNotifyTrafficThresholds = '80,95,100',
    ) {
    }

    public function sendExpiryWarnings(): NotificationSummary
    {
        return $this->sendExpiryWarningsWithOptions();
    }

    public function sendTrafficWarnings(): NotificationSummary
    {
        return $this->sendTrafficWarningsWithOptions();
    }

    public function sendExpiredNotifications(): NotificationSummary
    {
        return $this->sendExpiredNotificationsWithOptions();
    }

    public function sendExpiryWarningsWithOptions(bool $dryRun = false, int $limit = 100): NotificationSummary
    {
        $services = $this->loadServices($limit);
        $thresholds = $this->resolveThresholds(self::SETTING_EXPIRY_DAYS, $this->serviceNotifyExpiryDays, [3, 1], true);
        $now = new \DateTimeImmutable();

        $checked = 0;
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($services as $service) {
            ++$checked;

            if (VpnServiceStatus::ACTIVE !== $service->getStatus()) {
                ++$skipped;
                continue;
            }

            $expiresAt = $service->getExpiresAt();
            if (!$expiresAt instanceof \DateTimeImmutable) {
                ++$skipped;
                continue;
            }

            if ($expiresAt <= $now) {
                ++$skipped;
                continue;
            }

            $daysLeft = (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
            $matched = false;
            foreach ($thresholds as $days) {
                if ($daysLeft > $days) {
                    continue;
                }

                $matched = true;
                $result = $this->dispatchNotification(
                    $service,
                    'expiry_warning',
                    sprintf('expiry_%d_days', $days),
                    sprintf(
                        "⏰ سرویس شما تا %d روز دیگر منقضی میشود.\nبرای جلوگیری از قطع شدن، سرویس خود را تمدید کنید.",
                        $days
                    ),
                    [
                        'thresholdDays' => $days,
                        'daysLeft' => $daysLeft,
                        'expiresAt' => $expiresAt->format('Y-m-d H:i:s'),
                    ],
                    $dryRun,
                    [
                        'inline_keyboard' => [
                            [[
                                'text' => '📦 مشاهده سرویس',
                                'callback_data' => 'service_view:'.((int) $service->getId()),
                            ]],
                        ],
                    ]
                );

                $this->applyResultCounters($result, $sent, $skipped, $failed);
            }

            if (!$matched) {
                ++$skipped;
            }
        }

        return new NotificationSummary($checked, $sent, $skipped, $failed);
    }

    public function sendTrafficWarningsWithOptions(bool $dryRun = false, int $limit = 100): NotificationSummary
    {
        $services = $this->loadServices($limit);
        $thresholds = $this->resolveThresholds(self::SETTING_TRAFFIC_THRESHOLDS, $this->serviceNotifyTrafficThresholds, [80, 95, 100], false);

        $checked = 0;
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($services as $service) {
            ++$checked;

            if (VpnServiceStatus::DELETED === $service->getStatus()) {
                ++$skipped;
                continue;
            }

            $limitGb = $service->getTrafficLimitGb();
            $usedGb = $service->getTrafficUsedGb();
            if (null === $limitGb || $limitGb <= 0 || null === $usedGb || $usedGb < 0) {
                ++$skipped;
                continue;
            }

            $usagePercent = ((float) $usedGb / (float) $limitGb) * 100;
            $matched = false;
            foreach ($thresholds as $threshold) {
                if ($usagePercent < $threshold) {
                    continue;
                }

                $matched = true;
                $type = $threshold >= 100 ? 'traffic_exhausted' : 'traffic_warning';
                $keyName = sprintf('traffic_%d', $threshold);

                $result = $this->dispatchNotification(
                    $service,
                    $type,
                    $keyName,
                    $this->buildTrafficMessage($threshold),
                    [
                        'thresholdPercent' => $threshold,
                        'usagePercent' => round($usagePercent, 2),
                        'trafficUsedGb' => $usedGb,
                        'trafficLimitGb' => $limitGb,
                    ],
                    $dryRun,
                    [
                        'inline_keyboard' => [[
                            [
                                'text' => '📦 مشاهده سرویس',
                                'callback_data' => 'service_view:'.((int) $service->getId()),
                            ],
                        ]],
                    ]
                );

                $this->applyResultCounters($result, $sent, $skipped, $failed);
            }

            if (!$matched) {
                ++$skipped;
            }
        }

        return new NotificationSummary($checked, $sent, $skipped, $failed);
    }

    public function sendExpiredNotificationsWithOptions(bool $dryRun = false, int $limit = 100): NotificationSummary
    {
        $services = $this->loadServices($limit);
        $now = new \DateTimeImmutable();

        $checked = 0;
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($services as $service) {
            ++$checked;

            if (VpnServiceStatus::DELETED === $service->getStatus()) {
                ++$skipped;
                continue;
            }

            $isExpiredStatus = VpnServiceStatus::EXPIRED === $service->getStatus();
            $isExpiredByTime = $service->getExpiresAt() instanceof \DateTimeImmutable && $service->getExpiresAt() < $now;
            if (!$isExpiredStatus && !$isExpiredByTime) {
                ++$skipped;
                continue;
            }

            $result = $this->dispatchNotification(
                $service,
                'expired',
                'expired',
                '🔴 سرویس شما منقضی شده است.',
                [
                    'status' => $service->getStatus(),
                    'expiresAt' => $service->getExpiresAt()?->format('Y-m-d H:i:s'),
                ],
                $dryRun,
                [
                    'inline_keyboard' => [[
                        [
                            'text' => '📦 مشاهده سرویس',
                            'callback_data' => 'service_view:'.((int) $service->getId()),
                        ],
                    ]],
                ]
            );

            $this->applyResultCounters($result, $sent, $skipped, $failed);
        }

        return new NotificationSummary($checked, $sent, $skipped, $failed);
    }

    /**
     * @return list<VpnService>
     */
    private function loadServices(int $limit): array
    {
        $query = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->orderBy('service.id', 'DESC');

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }

        /** @var list<VpnService> $services */
        $services = $query->getQuery()->getResult();

        return $services;
    }

    private function dispatchNotification(
        VpnService $service,
        string $type,
        string $keyName,
        string $text,
        array $payload,
        bool $dryRun,
        ?array $replyMarkup = null
    ): string {
        $existing = $this->entityManager->getRepository(ServiceNotificationLog::class)->findOneBy([
            'service' => $service,
            'type' => $type,
            'keyName' => $keyName,
        ]);

        if ($existing instanceof ServiceNotificationLog) {
            return 'skipped';
        }

        $account = $service->getUser()->getTelegramAccount();
        if (!$account instanceof TelegramAccount) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'sent';
        }

        try {
            $this->telegramApiClient->sendMessageStrict($account->getTelegramId(), $text, $replyMarkup);
        } catch (\Throwable $e) {
            $this->log(sprintf(
                'send_failed service_id=%d type="%s" key="%s" message="%s"',
                (int) ($service->getId() ?? 0),
                $type,
                $keyName,
                $this->sanitizeError($e->getMessage())
            ));

            return 'failed';
        }

        try {
            $log = (new ServiceNotificationLog())
                ->setService($service)
                ->setUser($service->getUser())
                ->setType($type)
                ->setKeyName($keyName)
                ->setSentAt(new \DateTimeImmutable())
                ->setPayload($payload);

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->log(sprintf(
                'save_failed service_id=%d type="%s" key="%s" message="%s"',
                (int) ($service->getId() ?? 0),
                $type,
                $keyName,
                $this->sanitizeError($e->getMessage())
            ));

            return 'failed';
        }

        return 'sent';
    }

    /**
     * @return list<int>
     */
    private function resolveThresholds(string $settingKey, string $fallback, array $default, bool $desc): array
    {
        $raw = $this->settingValueProvider->get($settingKey, $fallback);
        if (null === $raw || '' === trim($raw)) {
            return $default;
        }

        $parsed = [];
        foreach (explode(',', $raw) as $item) {
            $value = (int) trim($item);
            if ($value <= 0) {
                continue;
            }
            $parsed[] = $value;
        }

        if ([] === $parsed) {
            return $default;
        }

        $parsed = array_values(array_unique($parsed));
        if ($desc) {
            rsort($parsed);
        } else {
            sort($parsed);
        }

        return $parsed;
    }

    private function buildTrafficMessage(int $threshold): string
    {
        return match ($threshold) {
            80 => '⚠️ شما ۸۰٪ حجم سرویس خود را مصرف کردهاید.',
            95 => '⚠️ حجم سرویس شما تقریباً رو به پایان است.',
            100 => '🔴 حجم سرویس شما به پایان رسیده است.',
            default => sprintf('⚠️ مصرف حجم سرویس شما به %d%% رسیده است.', $threshold),
        };
    }

    private function applyResultCounters(string $result, int &$sent, int &$skipped, int &$failed): void
    {
        if ('sent' === $result) {
            ++$sent;
            return;
        }

        if ('failed' === $result) {
            ++$failed;
            return;
        }

        ++$skipped;
    }

    private function sanitizeError(string $message): string
    {
        $safe = trim($message);
        $safe = preg_replace('/https?:\/\/\S+/i', '[url-redacted]', $safe) ?? $safe;
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;

        return mb_substr($safe, 0, 300);
    }

    private function log(string $message): void
    {
        error_log('[ServiceNotificationService] '.$message);
    }
}
