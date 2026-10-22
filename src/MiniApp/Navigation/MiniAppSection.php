<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

use App\Security\AdminPermission;

/**
 * One area of the Mini App, as a MiniAppSectionProvider registers it. Its routes name it with the route default
 * "_miniapp_section" (MiniAppSectionGuard refuses them to anyone MiniAppSections::isAllowed() says no to).
 */
final class MiniAppSection
{
    /**
     * @param string                $label  translation key (miniapp domain)
     * @param int                   $order  position in the bottom navigation / parent's list, ascending
     * @param list<AdminPermission> $anyOf  needs at least one of these (empty: the audience alone decides)
     * @param string|null           $parent a top-level section key: listed on the parent's page, not in the bottom navigation
     * @param string|null           $route  where it opens; null: not built yet (shown as "coming soon")
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $icon,
        public readonly int $order,
        public readonly MiniAppAudience $audience = MiniAppAudience::CUSTOMER,
        public readonly array $anyOf = [],
        public readonly ?string $parent = null,
        public readonly ?string $route = null,
    ) {
    }
}
