<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Every PSR log record of the app and its plugins (the "logger" service) without a customer's Telegram id (M2d B3,
 * M2e E3/E4): the message and every string in the context go through SecretRedactor::forLog(), an exception in the
 * context is replaced by its class and its redacted message, and a Stringable value by its redacted text (so a
 * {placeholder} the inner logger fills in from it is redacted too; one whose __toString() throws becomes UNPRINTABLE).
 *
 * The rule for a Telegram id in the context (also for {placeholder}s, which the inner logger fills in from these values):
 * under a key of SecretRedactor::TELEGRAM_ID_KEYS any int or string is masked (as in M2d); under another key that names
 * a Telegram id (isTelegramIdKey(): it contains "chat", "telegram" or "actor", or is tg_id / tgId / from / from_id /
 * fromId) an int, or a string of digits, of 5+ digits is masked. Every other key keeps its
 * value as it is, so order, payment and service ids, amounts and ports are never touched: a Telegram id must be logged
 * under such a key.
 *
 * It never throws and never recurses without bound: the context is walked to MAX_DEPTH levels and MAX_NODES values in
 * all (a self-referencing array stops there), a string longer than MAX_STRING_BYTES is cut, other objects and resources
 * are passed on as they are. Anything that still fails sends the redacted message alone (CONTEXT_DROPPED).
 */
#[AsDecorator('logger')]
final class RedactingLogger extends AbstractLogger
{
    public const MAX_DEPTH = 5;
    public const MAX_NODES = 1000;
    public const MAX_STRING_BYTES = 1048576;
    public const DEPTH_LIMIT = '[depth limit]';
    public const NODE_LIMIT = '[truncated]';
    public const UNPRINTABLE = '[unprintable]';
    public const CONTEXT_DROPPED = '[context dropped: it could not be redacted]';

    public function __construct(#[AutowireDecorated] private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $nodes = 0;
            $text = SafeErrorLog::cap(SecretRedactor::forLog(self::text($message)));
            $context = self::context($context, 1, $nodes);
        } catch (\Throwable) {
            $text = isset($text) ? $text : SecretRedactor::WITHHELD;
            $context = ['redaction' => self::CONTEXT_DROPPED];
        }
        try {
            $this->inner->log($level, $text, $context);
        } catch (\Throwable) {
            // A logger must never break the caller.
        }
    }

    /** Whether a context key holds a Telegram id (see the class comment). */
    public static function isTelegramIdKey(string $key): bool
    {
        return in_array($key, SecretRedactor::TELEGRAM_ID_KEYS, true)
            || 1 === preg_match('/chat|telegram|actor|^tg_?id$|^from(_?id)?$/i', $key);
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function context(array $context, int $depth, int &$nodes): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (++$nodes > self::MAX_NODES) {
                $out[$key] = self::NODE_LIMIT;
                break;
            }
            $out[$key] = match (true) {
                // The listed keys, as in M2d: whatever int or string they hold.
                is_string($key) && in_array($key, SecretRedactor::TELEGRAM_ID_KEYS, true) && (is_int($value) || is_string($value)) => SecretRedactor::MASK,
                is_string($key) && self::isTelegramIdKey($key) && (is_int($value) || is_string($value)) && 1 === preg_match('/^-?\d{5,}$/', (string) $value) => SecretRedactor::MASK,
                is_string($value) => SecretRedactor::forLog(SafeErrorLog::cap($value)),
                $value instanceof \Throwable => $value::class.': '.SecretRedactor::forLog(SafeErrorLog::cap(self::text($value->getMessage()))),
                $value instanceof \Stringable => SecretRedactor::forLog(SafeErrorLog::cap(self::text($value))),
                is_array($value) => $depth >= self::MAX_DEPTH ? self::DEPTH_LIMIT : self::context($value, $depth + 1, $nodes),
                default => $value,
            };
        }

        return $out;
    }

    private static function text(string|\Stringable $value): string
    {
        try {
            return (string) $value;
        } catch (\Throwable) {
            return self::UNPRINTABLE;
        }
    }
}
