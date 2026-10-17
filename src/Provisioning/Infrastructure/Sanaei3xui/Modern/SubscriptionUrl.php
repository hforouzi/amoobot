<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnPanel;

/**
 * The customer's subscription URL: <subscription base URL><path prefix, default /sub>/<subId>, with the same rules as
 * the legacy driver (Sanaei3xuiDriver::buildSubscriptionUrl), so both drivers hand out identical URLs.
 */
final class SubscriptionUrl
{
    public static function build(VpnPanel $panel, string $subId): ?string
    {
        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $base = rtrim(trim((string) ($config['subscription_base_url'] ?? $panel->getSubscriptionBaseUrl() ?? '')), '/');
        if ('' === trim($subId) || '' === $base || false === filter_var($base, FILTER_VALIDATE_URL)) {
            return null;
        }
        $prefix = trim((string) ($config['subscription_path_prefix'] ?? ''));
        $prefix = '/'.trim('' === $prefix ? '/sub' : $prefix, '/');
        $url = sprintf('%s%s/%s', $base, $prefix, rawurlencode($subId));

        return preg_replace('#(?<!:)/{2,}#', '/', $url) ?? $url;
    }
}
