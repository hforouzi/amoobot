<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\SubscriptionPageButton;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\ServiceClaim;
use App\Entity\User;
use App\Entity\VpnService;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use Psr\Log\LoggerInterface;

/**
 * The bot messages of claim links, sent after the change was committed and best effort (a failure is logged, never
 * undoes anything): the new owner is told the service is in "My services" (with its subscription page, the new one);
 * the previous owner that it moved, without a word about who took it. Admins with LOGS_VIEW get an info line when one
 * Telegram user keeps trying invalid links (no automatic ban).
 */
class ClaimNotifier
{
    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly BotTextResolver $texts,
        private readonly SubscriptionPageButton $subscriptionPageButton,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function claimed(ServiceClaim $claim, VpnService $service, User $claimer, string $claimerChatId): void
    {
        $this->send($claimerChatId, $this->texts->message('claim.done', ['service' => ['id' => (int) $service->getId()]]), $this->claimerKeyboard($service));

        // The previous owner (the reseller, or the customer whose service an admin handed over): only that it moved.
        $previous = $claim->getFromUser()->getTelegramAccount()?->getTelegramId();
        if (null !== $previous && '' !== $previous && $previous !== $claimerChatId) {
            $key = null !== $claim->getReseller() ? 'claim.link_used' : 'claim.moved_away';
            $this->send($previous, $this->texts->message($key, ['service' => ['id' => (int) $service->getId()], 'claim' => ['claimedAt' => $claim->getClaimedAt()?->format('Y-m-d H:i') ?? '-']]));
        }
    }

    /** The service went back to the reseller who sold it (admin "Return to reseller"): both sides are told. */
    public function returned(VpnService $service, User $from, User $to): void
    {
        $fromChat = $from->getTelegramAccount()?->getTelegramId();
        if (null !== $fromChat && '' !== $fromChat) {
            $this->send($fromChat, $this->texts->message('claim.returned_from', ['service' => ['id' => (int) $service->getId()]]));
        }
        $toChat = $to->getTelegramAccount()?->getTelegramId();
        if (null !== $toChat && '' !== $toChat) {
            $this->send($toChat, $this->texts->message('claim.returned_to', ['service' => ['id' => (int) $service->getId()]]));
        }
    }

    /** One Telegram user reached the invalid-link threshold (ClaimService): an info line to the admins, no ban. */
    public function invalidAttempts(string $telegramUserId, int $count): void
    {
        $this->logger->info('claim_invalid_attempts', ['telegram_id' => $telegramUserId, 'count' => $count]);
        foreach ($this->accessPolicy->telegramRecipients(AdminPermission::LOGS_VIEW) as $chatId) {
            $this->send($chatId, $this->texts->message('claim.admin_invalid_attempts', ['claim' => ['telegramId' => $telegramUserId, 'count' => $count]]));
        }
    }

    /**
     * A reset of connection links whose outcome on the panel could not be confirmed, or that the panel did not keep:
     * the admins with LOGS_VIEW are told (service id and what is wrong; nothing of the customer), to check the client.
     */
    public function resetNeedsAttention(int $serviceId, string $what): void
    {
        $this->logger->error('claim_reset_links_needs_attention', ['service_id' => $serviceId, 'what' => $what]);
        foreach ($this->accessPolicy->telegramRecipients(AdminPermission::LOGS_VIEW) as $chatId) {
            $this->send($chatId, $this->texts->message('claim.admin_reset_attention', ['service' => ['id' => $serviceId], 'claim' => ['what' => $what]]));
        }
    }

    /**
     * The claimer's buttons: the (new) subscription page and the service in "My services".
     *
     * @return array{inline_keyboard: list<list<array<string, string>>>}
     */
    public function claimerKeyboard(VpnService $service): array
    {
        $rows = $this->subscriptionPageButton->keyboardFor($service)['inline_keyboard'] ?? [];
        $rows[] = [['text' => $this->texts->button('button.claim.view_service'), 'callback_data' => 'service_view:'.$service->getId()]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param array<string, mixed>|null $keyboard
     */
    private function send(string $chatId, string $text, ?array $keyboard = null): void
    {
        try {
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        } catch (\Throwable $e) {
            $this->logger->warning('Claim notification failed.', ['error' => $e->getMessage()]);
        }
    }
}
