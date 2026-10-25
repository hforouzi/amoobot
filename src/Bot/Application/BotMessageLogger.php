<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\BotMessageLog;
use App\Reseller\Domain\ClaimToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores every update the bot receives and every message it sends (bot_message_log). Claim-link tokens are masked first
 * (ClaimToken::redact) in every string of the payload, keys included: an incoming "/start claim_<token>", the link sent
 * to the reseller who made it. A token in this table would be a usable link for anyone who can read the logs.
 */
class BotMessageLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(string $direction, array $payload, ?string $telegramId = null, ?string $updateType = null): void
    {
        $log = (new BotMessageLog())
            ->setDirection($direction)
            ->setPayload(self::redact($payload))
            ->setTelegramId($telegramId)
            ->setUpdateType($updateType);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    public static function redact(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            $key = is_string($key) ? ClaimToken::redact($key) : $key;
            $clean[$key] = match (true) {
                is_array($value) => self::redact($value),
                is_string($value) => ClaimToken::redact($value),
                default => $value,
            };
        }

        return $clean;
    }
}
