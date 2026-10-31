<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Every PSR log record of the app and its plugins (the "logger" service) without a customer's Telegram id (M2d B3):
 * the message and every string in the context go through SecretRedactor::forLog() (a client email tg_<id>_order_<n>
 * loses the id), the values of the Telegram id keys (SecretRedactor::TELEGRAM_ID_KEYS) are masked, and an exception
 * in the context is replaced by its class and its redacted message. Records are otherwise passed on unchanged.
 */
#[AsDecorator('logger')]
final class RedactingLogger extends AbstractLogger
{
    public function __construct(#[AutowireDecorated] private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, SecretRedactor::forLog((string) $message), self::context($context));
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function context(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = match (true) {
                is_string($key) && in_array($key, SecretRedactor::TELEGRAM_ID_KEYS, true) && (is_int($value) || is_string($value)) => SecretRedactor::MASK,
                is_string($value) => SecretRedactor::forLog($value),
                $value instanceof \Throwable => $value::class.': '.SecretRedactor::forLog($value->getMessage()),
                is_array($value) => self::context($value),
                default => $value,
            };
        }

        return $context;
    }
}
