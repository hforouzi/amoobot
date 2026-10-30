<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Telegram alerts about additional inbounds (docs/design/multi-inbound-plans.md 3.2: "alerted to the admins once per
 * service, like stuck payments"), sent by the scheduled run to the admins who may press "Retry attach"
 * (SERVICES_MANAGE; AdminAccessPolicy recipients, as AdminPaymentAlerter). One message per service and kind:
 *
 * - FAILED: an active service's inbound failed for the first time (at the sale, or found removed on the panel);
 * - EXHAUSTED: its attempts are used up (MultiInboundSettings::maxAttempts()): the scheduled retry stops;
 * - RECOVERED: an inbound alerted about is attached again.
 *
 * Each row is claimed with a conditional UPDATE of vpn_service_inbound.alert_state (the stuck-payment mechanism), so
 * overlapping runs never alert twice; nothing is claimed while no admin chat exists (the next run tries again). The text
 * names the service, user, plan and inbounds by local id and title and quotes the stored error, which is safe already
 * (ServiceErrorText: no UUID, subscription id, link or panel address), with the client email masked too.
 */
class InboundAttachAlerts
{
    public const FAILED_TITLE = '⚠️ اینباند اضافهٔ سرویس وصل نشد';
    public const EXHAUSTED_TITLE = '🚨 تلاش‌های اتصال اینباند اضافهٔ سرویس تمام شد';
    public const RECOVERED_TITLE = '✅ اینباند اضافهٔ سرویس دوباره وصل شد';
    public const MISSING_TITLE = '⚠️ اینباند اضافهٔ پلن پیش از ساخت سرویس حذف شده بود';

    private const KIND_FAILED = 'failed';
    private const KIND_EXHAUSTED = 'exhausted';
    private const KIND_RECOVERED = 'recovered';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MultiInboundSettings $settings,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int the alerts sent (one per service and kind)
     */
    public function send(int $limit = 500): int
    {
        $chats = $this->accessPolicy->telegramRecipients(AdminPermission::SERVICES_MANAGE);
        $max = $this->settings->maxAttempts();
        $connection = $this->entityManager->getConnection();
        $rows = $connection->fetchAllAssociative(
            sprintf(
                "SELECT r.id, r.service_id, r.status, r.attempts, r.alert_state, r.last_error, i.id AS inbound_id, i.title AS inbound_title, s.user_id, s.client_email, p.title AS plan_title
                 FROM vpn_service_inbound r
                 JOIN vpn_service s ON s.id = r.service_id
                 JOIN vpn_inbound i ON i.id = r.inbound_id
                 LEFT JOIN `order` o ON o.id = s.order_id
                 LEFT JOIN plan p ON p.id = o.plan_id
                 WHERE s.status = :active AND (
                   (r.status = :failed AND (r.alert_state IS NULL OR (r.alert_state = :alertFailed AND r.attempts >= :max)))
                   OR (r.status = :attached AND r.alert_state IS NOT NULL))
                 ORDER BY r.service_id, r.id
                 LIMIT %d",
                max(1, $limit),
            ),
            ['active' => VpnServiceStatus::ACTIVE, 'failed' => VpnServiceInbound::STATUS_FAILED, 'alertFailed' => VpnServiceInbound::ALERT_FAILED, 'max' => $max, 'attached' => VpnServiceInbound::STATUS_ATTACHED],
        );
        if ([] === $rows) {
            return 0;
        }
        if ([] === $chats) {
            $this->logger->warning('Inbound attach alert not sent: no admin chat (TELEGRAM_ADMIN_CHAT_ID empty and no linked admin with SERVICES_MANAGE).', ['rows' => count($rows)]);

            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $kind = VpnServiceInbound::STATUS_ATTACHED === $row['status'] ? self::KIND_RECOVERED : ((int) $row['attempts'] >= $max ? self::KIND_EXHAUSTED : self::KIND_FAILED);
            if ($this->claim((int) $row['id'], $kind, $max)) {
                $groups[(int) $row['service_id']][$kind][] = $row;
            }
        }

        $sent = 0;
        foreach ($groups as $serviceId => $kinds) {
            foreach ($kinds as $kind => $claimed) {
                $this->deliver($chats, $this->text($serviceId, $kind, $claimed, $max));
                ++$sent;
            }
        }
        $this->logger->info('Inbound attach alerts sent.', ['alerts' => $sent]);

        return $sent;
    }

    /**
     * After a settlement (never inside its transaction): additional inbounds of the order's snapshot that were deleted
     * before it was provisioned (Order::PLAN_INBOUNDS_MISSING). They can never be attached and have no row, so this is
     * their only alert. Sent at once, whatever the switch.
     */
    public function missingInbounds(Order $order, VpnService $service): void
    {
        $missing = $order->getMetadata()[Order::PLAN_INBOUNDS_MISSING] ?? [];
        if (!is_array($missing) || [] === $missing) {
            return;
        }
        $chats = $this->accessPolicy->telegramRecipients(AdminPermission::SERVICES_MANAGE);
        if ([] === $chats) {
            $this->logger->warning('Missing inbound alert not sent: no admin chat.', ['service_id' => $service->getId()]);

            return;
        }
        $this->deliver($chats, implode("
", [
            self::MISSING_TITLE,
            sprintf('Service ID: %d', (int) $service->getId()),
            sprintf('Order ID: %d', (int) $order->getId()),
            sprintf('User ID: %d', (int) $service->getUser()->getId()),
            sprintf('Plan: %s', mb_substr($order->requirePlan()->getTitle(), 0, 80)),
            'Deleted inbound id(s): '.implode(', ', array_map(static fn (mixed $id): string => '#'.(int) $id, $missing)),
            'The service was delivered without them; they cannot be attached. Add the servers to the customer by hand if needed.',
        ]));
    }

    /** One row, only if it is still in the state this alert is about: two runs never both get it. */
    private function claim(int $rowId, string $kind, int $max): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection = $this->entityManager->getConnection();

        return 1 === match ($kind) {
            self::KIND_FAILED => $connection->executeStatement(
                'UPDATE vpn_service_inbound SET alert_state = ?, alerted_at = ? WHERE id = ? AND status = ? AND alert_state IS NULL AND attempts < ?',
                [VpnServiceInbound::ALERT_FAILED, $now, $rowId, VpnServiceInbound::STATUS_FAILED, $max],
            ),
            self::KIND_EXHAUSTED => $connection->executeStatement(
                'UPDATE vpn_service_inbound SET alert_state = ?, alerted_at = ? WHERE id = ? AND status = ? AND attempts >= ? AND (alert_state IS NULL OR alert_state = ?)',
                [VpnServiceInbound::ALERT_EXHAUSTED, $now, $rowId, VpnServiceInbound::STATUS_FAILED, $max, VpnServiceInbound::ALERT_FAILED],
            ),
            default => $connection->executeStatement(
                'UPDATE vpn_service_inbound SET alert_state = NULL, alerted_at = ? WHERE id = ? AND status = ? AND alert_state IS NOT NULL',
                [$now, $rowId, VpnServiceInbound::STATUS_ATTACHED],
            ),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function text(int $serviceId, string $kind, array $rows, int $max): string
    {
        $first = $rows[0];
        $inbounds = implode(', ', array_map(static fn (array $r): string => sprintf('"%s" (#%d)', mb_substr((string) $r['inbound_title'], 0, 60), (int) $r['inbound_id']), $rows));
        $lines = [
            match ($kind) {
                self::KIND_FAILED => self::FAILED_TITLE,
                self::KIND_EXHAUSTED => self::EXHAUSTED_TITLE,
                default => self::RECOVERED_TITLE,
            },
            sprintf('Service ID: %d', $serviceId),
            sprintf('User ID: %d', (int) $first['user_id']),
            sprintf('Plan: %s', mb_substr((string) ($first['plan_title'] ?? '-'), 0, 80)),
        ];
        if (self::KIND_RECOVERED === $kind) {
            $lines[] = 'Attached again: '.$inbounds;

            return implode("\n", $lines);
        }
        $lines[] = 'Not attached: '.$inbounds;
        $lines[] = 'Last error: '.mb_substr(self::withoutClient((string) ($first['last_error'] ?? '-'), (string) ($first['client_email'] ?? '')), 0, 200);
        $looping = [] !== array_filter($rows, static fn (array $r): bool => InboundAttachScheduler::REATTACH_STOPPED === $r['last_error']);
        $lines[] = match (true) {
            self::KIND_EXHAUSTED === $kind && $looping => sprintf('Removed on the panel and attached again %d times within %d hours: the automatic re-attach stopped. Find out what removes it (another tool, a panel script), then use "Retry attach" on the service page (Services → #%d).', InboundAttachScheduler::MAX_REATTACH_CYCLES, InboundAttachScheduler::REATTACH_WINDOW_HOURS, $serviceId),
            self::KIND_EXHAUSTED === $kind => sprintf('The scheduled retry gave up after %d attempt(s). Fix the panel, then use "Retry attach" on the service page (Services → #%d).', $max, $serviceId),
            default => sprintf('Retried automatically (at most %d attempts), or use "Retry attach" on the service page (Services → #%d). The customer has the other servers.', $max, $serviceId),
        };

        return implode("\n", $lines);
    }

    /** The stored error names the panel request, whose path carries the client email: not for a Telegram chat. */
    private static function withoutClient(string $error, string $email): string
    {
        $error = (string) preg_replace('#clients/[^/\s]+/#', 'clients/***/', $error);

        return '' === trim($email) ? $error : str_ireplace([$email, rawurlencode($email)], '***', $error);
    }

    /**
     * @param list<string> $chats
     */
    private function deliver(array $chats, string $text): void
    {
        foreach ($chats as $chatId) {
            try {
                $this->telegramApiClient->sendMessage($chatId, $text);
            } catch (\Throwable $e) {
                $this->logger->error('Inbound attach alert failed.', ['chat' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }
}
