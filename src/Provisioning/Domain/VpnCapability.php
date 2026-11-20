<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Module\Domain\CapabilitySet;
use App\Module\Domain\CapabilityNotSupported;

/**
 * The VPN capability names of docs/design/product-modules.md 6.3 and the existing interface behind each one.
 *
 * A driver's capabilities are derived from the interfaces it implements (derive()), so the declared set is exactly
 * its instanceof set. Capabilities that have no interface or method in the code yet (subscription_url, client_import,
 * ip_limit, device_limit, on_hold, webhooks) are named here but no driver declares them: they become declarable in
 * the PR that adds their interface, never before.
 */
final class VpnCapability
{
    public const ADOPT_EXISTING = 'adopt_existing';
    public const RESUME = 'resume';
    public const TRAFFIC_ADD = 'traffic_add';
    public const TRAFFIC_RESET = 'traffic_reset';
    public const BATCH_USAGE = 'batch_usage';
    public const MULTI_INBOUND = 'multi_inbound';
    public const PER_USER_INBOUND_SELECTION = 'per_user_inbound_selection';
    public const INBOUND_MOVE = 'inbound_move';
    public const INBOUND_DETACH = 'inbound_detach';
    public const PANEL_SUB_LINKS = 'panel_sub_links';
    public const SUBSCRIPTION_URL = 'subscription_url';
    public const CREDENTIAL_ROTATION = 'credential_rotation';
    public const CLIENT_OWNER_NOTE = 'client_owner_note';
    public const CLIENT_IMPORT = 'client_import';
    public const IP_LIMIT = 'ip_limit';
    public const DEVICE_LIMIT = 'device_limit';
    public const ON_HOLD = 'on_hold';
    public const WEBHOOKS = 'webhooks';

    /** Every name of the 6.3 table, in its order. */
    public const ALL = [
        self::ADOPT_EXISTING, self::RESUME, self::TRAFFIC_ADD, self::TRAFFIC_RESET, self::BATCH_USAGE, self::MULTI_INBOUND,
        self::PER_USER_INBOUND_SELECTION, self::INBOUND_MOVE, self::INBOUND_DETACH, self::PANEL_SUB_LINKS,
        self::SUBSCRIPTION_URL, self::CREDENTIAL_ROTATION, self::CLIENT_OWNER_NOTE, self::CLIENT_IMPORT, self::IP_LIMIT,
        self::DEVICE_LIMIT, self::ON_HOLD, self::WEBHOOKS,
    ];

    /**
     * The interface that carries each capability today (6.3). traffic_reset is resetUsage() of the base interface, so
     * every VPN driver has it.
     *
     * @var array<string, class-string>
     */
    public const INTERFACES = [
        self::ADOPT_EXISTING => ExistingClientAwareDriverInterface::class,
        self::RESUME => ServiceActivationDriverInterface::class,
        self::TRAFFIC_ADD => TrafficLimitDriverInterface::class,
        self::TRAFFIC_RESET => VpnPanelDriverInterface::class,
        self::BATCH_USAGE => BatchUsageDriverInterface::class,
        self::MULTI_INBOUND => InboundAttachDriverInterface::class,
        self::PER_USER_INBOUND_SELECTION => InboundAttachDriverInterface::class,
        self::INBOUND_MOVE => InboundMoveDriverInterface::class,
        self::INBOUND_DETACH => InboundDetachDriverInterface::class,
        self::PANEL_SUB_LINKS => PanelLinkSourceDriverInterface::class,
        self::CREDENTIAL_ROTATION => CredentialRotationDriverInterface::class,
        self::CLIENT_OWNER_NOTE => ClientOwnerDriverInterface::class,
    ];

    private function __construct()
    {
    }

    /** The capabilities a driver has: one per mapped interface it implements. */
    public static function derive(object $driver): CapabilitySet
    {
        $names = [];
        foreach (self::INTERFACES as $capability => $interface) {
            if ($driver instanceof $interface) {
                $names[] = $capability;
            }
        }

        return CapabilitySet::of(...$names);
    }

    /**
     * Use of a capability: allowed only when the driver declares it.
     *
     * @throws CapabilityNotSupported
     */
    public static function require(VpnDriverInterface $driver, string $capability): void
    {
        $driver->capabilities()->require($capability, $driver->code());
    }
}
