<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

use App\Security\AdminPermission;

/**
 * The sections of this release: the customer's (with the shop, and orders under My services), and the admin entry
 * with its areas, which are listed as
 * "coming soon" (route null) until their screens are built; each then only gets a route here.
 */
final class CoreSections implements MiniAppSectionProvider
{
    public function sections(): iterable
    {
        yield new MiniAppSection('home', 'nav.home', '🏠', 10, route: 'miniapp_home');
        yield new MiniAppSection('shop', 'nav.shop', '🛒', 15, route: 'miniapp_shop');
        yield new MiniAppSection('services', 'nav.services_tab', '📦', 20, route: 'miniapp_services');
        yield new MiniAppSection('orders', 'nav.orders', '🧾', 25, parent: 'services', route: 'miniapp_orders');
        yield new MiniAppSection('apps', 'nav.apps', '📲', 30, route: 'miniapp_apps');
        yield new MiniAppSection('help', 'nav.help', '💬', 40, route: 'miniapp_help');

        yield new MiniAppSection('admin', 'nav.admin', '🛠', 90, MiniAppAudience::ADMIN, route: 'miniapp_admin');
        $areas = [
            ['admin.orders', '🧾', AdminPermission::ORDERS_VIEW, 'miniapp_admin_orders'],
            ['admin.payments', '💳', AdminPermission::PAYMENTS_VIEW, 'miniapp_admin_payments'],
            ['admin.customers', '👥', AdminPermission::CUSTOMERS_VIEW, 'miniapp_admin_customers'],
            ['admin.services', '📦', AdminPermission::SERVICES_VIEW, 'miniapp_admin_services'],
            ['admin.plans', '🏷', AdminPermission::PLANS_MANAGE, 'miniapp_admin_plans'],
            ['admin.discounts', '🎟', AdminPermission::DISCOUNTS_MANAGE, 'miniapp_admin_discounts'],
            ['admin.broadcast', '📣', AdminPermission::BROADCAST_SEND, 'miniapp_admin_broadcasts'],
            ['admin.panels', '🖥', AdminPermission::PANELS_MANAGE, 'miniapp_admin_panels'],
            ['admin.settings', '⚙️', AdminPermission::SETTINGS_MANAGE],
        ];
        foreach ($areas as $position => $area) {
            [$key, $icon, $permission] = $area;
            yield new MiniAppSection($key, 'area.'.substr($key, 6), $icon, 10 * ($position + 1), MiniAppAudience::ADMIN, [$permission], parent: 'admin', route: $area[3] ?? null);
        }
    }
}
