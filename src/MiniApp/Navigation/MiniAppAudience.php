<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

/**
 * Who a Mini App section is for. A reseller audience is added here when resellers exist (and MiniAppSections learns
 * how to recognise one); sections then only name it.
 */
enum MiniAppAudience: string
{
    /** Everyone signed in (every admin is also a customer). */
    case CUSTOMER = 'customer';
    /** An active admin (AdminAccessPolicy::botActor) with at least one permission, plus the section's own anyOf. */
    case ADMIN = 'admin';
}
