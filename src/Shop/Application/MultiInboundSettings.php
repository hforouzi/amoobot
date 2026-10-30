<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The switch for plans with several inbounds (docs/design/multi-inbound-plans.md 6), a Setting row edited on the web
 * settings page, "plans" tab (SETTINGS_MANAGE). Off by default. While off the web plan form has no "additional inbounds"
 * field and refuses one sent anyway, the plan list and the inbound page look as before.
 *
 * A plan with additional inbounds is sold only with the switch on, every inbound active and on the primary's panel, and
 * that panel 3x-ui in modern mode (PlanSaleRule over MultiInboundSale, M2c; checked at the catalog, draft confirmation,
 * payment start and wallet payment). Renewals and add-traffic are not gated. With the switch off, an order already paid
 * for such a plan still gets its primary inbound on any panel; its additional inbounds are recorded "not attached"
 * (VpnProvisioningService::SWITCHED_OFF) and never tried until the switch is on and an admin retries (M2c F3).
 *
 * M2b, the scheduled retry of failed additional inbounds (app:services:attach-inbounds, app:automation:run
 * --only=attach-inbounds), on the same tab: it runs only with the switch on AND attach_retry_enabled (on by default).
 * A failed row is tried again after retryDelayMinutes(), then twice as long each time, at most MAX_DELAY_HOURS, until it
 * has had maxAttempts() attempts in all (the one at the sale counts): nextAttemptAt().
 *
 * Read on admin pages and once per scheduled run, so it is not remembered between calls.
 */
final class MultiInboundSettings
{
    public const ENABLED = 'plans.multi_inbound_enabled';
    public const ATTACH_RETRY_ENABLED = 'plans.attach_retry_enabled';
    public const ATTACH_MAX_ATTEMPTS = 'plans.attach_max_attempts';
    public const ATTACH_RETRY_DELAY_MINUTES = 'plans.attach_retry_delay_minutes';

    public const DEFAULT_MAX_ATTEMPTS = 12;
    public const MAX_MAX_ATTEMPTS = 100;
    public const DEFAULT_RETRY_DELAY_MINUTES = 5;
    /** One day. */
    public const MAX_RETRY_DELAY_MINUTES = 1440;
    /** The back-off stops growing here (or at the first delay, when that is longer). */
    public const MAX_DELAY_HOURS = 6;

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }

    /** The scheduled retry's own toggle (the switch must be on too: scheduledRetryOn()). */
    public function attachRetryEnabled(): bool
    {
        return $this->settings->getBool(self::ATTACH_RETRY_ENABLED, true);
    }

    public function scheduledRetryOn(): bool
    {
        return $this->enabled() && $this->attachRetryEnabled();
    }

    public function maxAttempts(): int
    {
        return max(1, min(self::MAX_MAX_ATTEMPTS, $this->int(self::ATTACH_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS)));
    }

    public function retryDelayMinutes(): int
    {
        return max(1, min(self::MAX_RETRY_DELAY_MINUTES, $this->int(self::ATTACH_RETRY_DELAY_MINUTES, self::DEFAULT_RETRY_DELAY_MINUTES)));
    }

    /**
     * When a row that has now failed $attempts times may be tried again: the first delay after the first failure,
     * doubled after each further one, at most MAX_DELAY_HOURS. NULL once $attempts reaches maxAttempts(): the scheduled
     * retry gives up ("Retry attach" on the admin page still works).
     */
    public function nextAttemptAt(int $attempts, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return self::backoff($attempts, $this->maxAttempts(), $this->retryDelayMinutes(), $now);
    }

    public static function backoff(int $attempts, int $maxAttempts, int $delayMinutes, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($attempts >= $maxAttempts) {
            return null;
        }
        $first = 60 * $delayMinutes;
        $cap = max($first, 3600 * self::MAX_DELAY_HOURS);
        $seconds = $first;
        for ($n = 1; $n < $attempts && $seconds < $cap; ++$n) {
            $seconds *= 2;
        }

        return $now->modify(sprintf('+%d seconds', min($cap, $seconds)));
    }

    private function int(string $key, int $default): int
    {
        $value = trim((string) $this->settings->get($key, (string) $default));

        return 1 === preg_match('/^\d{1,6}$/D', $value) ? (int) $value : $default;
    }
}
