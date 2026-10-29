<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

/**
 * Who a Mini App section is for; sections only name it and MiniAppSections decides who is in it.
 */
enum MiniAppAudience: string
{
    /** Everyone signed in (every admin is also a customer). */
    case CUSTOMER = 'customer';
    /** An active admin (AdminAccessPolicy::botActor) with at least one permission, plus the section's own anyOf. */
    case ADMIN = 'admin';
    /**
     * An active reseller while reseller.enabled is on (MiniAppResellerAccess). Its routes answer 404 to anybody else,
     * so with resellers off nothing about them shows (MiniAppSectionGuard).
     */
    case RESELLER = 'reseller';
}
