<?php

declare(strict_types=1);

namespace App\Branding;

use App\Shared\Application\BrandDomain;
use App\Shared\Infrastructure\SettingValueProvider;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The switch "customer subscription pages on the brand domain": Setting brand.subscription_pages_on_brand, edited on the
 * web settings page, "branding" tab (SettingsAdministration::saveBranding()). Off by default.
 *
 * Fail-closed: only on AND with a valid brand domain (BrandDomain::isActive()) are the subscription page (/s/{token})
 * and the app import bridge (/s/{token}/open/{appId}) handed to customers on the brand base and served on the brand
 * host (BrandHostGuard). Otherwise everything is as before: the links are built on DEFAULT_URI and the brand host
 * answers 404 for them. The main host serves both pages whatever the switch says, so links already given out keep
 * working.
 *
 * Read at most once per request (reset() between requests and after a save).
 */
final class BrandSubscriptionPages implements ResetInterface
{
    public const ENABLED = 'brand.subscription_pages_on_brand';

    private ?bool $switchedOn = null;

    public function __construct(
        private readonly SettingValueProvider $settings,
        private readonly BrandDomain $brand,
    ) {
    }

    /** The stored switch alone (the settings form shows it as saved, brand domain or not). */
    public function switchedOn(): bool
    {
        return $this->switchedOn ??= $this->settings->getBool(self::ENABLED, false);
    }

    /** Whether the two pages live on the brand domain now: the switch is on and the brand domain is valid. */
    public function active(): bool
    {
        return $this->brand->isActive() && $this->switchedOn();
    }

    /** Whether BRAND_PUBLIC_BASE_URL is set and valid (the settings form says when the switch has no effect). */
    public function brandDomainActive(): bool
    {
        return $this->brand->isActive();
    }

    public function reset(): void
    {
        $this->switchedOn = null;
    }
}
