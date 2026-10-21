<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: orders.
 */
final class AdminOrdersHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly AdminBotReplies $replies,
    ) {
    }

    public function recent(string $chatId, ?string $callbackId = null): void
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $orders) {
            $this->replies->popup($chatId, $callbackId, 'سفارشی برای نمایش وجود ندارد.', 'popup_no_orders');

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
                "Order ID: %d\nTracking: %s\nUser: %s\nPlan: %s\nAmount: %d\nStatus: %s%s\nCreated at: %s\n",
                $order->getId(),
                (string) ($order->getTrackingCode() ?? '-'),
                $this->replies->identity($order->getUser()->getTelegramAccount()),
                $order->getPlan()->getTitle(),
                $order->getAmount(),
                $order->getStatus(),
                $customInfo,
                $order->getCreatedAt()->format('Y-m-d H:i:s')
            );
        }

        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->backToAdminMenu());
    }
}
