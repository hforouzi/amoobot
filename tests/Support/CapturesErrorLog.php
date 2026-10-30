<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Captures what the code under test writes through error_log(), the destination of the app's provisioning logs and of
 * the framework's fallback logger (no MonologBundle): journald for the jobs, the PHP-FPM/nginx error log for the web.
 */
trait CapturesErrorLog
{
    /**
     * @param callable(): void $action
     */
    private function captureErrorLog(callable $action): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'amoobot-log-');
        $previous = ini_set('error_log', $file);
        try {
            $action();
        } finally {
            ini_set('error_log', false === $previous ? '' : $previous);
        }
        $log = (string) file_get_contents($file);
        @unlink($file);

        return $log;
    }

    /**
     * @param array<string, ?string> $secrets label => value
     */
    private static function assertContainsNoSecret(string $text, array $secrets, string $where): void
    {
        foreach ($secrets as $label => $secret) {
            $secret = trim((string) $secret);
            if ('' === $secret) {
                continue;
            }
            self::assertStringNotContainsString($secret, $text, sprintf('%s must not contain the %s', $where, $label));
            self::assertStringNotContainsString(rawurlencode($secret), $text, sprintf('%s must not contain the URL-encoded %s', $where, $label));
        }
    }
}
