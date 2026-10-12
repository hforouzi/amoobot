<?php

declare(strict_types=1);

namespace App\Payment\Application\Repair;

use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;

/**
 * Compares a service's CURRENT panel state with what the database says, before a renewal or
 * add-traffic order is offered for retry. A retry renews from the database values, so retrying a
 * renewal that already reached the panel would extend the service twice.
 *
 *   UNCHANGED: panel expiry and traffic limit equal the database -> the renewal was not applied
 *   AHEAD:     panel expiry later or traffic limit higher -> it may have been applied; never retry
 *   UNKNOWN:   panel unreachable or does not report the needed values; never retry
 */
class RenewalPanelComparator
{
    /** Panel and database timestamps are synced at different times; a renewal adds at least a day. */
    private const EXPIRY_TOLERANCE_SECONDS = 12 * 3600;
    private const BYTES_PER_GB = 1073741824;

    public function __construct(private readonly VpnPanelDriverRegistry $driverRegistry)
    {
    }

    public function compare(VpnService $service, bool $compareExpiry): PanelStateComparison
    {
        $remoteId = (string) ($service->getRemoteId() ?? '');
        if ('' === $remoteId) {
            return new PanelStateComparison(PanelStateComparison::UNKNOWN, 'service has no remote id');
        }

        try {
            $usage = $this->driverRegistry->resolve($service->getPanel())->getUsage($remoteId, $service->getPanel());
        } catch (\Throwable $e) {
            return new PanelStateComparison(PanelStateComparison::UNKNOWN, sprintf('panel not readable (%s)', (new \ReflectionClass($e))->getShortName()));
        }

        $panelLimit = $this->panelLimitBytes($usage);
        $dbLimit = $service->getTrafficLimitBytes() ?? (null !== $service->getTrafficLimitGb() ? $service->getTrafficLimitGb() * self::BYTES_PER_GB : null);
        $snapshot = sprintf(
            'panel: expires %s, limit %s; database: expires %s, limit %s',
            $usage->expiresAt?->format(\DATE_ATOM) ?? 'n/a',
            null !== $panelLimit ? $this->gb($panelLimit) : 'n/a',
            $service->getExpiresAt()?->format(\DATE_ATOM) ?? 'n/a',
            null !== $dbLimit ? $this->gb($dbLimit) : 'n/a',
        );

        if (null === $panelLimit || null === $dbLimit) {
            return new PanelStateComparison(PanelStateComparison::UNKNOWN, 'traffic limit not comparable; '.$snapshot);
        }
        if ($panelLimit > $dbLimit) {
            return new PanelStateComparison(PanelStateComparison::AHEAD, 'panel traffic limit is higher; '.$snapshot);
        }
        if ($panelLimit < $dbLimit) {
            return new PanelStateComparison(PanelStateComparison::UNKNOWN, 'panel traffic limit is lower than the database; '.$snapshot);
        }

        if ($compareExpiry) {
            $panelExpiry = $usage->expiresAt;
            $dbExpiry = $service->getExpiresAt();
            if (null === $panelExpiry || null === $dbExpiry) {
                if (null !== $panelExpiry || null !== $dbExpiry) {
                    return new PanelStateComparison(PanelStateComparison::UNKNOWN, 'expiry not comparable; '.$snapshot);
                }
            } else {
                $difference = $panelExpiry->getTimestamp() - $dbExpiry->getTimestamp();
                if ($difference > self::EXPIRY_TOLERANCE_SECONDS) {
                    return new PanelStateComparison(PanelStateComparison::AHEAD, 'panel expiry is later; '.$snapshot);
                }
                if ($difference < -self::EXPIRY_TOLERANCE_SECONDS) {
                    return new PanelStateComparison(PanelStateComparison::UNKNOWN, 'panel expiry is earlier than the database; '.$snapshot);
                }
            }
        }

        return new PanelStateComparison(PanelStateComparison::UNCHANGED, $snapshot);
    }

    private function panelLimitBytes(VpnUsage $usage): ?int
    {
        if (null !== $usage->totalBytes && $usage->totalBytes > 0) {
            return $usage->totalBytes;
        }

        return null !== $usage->trafficLimitGb && $usage->trafficLimitGb > 0 ? $usage->trafficLimitGb * self::BYTES_PER_GB : null;
    }

    private function gb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / self::BYTES_PER_GB, 2, '.', ''), '0'), '.').' GB';
    }
}
