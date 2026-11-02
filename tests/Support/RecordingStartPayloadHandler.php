<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Bot\Application\DeepLink\StartPayloadHandler;
use App\Entity\TelegramAccount;

/**
 * A deep-link handler for router tests: a fixed prefix, on or off, a fixed Mini App target; remembers what it received.
 */
final class RecordingStartPayloadHandler implements StartPayloadHandler
{
    /** @var list<string> */
    public array $seen = [];

    /**
     * @param list<string>|null $accepted the values accepts() takes (null: every value)
     */
    public function __construct(private readonly string $prefix, private readonly bool $enabled, private readonly ?string $target, private readonly ?array $accepted = null)
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function accepts(string $value): bool
    {
        return null === $this->accepted || in_array($value, $this->accepted, true);
    }

    public function handleBot(TelegramAccount $account, string $chatId, string $value): void
    {
        $this->seen[] = $value;
    }

    public function miniAppTarget(TelegramAccount $account, string $value): ?string
    {
        return $this->target;
    }
}
