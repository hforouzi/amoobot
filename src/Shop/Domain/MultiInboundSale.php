<?php

declare(strict_types=1);

namespace App\Shop\Domain;

use App\Entity\VpnInbound;

/**
 * The one sale rule for plans with additional inbounds (docs/design/multi-inbound-plans.md 4, D3; M2c F1): sellable only
 * with plans.multi_inbound_enabled on AND every inbound (primary and additional) active AND on the primary's panel AND
 * that panel 3x-ui in modern mode. Without additional inbounds there is nothing to decide here (null).
 *
 * Pure: Plan::multiInboundSaleBlocker() asks it for a plan, PlanSaleRule for an order's snapshot. The switch may be a
 * closure, read only when a plan has additional inbounds.
 */
final class MultiInboundSale
{
    public const SWITCHED_OFF = 'switched_off';
    public const NO_PRIMARY = 'no_primary';
    public const INACTIVE_INBOUND = 'inactive_inbound';
    public const OTHER_PANEL = 'other_panel';
    public const NOT_MODERN = 'not_modern';

    /**
     * @param list<VpnInbound>       $additional
     * @param bool|\Closure(): bool $enabled    plans.multi_inbound_enabled
     *
     * @return self::*|null why such a plan is not sold, or null
     */
    public static function blocker(?VpnInbound $primary, array $additional, bool|\Closure $enabled): ?string
    {
        if ([] === $additional) {
            return null;
        }
        if (!($enabled instanceof \Closure ? $enabled() : $enabled)) {
            return self::SWITCHED_OFF;
        }
        if (null === $primary) {
            return self::NO_PRIMARY;
        }
        $panel = $primary->getPanel();
        foreach ([$primary, ...$additional] as $inbound) {
            if (!$inbound->isActive()) {
                return self::INACTIVE_INBOUND;
            }
            $other = $inbound->getPanel();
            if ($other !== $panel && (null === $other->getId() || $other->getId() !== $panel->getId())) {
                return self::OTHER_PANEL;
            }
        }

        return 'sanaei_3xui' === $panel->getType() && $panel->isModern() ? null : self::NOT_MODERN;
    }
}
