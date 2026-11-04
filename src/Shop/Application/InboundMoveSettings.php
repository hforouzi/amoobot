<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The switch and limits for moving a plan's existing services to its new inbound (docs/design/plan-inbound-change.md
 * 8.1), Setting rows edited on the web settings page, "plans" tab (SETTINGS_MANAGE).
 *
 * PR P3: the keys only. Nothing reads them yet, so the switch has no effect in this release: no run can be created
 * and there is no runner. When the runner comes (P4/P6), with the switch off no run can be created and the runner
 * does nothing; primary moves do not need MultiInboundSettings::ENABLED.
 *
 * - detachGraceHours(): how long the old inbound stays on a moved client before it is removed (4.2, phase 2);
 * - batchSize(), pauseSeconds(): services per runner pass, and the pause between passes.
 *
 * Retries are not configured here: they reuse MultiInboundSettings::ATTACH_RETRY_ENABLED, ATTACH_MAX_ATTEMPTS and
 * ATTACH_RETRY_DELAY_MINUTES (5.4). A stored value that is not a whole number in range is ignored and the default is
 * used (fail closed); the settings form refuses such a value before it is stored.
 */
final class InboundMoveSettings
{
    public const ENABLED = 'plans.inbound_move_enabled';
    public const DETACH_GRACE_HOURS = 'plans.inbound_move_detach_grace_hours';
    public const BATCH_SIZE = 'plans.inbound_move_batch_size';
    public const PAUSE_SECONDS = 'plans.inbound_move_pause_seconds';

    public const DEFAULT_DETACH_GRACE_HOURS = 24;
    public const MIN_DETACH_GRACE_HOURS = 0;
    /** One week. */
    public const MAX_DETACH_GRACE_HOURS = 168;
    public const DEFAULT_BATCH_SIZE = 20;
    public const MIN_BATCH_SIZE = 1;
    public const MAX_BATCH_SIZE = 100;
    public const DEFAULT_PAUSE_SECONDS = 2;
    public const MIN_PAUSE_SECONDS = 0;
    public const MAX_PAUSE_SECONDS = 60;

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }

    public function detachGraceHours(): int
    {
        return $this->int(self::DETACH_GRACE_HOURS, self::DEFAULT_DETACH_GRACE_HOURS, self::MIN_DETACH_GRACE_HOURS, self::MAX_DETACH_GRACE_HOURS);
    }

    public function batchSize(): int
    {
        return $this->int(self::BATCH_SIZE, self::DEFAULT_BATCH_SIZE, self::MIN_BATCH_SIZE, self::MAX_BATCH_SIZE);
    }

    public function pauseSeconds(): int
    {
        return $this->int(self::PAUSE_SECONDS, self::DEFAULT_PAUSE_SECONDS, self::MIN_PAUSE_SECONDS, self::MAX_PAUSE_SECONDS);
    }

    /** Whether $value (as typed in the form) is a whole number from $min to $max. */
    public static function valid(string $value, int $min, int $max): bool
    {
        return 1 === preg_match('/^\d{1,3}$/D', $value) && (int) $value >= $min && (int) $value <= $max;
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = trim((string) $this->settings->get($key, (string) $default));

        return self::valid($value, $min, $max) ? (int) $value : $default;
    }
}
