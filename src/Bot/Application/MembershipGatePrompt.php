<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;

/**
 * The bot side of the required-channels gate (TelegramChannelMembershipGate): checks the customer and, when channels are
 * missing, sends the "join these channels" message with the invite and "check membership" buttons. Shared by every bot
 * handler that sells (TelegramUpdateHandler, WalletBotHandler), so they all ask the same way.
 */
final class MembershipGatePrompt
{
    public function __construct(
        private readonly TelegramChannelMembershipGate $membershipGate,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    /** True when the customer may go on; else the gate message was sent and the caller stops. */
    public function allowed(TelegramAccount $account, string $chatId, ?string $callbackId, string $context): bool
    {
        $result = $this->membershipGate->check($account->getTelegramId(), $context);
        if ($result->allowed) {
            return true;
        }

        $this->send($chatId, $callbackId, $context, $result);

        return false;
    }

    public function send(string $chatId, ?string $callbackId, string $context, MembershipGateResult $result): void
    {
        if (null !== $callbackId && '' !== $callbackId) {
            $this->telegramApiClient->answerCallbackQuery($callbackId);
        }
        $lines = ['برای ادامه، ابتدا باید عضو کانال‌های زیر باشید. بعد از عضویت، روی «بررسی عضویت» بزنید.', ''];
        foreach ($result->missingChannels as $channel) {
            $lines[] = sprintf('- %s (%s)', $channel->getTitle(), $channel->getChatId());
        }

        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboard($context, $result));
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}
     */
    private function keyboard(string $context, MembershipGateResult $result): array
    {
        $rows = [];
        foreach ($result->missingChannels as $channel) {
            $inviteUrl = trim((string) ($channel->getInviteUrl() ?? ''));
            if ('' === $inviteUrl) {
                continue;
            }

            $rows[] = [[
                'text' => sprintf('عضویت در %s', $channel->getTitle()),
                'url' => $inviteUrl,
            ]];
        }

        $rows[] = [[
            'text' => '✅ بررسی عضویت',
            'callback_data' => 'membership_check:'.$context,
        ]];

        return ['inline_keyboard' => $rows];
    }
}
