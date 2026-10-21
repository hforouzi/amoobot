<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\PublicUrlPolicy;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\SubscriptionUrl;

/**
 * Whether the panel hands customers a subscription URL their phone can reach. It is built from the "Subscription base
 * URL" only (never from the address the bot uses to reach the panel, often http://127.0.0.1:2096); shown by
 * app:panel:doctor and on the panel page.
 */
final class PanelSubscriptionUrlCheck
{
    public const MISSING = 'missing';
    public const NOT_PUBLIC = 'not_public';
    private const SAMPLE_SUB_ID = '<subId>';

    /** null when fine, else MISSING / NOT_PUBLIC. */
    public static function problem(VpnPanel $panel): ?string
    {
        $url = self::sampleUrl($panel);
        if (null === $url) {
            return self::MISSING;
        }

        return PublicUrlPolicy::isPubliclyUsableUrl($url) ? null : self::NOT_PUBLIC;
    }

    /** A customer's subscription URL on this panel, with "<subId>" for the id; null when no base is set. */
    public static function sampleUrl(VpnPanel $panel): ?string
    {
        $url = SubscriptionUrl::build($panel, 'SAMPLESUBID');

        return null === $url ? null : str_replace('SAMPLESUBID', self::SAMPLE_SUB_ID, $url);
    }
}
