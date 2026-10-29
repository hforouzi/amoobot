<?php

declare(strict_types=1);

namespace App\Reseller\UI;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\DeepLink\StartPayloadHandler;
use App\Bot\Application\MembershipGatePrompt;
use App\Bot\Application\ServiceManagementService;
use App\Bot\Application\TelegramChannelMembershipGate;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Reseller\Application\ClaimNotifier;
use App\Reseller\Application\ClaimService;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimPreview;
use App\Reseller\Domain\ClaimToken;
use Psr\Log\LoggerInterface;

/**
 * The claimer's side of claim links in the bot (docs/design/reseller-and-claim.md, 3.3), and the deep-link handler of
 * the "claim" prefix (StartPayloadRouter) for the bot and the Mini App.
 *
 * "/start claim_<token>": the required-channel gate first (as for a purchase), then a preview with "Add to my account"
 * / Cancel. Opening the link changes nothing; the button carries the claim id and a ticket for this user (ClaimTickets),
 * never the token. Every unusable link gets the same answer.
 *
 * Callbacks: cl_ok:{claimId}:{ticket} (claim), cl_no (cancel), cl_rst:{serviceId} (reset my connection links: the
 * warning), cl_rst_ok:{serviceId}:{nonce} (do it; the nonce names the credentials the warning was shown for, so a
 * second delivery of the same tap rotates nothing: ServiceOwnership::resetNonce). A cl_rst_ok without a nonce (a button
 * from before) shows the warning again.
 */
final class ClaimBotHandler implements StartPayloadHandler
{
    private const PATTERNS = [
        'cl_ok' => '/^cl_ok:([1-9]\d{0,9}):([0-9a-z]{7}[A-Za-z0-9_-]{16})$/D',
        'cl_rst' => '/^cl_rst:([1-9]\d{0,9})$/D',
        'cl_rst_ok' => '/^cl_rst_ok:([1-9]\d{0,9})(?::([0-9a-f]{12}))?$/D',
    ];

    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly BotTextResolver $texts,
        private readonly ClaimService $claims,
        private readonly ClaimNotifier $notifier,
        private readonly ResellerSettings $settings,
        private readonly ServiceOwnership $ownership,
        private readonly ServiceManagementService $serviceManagement,
        private readonly CustomerServiceFinder $customerServices,
        private readonly MembershipGatePrompt $membershipGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prefix(): string
    {
        return ClaimToken::PREFIX;
    }

    public function enabled(): bool
    {
        return $this->settings->claimsEnabled();
    }

    public function handleBot(TelegramAccount $account, string $chatId, #[\SensitiveParameter] string $value): void
    {
        if (!$this->membershipGate->allowed($account, $chatId, null, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }
        try {
            $preview = $this->claims->preview($value, $account->getUser(), $account->getTelegramId());
        } catch (ClaimException $e) {
            $this->telegram->sendMessage($chatId, $this->errorText($e->error));

            return;
        }
        $this->telegram->sendMessage($chatId, $this->previewText($preview), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.claim.accept'), 'callback_data' => sprintf('cl_ok:%d:%s', $preview->claimId, $preview->ticket)]],
            [['text' => $this->texts->button('button.claim.cancel'), 'callback_data' => 'cl_no']],
        ]]);
    }

    /** The claim screen with the preview's ticket, or the screen that says why not (never the token in a URL). */
    public function miniAppTarget(TelegramAccount $account, #[\SensitiveParameter] string $value): string
    {
        try {
            $preview = $this->claims->preview($value, $account->getUser(), $account->getTelegramId());
        } catch (ClaimException $e) {
            return '/app/view/claim/failed/'.$e->error;
        }

        return sprintf('/app/view/claim/%d/%s', $preview->claimId, $preview->ticket);
    }

    public function supports(string $data): bool
    {
        return 'cl_no' === $data || str_starts_with($data, 'cl_ok:') || str_starts_with($data, 'cl_rst:') || str_starts_with($data, 'cl_rst_ok:');
    }

    public function handle(TelegramAccount $account, string $chatId, string $callbackId, string $data): void
    {
        if ('cl_no' === $data) {
            $this->telegram->answerCallbackQuery($callbackId);
            $this->telegram->sendMessage($chatId, $this->texts->message('claim.cancelled'));

            return;
        }
        $kind = explode(':', $data, 2)[0];
        if (!isset(self::PATTERNS[$kind]) || 1 !== preg_match(self::PATTERNS[$kind], $data, $m)) {
            // Altered or truncated callback data: the same answer as an unusable link.
            $this->telegram->answerCallbackQuery($callbackId, $this->errorText(ClaimException::UNAVAILABLE), true);

            return;
        }

        match ($kind) {
            'cl_ok' => $this->claim($account, $chatId, $callbackId, (int) $m[1], $m[2]),
            'cl_rst' => $this->confirmReset($account, $chatId, $callbackId, (int) $m[1]),
            default => isset($m[2]) ? $this->reset($account, $chatId, $callbackId, (int) $m[1], $m[2]) : $this->confirmReset($account, $chatId, $callbackId, (int) $m[1]),
        };
    }

    private function claim(TelegramAccount $account, string $chatId, string $callbackId, int $claimId, string $ticket): void
    {
        if (!$this->membershipGate->allowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }
        try {
            [$outcome, $service] = $this->claims->claim($claimId, $ticket, $account->getUser(), $account->getTelegramId());
        } catch (ClaimException $e) {
            $this->telegram->answerCallbackQuery($callbackId);
            $this->telegram->sendMessage($chatId, $this->errorText($e->error));

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Bot claim failed.', ['claim_id' => $claimId, 'error' => $e->getMessage()]);
            $this->telegram->answerCallbackQuery($callbackId);
            $this->telegram->sendMessage($chatId, $this->texts->message('claim.failed'));

            return;
        }
        $this->telegram->answerCallbackQuery($callbackId);
        if (ClaimService::OUTCOME_ALREADY_YOURS === $outcome) {
            // A second delivery of the same tap: the first already said "done" (ClaimNotifier).
            $this->telegram->sendMessage($chatId, $this->texts->message('claim.already_yours', ['service' => ['id' => (int) $service->getId()]]), $this->notifier->claimerKeyboard($service));
        }
    }

    private function confirmReset(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId): void
    {
        $service = $this->customerServices->ownedBy($account->getUser(), $serviceId);
        if (null === $service || !$this->ownership->canResetLinks($account->getUser(), $service)) {
            $this->telegram->answerCallbackQuery($callbackId, $this->texts->message('claim.reset_unavailable'), true);

            return;
        }
        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('claim.reset_warning', ['service' => ['id' => $serviceId]]), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.claim.reset_confirm'), 'callback_data' => 'cl_rst_ok:'.$serviceId.':'.$this->ownership->resetNonce($service)]],
            [['text' => $this->texts->button('button.common.back'), 'callback_data' => 'service_view:'.$serviceId]],
        ]]);
    }

    /** The answer says what really happened: done, done already (a second tap), nothing changed, or unconfirmed. */
    private function reset(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId, string $nonce): void
    {
        try {
            [$outcome] = $this->ownership->resetConnectionLinks($account->getUser(), $serviceId, $nonce);
        } catch (ClaimException $e) {
            $this->telegram->answerCallbackQuery($callbackId, $this->texts->message(match ($e->error) {
                ClaimException::RESET_FAILED, ClaimException::BUSY => 'claim.reset_failed',
                ClaimException::RATE_LIMITED => 'claim.reset_rate_limited',
                ClaimException::RESET_UNCONFIRMED => 'claim.reset_unconfirmed',
                default => 'claim.reset_unavailable',
            }), true);
            if (ClaimException::RESET_UNCONFIRMED === $e->error) {
                // Also in the chat: it is more than a passing popup.
                $this->telegram->sendMessage($chatId, $this->texts->message('claim.reset_unconfirmed'));
            }

            return;
        }
        $this->telegram->sendMessage($chatId, $this->texts->message(ServiceOwnership::RESET_ALREADY_DONE === $outcome ? 'claim.reset_already_done' : 'claim.reset_done', ['service' => ['id' => $serviceId]]));
        // The service view with its new links (answers the callback).
        $this->serviceManagement->showUserServiceDetail($account, $serviceId, $chatId, $callbackId);
    }

    private function previewText(ClaimPreview $preview): string
    {
        $left = $preview->trafficLeftBytes();
        $text = $this->texts->message('claim.preview', ['claim' => [
            'serviceId' => $preview->serviceId,
            'plan' => $preview->planTitle,
            'trafficLeft' => null === $left ? '∞' : self::gb($left).' GB',
            'expiresAt' => $preview->serviceExpiresAt?->format('Y-m-d') ?? '∞',
            'linkExpiresAt' => $preview->linkExpiresAt->format('Y-m-d H:i'),
        ]]);
        if (null !== $preview->fromName && '' !== $preview->fromName) {
            $text .= "\n".$this->texts->message('claim.preview_from', ['claim' => ['from' => $preview->fromName]]);
        }

        return $text;
    }

    private function errorText(string $error): string
    {
        return $this->texts->message(match ($error) {
            ClaimException::RATE_LIMITED => 'claim.error.rate_limited',
            ClaimException::BUSY => 'claim.error.busy',
            ClaimException::OWN_SERVICE => 'claim.error.own_service',
            ClaimException::ALREADY_YOURS => 'claim.error.already_yours',
            default => 'claim.unavailable',
        });
    }

    private static function gb(int $bytes): string
    {
        return rtrim(rtrim(number_format(max(0, $bytes) / 1073741824, 2, '.', ''), '0'), '.');
    }
}
