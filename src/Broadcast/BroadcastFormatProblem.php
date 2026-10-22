<?php

declare(strict_types=1);

namespace App\Broadcast;

/**
 * Why Telegram would refuse a broadcast's text in its format: a translation key (validators domain) and its
 * parameters ({{ char }}, {{ line }}, {{ tag }}, {{ expected }}).
 */
final readonly class BroadcastFormatProblem
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $message,
        public array $parameters = [],
    ) {
    }
}
