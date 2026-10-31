<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;

/**
 * The app's own error_log() lines (provisioning, links, the bot's debug lines) go through here, so none of them writes
 * a customer's Telegram id: SecretRedactor::forLog() (M2d B3). Same destination as error_log(): journald for the jobs,
 * the PHP-FPM error log for the web.
 */
final class SafeErrorLog
{
    public static function write(string $message): void
    {
        error_log(SecretRedactor::forLog($message));
    }
}
