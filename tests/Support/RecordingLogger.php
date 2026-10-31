<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Test only (config/services.yaml, when@test): sits under App\Shared\Infrastructure\RedactingLogger, so it records every
 * log record as it would reach the real logger (all levels, context included), then passes it on unchanged.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        $this->inner->log($level, $message, $context);
    }

    /** Every record as one text (message and context), for "never contains" checks. */
    public function text(): string
    {
        return implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $this->records));
    }
}
