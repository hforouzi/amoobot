<?php

declare(strict_types=1);

namespace App\Security;

/**
 * What an admin may do, in the web panel and in the bot. Which role has which permission: AdminRolePermissions.
 * The value is the security attribute (is_granted('PERM_ORDERS_VIEW'), MenuItem::setPermission(...)).
 */
enum AdminPermission: string
{
    case ORDERS_VIEW = 'PERM_ORDERS_VIEW';
    case ORDERS_MANAGE = 'PERM_ORDERS_MANAGE';
    case PAYMENTS_VIEW = 'PERM_PAYMENTS_VIEW';
    /** Approve, reject, retry provisioning, edit a payment. */
    case PAYMENTS_MODERATE = 'PERM_PAYMENTS_MODERATE';
    case CUSTOMERS_VIEW = 'PERM_CUSTOMERS_VIEW';
    case CUSTOMERS_MANAGE = 'PERM_CUSTOMERS_MANAGE';
    case SERVICES_VIEW = 'PERM_SERVICES_VIEW';
    /** Help a customer without changing what they paid for: resend config, refresh, sync usage. */
    case SERVICES_ASSIST = 'PERM_SERVICES_ASSIST';
    /** Change a service: suspend, activate, extend, add traffic, reset usage, start a renewal, edit it on the web. */
    case SERVICES_MANAGE = 'PERM_SERVICES_MANAGE';
    case SERVICES_DELETE = 'PERM_SERVICES_DELETE';
    /** Plans, trial plans and claims, bulk price adjustment. */
    case PLANS_MANAGE = 'PERM_PLANS_MANAGE';
    case DISCOUNTS_MANAGE = 'PERM_DISCOUNTS_MANAGE';
    case BROADCAST_SEND = 'PERM_BROADCAST_SEND';
    case BOT_TEXTS_MANAGE = 'PERM_BOT_TEXTS_MANAGE';
    /** Settings, renewal pricing / automation settings, required channels. */
    case SETTINGS_MANAGE = 'PERM_SETTINGS_MANAGE';
    /** Payment gateways and store payment methods. */
    case GATEWAYS_MANAGE = 'PERM_GATEWAYS_MANAGE';
    /** Panels and inbounds (including their sync/test/regenerate actions). */
    case PANELS_MANAGE = 'PERM_PANELS_MANAGE';
    case PLUGINS_MANAGE = 'PERM_PLUGINS_MANAGE';
    case ADMINS_MANAGE = 'PERM_ADMINS_MANAGE';
    /** Notification log, bot message log, admin audit log. */
    case LOGS_VIEW = 'PERM_LOGS_VIEW';
}
