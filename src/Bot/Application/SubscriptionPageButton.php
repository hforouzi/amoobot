<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Subscription\Application\SubscriptionPageUrl;

/**
 * The "Open subscription page" URL button under a message that delivers a service (after a purchase, a trial).
 * None when the service is gone or the shop has no public address Telegram accepts.
 */
final class SubscriptionPageButton
{
    public function __construct(
        private readonly SubscriptionPageUrl $pageUrl,
        private readonly TelegramKeyboardFactory $keyboardFactory,
    ) {
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, url: string}>>}|null
     */
    public function keyboardFor(?VpnService $service): ?array
    {
        if (!$service instanceof VpnService || VpnServiceStatus::DELETED === $service->getStatus()) {
            return null;
        }

        return $this->keyboardFactory->subscriptionPage($this->pageUrl->forTelegram($service));
    }
}
