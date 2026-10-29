<?php

declare(strict_types=1);

namespace App\MiniApp\Reseller;

use App\MiniApp\Navigation\MiniAppAudience;
use App\MiniApp\Navigation\MiniAppSection;
use App\MiniApp\Navigation\MiniAppSectionProvider;

/**
 * The reseller entry of the Mini App (docs/design/reseller-and-claim.md, 6.2): one bottom-navigation item, shown only
 * to an active reseller while reseller.enabled is on (MiniAppAudience::RESELLER). Its screens (dashboard, new service,
 * sold services, customers, claim links) are reached from the dashboard and share this section's route default.
 */
final class ResellerSections implements MiniAppSectionProvider
{
    public function sections(): iterable
    {
        yield new MiniAppSection('reseller', 'nav.reseller', '🧑‍💼', 35, MiniAppAudience::RESELLER, route: 'miniapp_reseller');
    }
}
