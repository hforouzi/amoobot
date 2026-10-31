<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;

/**
 * The app's own error_log() lines (provisioning, links, the bot's debug lines) go through here, so none of them writes
 * a customer's Telegram id: SecretRedactor::forLog() (M2d B3). Same destination as error_log(): journald for the jobs,
 * the PHP-FPM error log for the web. It never throws (M2e E3): a line longer than RedactingLogger::MAX_STRING_BYTES is
 * cut, one that cannot be redacted is written as SecretRedactor::WITHHELD.
 */
final class SafeErrorLog
{
    public static function write(string $message): void
    {
        try {
            error_log(SecretRedactor::forLog(self::cap($message)));
        } catch (\Throwable) {
            // Logging must never break the caller.
        }
    }

    /** $text cut to RedactingLogger::MAX_STRING_BYTES, saying how much was left out. */
    public static function cap(string $text): string
    {
        $over = strlen($text) - RedactingLogger::MAX_STRING_BYTES;

        return $over > 0 ? substr($text, 0, RedactingLogger::MAX_STRING_BYTES).sprintf(' …[%d bytes cut]', $over) : $text;
    }
}
