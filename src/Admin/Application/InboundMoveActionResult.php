<?php

declare(strict_types=1);

namespace App\Admin\Application;

/**
 * The outcome of an InboundMoveActions call, for the surface to show: ok (done) or not, where it belongs (back: the
 * Apply page with the same scope, else the plan's or service's page), the messages (level, key of the admin domain,
 * plain parameters), the scope as a query, the run created, and whether the per-service limiter refused it.
 */
final class InboundMoveActionResult
{
    /**
     * @param list<array{0: string, 1: string, 2: array<string, int|string>}> $messages
     * @param array<string, string>                                            $query
     */
    public function __construct(
        public readonly bool $ok,
        public readonly bool $back,
        public readonly array $messages,
        public readonly array $query = [],
        public readonly ?int $runId = null,
        public readonly bool $rateLimited = false,
    ) {
    }
}
