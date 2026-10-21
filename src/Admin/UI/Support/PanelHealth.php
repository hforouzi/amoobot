<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

use App\Entity\VpnPanel;

/**
 * A panel's connection state as the admin shows it, from what the panel already records: the last connection test
 * (probe) and the degraded flag set after repeated call failures. Nothing is contacted here.
 */
final class PanelHealth
{
    public const CONNECTED = 'connected';
    public const DEGRADED = 'degraded';
    public const ERROR = 'error';
    public const UNTESTED = 'untested';

    private const BADGE = [self::CONNECTED => 'success', self::DEGRADED => 'warning', self::ERROR => 'danger', self::UNTESTED => 'secondary'];

    public static function of(VpnPanel $panel): string
    {
        return match (true) {
            $panel->isDegraded() => self::DEGRADED,
            null === $panel->getProbeStatus() => self::UNTESTED,
            'ok' === $panel->getProbeStatus() => self::CONNECTED,
            default => self::ERROR,
        };
    }

    public static function badge(string $state): string
    {
        return self::BADGE[$state] ?? 'secondary';
    }
}
