<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

use App\Entity\ServiceClaim;

/**
 * A new claim link, as its creator sees it once: the token is only in here (and in the links), never stored.
 */
final class CreatedClaimLink
{
    public function __construct(
        public readonly ServiceClaim $claim,
        #[\SensitiveParameter]
        public readonly string $token,
        /** https://t.me/<bot>?start=claim_<token>, or null when the bot's username is unknown. */
        public readonly ?string $botLink,
        /** https://t.me/<bot>?startapp=claim_<token> (the bot's main Mini App), or null when the Mini App is off. */
        public readonly ?string $appLink,
        /** Whether this service had another open link, which this one revoked. */
        public readonly bool $replacedOpenLink,
    ) {
    }

    /** "claim_<token>": what /start receives. */
    public function payload(): string
    {
        return ClaimToken::payload($this->token);
    }
}
