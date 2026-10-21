<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\VpnService;
use App\Provisioning\Domain\PublicUrlPolicy;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The last check before a subscription URL, config link or QR code reaches a customer: only addresses a phone on the
 * internet can reach (PublicUrlPolicy). What fails is withheld, and the admins who manage panels are alerted once per
 * service (deduplicated through service_notification_log).
 */
class CustomerAccessGuard
{
    public const SUPPORT_NOTICE = 'لینک اشتراک این سرویس فعلاً قابل استفاده نیست. لطفاً با پشتیبانی تماس بگیرید.';
    private const ALERT_TYPE = 'admin_alert';
    private const ALERT_KEY = 'unusable_access_link';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The stored subscription URL if a client can use it, else null (and the admins are alerted). */
    public function subscriptionUrl(VpnService $service): ?string
    {
        $url = trim((string) ($service->getSubscriptionUrl() ?? ''));
        if ('' === $url) {
            return null;
        }
        if (PublicUrlPolicy::isPubliclyUsableUrl($url)) {
            return $url;
        }

        $this->alertOnce($service, 'subscription URL', $this->describe($url));

        return null;
    }

    /**
     * The links a client can use; the others are dropped (and the admins are alerted).
     *
     * @param array<int, string> $links
     *
     * @return list<string>
     */
    public function configLinks(VpnService $service, array $links): array
    {
        $usable = [];
        $rejected = [];
        foreach ($links as $link) {
            if (PublicUrlPolicy::isPubliclyUsableLink($link)) {
                $usable[] = $link;
            } else {
                $rejected[] = $this->describe($link);
            }
        }
        if ([] !== $rejected) {
            $this->alertOnce($service, 'config link', implode(', ', array_unique($rejected)));
        }

        return $usable;
    }

    /** Scheme and host only: never the sub id, uuid or password in a link. */
    private function describe(string $link): string
    {
        $scheme = strtolower((string) (parse_url($link, PHP_URL_SCHEME) ?: '?'));
        $host = PublicUrlPolicy::linkHost($link) ?? '(no host)';

        return $scheme.'://'.$host;
    }

    private function alertOnce(VpnService $service, string $what, string $address): void
    {
        $this->logger->warning('Unusable customer access link withheld.', ['service_id' => $service->getId(), 'what' => $what, 'address' => $address]);

        $serviceId = $service->getId();
        if (null === $serviceId || !$this->firstAlert($service, $serviceId, $what, $address)) {
            return;
        }

        $panel = $service->getPanel();
        $text = implode("\n", [
            '🚨 لینک دسترسی مشتری قابل استفاده نیست',
            sprintf('سرویس #%d', $serviceId),
            sprintf('Panel: %s (#%d)', $panel?->getTitle() ?? '-', $panel?->getId() ?? 0),
            sprintf('Withheld %s: %s', $what, $address),
            'The customer was told to contact support.',
            'Fix: fill "Subscription base URL" (and the public host) in the panel\'s Advanced section with the address customers use, e.g. https://sub.example.com:2096, then run app:panel:doctor.',
        ]);

        $chats = $this->accessPolicy->telegramRecipients(AdminPermission::PANELS_MANAGE);
        if ([] === $chats) {
            $this->logger->warning('Unusable access link alert not sent: no admin chat.', ['service_id' => $serviceId]);
        }
        foreach ($chats as $chatId) {
            try {
                $this->telegramApiClient->sendMessage($chatId, $text);
            } catch (\Throwable $e) {
                $this->logger->error('Unusable access link alert failed.', ['service_id' => $serviceId, 'chat' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Claims the alert for this service; false when it was already sent (atomic, outside the caller's unit of work). */
    private function firstAlert(VpnService $service, int $serviceId, string $what, string $address): bool
    {
        try {
            return 1 === $this->entityManager->getConnection()->executeStatement(
                'INSERT IGNORE INTO service_notification_log (service_id, user_id, type, key_name, sent_at, payload) VALUES (?, ?, ?, ?, ?, ?)',
                [$serviceId, $service->getUser()->getId(), self::ALERT_TYPE, self::ALERT_KEY, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), json_encode(['what' => $what, 'address' => $address])],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unusable access link alert could not be recorded.', ['service_id' => $serviceId, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
