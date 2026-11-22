<?php

declare(strict_types=1);

namespace Amoobot\Plugin\FakeVpnDriver;

use App\Provisioning\Infrastructure\DummyVpnPanelDriver;

/**
 * Tests only: a driver package with neither panel_sub_links nor subscription_url (like the core 'dummy'), which a
 * package may not be (docs/design/product-modules.md 6.3, enforced for packages since PM2b). Used as mainClass by a
 * variant of the fake-vpn-driver manifest.
 */
final class NoLinksVpnDriver extends DummyVpnPanelDriver
{
    public function code(): string
    {
        return 'fake_vpn';
    }
}
