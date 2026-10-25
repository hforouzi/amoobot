<?php

declare(strict_types=1);

namespace App\Bot\Application\DeepLink;

use App\Entity\TelegramAccount;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One kind of deep link, by its payload prefix ("claim" now; "ref" for referrals later). Registered by implementing this
 * interface (autoconfigured tag app.start_payload_handler); StartPayloadRouter picks the handler by prefix. A handler
 * receives only a syntactically valid payload and must treat its value as untrusted.
 */
#[AutoconfigureTag('app.start_payload_handler')]
interface StartPayloadHandler
{
    /** The payload prefix this handler owns (lower-case letters, without the "_"). */
    public function prefix(): string;

    /** Whether the feature is on: when false the payload is ignored and /start behaves as without one. */
    public function enabled(): bool;

    /**
     * The bot received "/start <prefix>_<value>" from this account (already created / updated, like any /start).
     */
    public function handleBot(TelegramAccount $account, string $chatId, #[\SensitiveParameter] string $value): void;

    /**
     * The Mini App was opened with this start parameter by this (signed-in) account: the section path to open first
     * (/app/view/...), or null for the usual home.
     */
    public function miniAppTarget(TelegramAccount $account, #[\SensitiveParameter] string $value): ?string;
}
