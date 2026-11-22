<?php

declare(strict_types=1);

namespace Amoobot\Plugin\FakeVpnDriver;

use App\Entity\VpnPanel;
use App\Module\Domain\CapabilitySet;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\PanelLinks;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\PanelLinkSourceDriverInterface;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverIdentity;
use App\Provisioning\Domain\VpnDriverInterface;

/**
 * A VPN driver package for tests only (docs/design/product-modules.md PM2b): its "panel" is an array in memory, shared
 * by every instance in the process. It has the base operations and panel_sub_links, the least a VPN driver package
 * must declare. Never installed outside tests.
 */
final class FakeVpnDriver implements VpnDriverInterface, PanelLinkSourceDriverInterface
{
    use VpnDriverIdentity;

    /** @var array<string, array{limit: ?int, expires: ?\DateTimeImmutable, enabled: bool, used: int}> */
    private static array $users = [];

    public function code(): string
    {
        return 'fake_vpn';
    }

    public function capabilities(): CapabilitySet
    {
        return VpnCapability::derive($this);
    }

    public function supports(?VpnPanel $panel): bool
    {
        return null === $panel || 'fake_vpn' === $panel->getType();
    }

    public function supportsProvisioning(?VpnPanel $panel): bool
    {
        return true;
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        $id = 'fake_'.bin2hex(random_bytes(6));
        self::$users[$id] = ['limit' => $request->trafficLimitGb, 'expires' => null, 'enabled' => true, 'used' => 1024];

        return new CreatedVpnService(
            remoteId: $id,
            username: $request->username,
            subscriptionUrl: null,
            configText: 'vless://'.$id.'@fake.invalid:443',
            clientUuid: $id,
            clientEmail: $request->username,
            subId: $id,
            configLinks: ['vless://'.$id.'@fake.invalid:443'],
        );
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $this->user($remoteId);
        self::$users[$remoteId]['enabled'] = false;
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        $this->user($remoteId);
        self::$users[$remoteId]['limit'] = $request->trafficLimitGb;
        self::$users[$remoteId]['expires'] = $request->expiresAt;
        self::$users[$remoteId]['enabled'] = true;
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
        unset(self::$users[$remoteId]);
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
        $this->user($remoteId);
        self::$users[$remoteId]['used'] = 0;
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        $user = $this->user($remoteId);

        return new VpnUsage(
            trafficUsedGb: intdiv($user['used'], 1024 ** 3),
            trafficLimitGb: $user['limit'],
            usedBytes: $user['used'],
            totalBytes: null === $user['limit'] ? null : $user['limit'] * 1024 ** 3,
            expiresAt: $user['expires'],
            isEnabled: $user['enabled'],
        );
    }

    public function panelLinks(string $remoteId, ?string $subId, ?VpnPanel $panel = null, bool $recordHealth = true): PanelLinks
    {
        $this->user($remoteId);

        return new PanelLinks(['vless://'.$remoteId.'@fake.invalid:443'], 0, 'links', null);
    }

    /**
     * @return array{limit: ?int, expires: ?\DateTimeImmutable, enabled: bool, used: int}
     */
    private function user(string $remoteId): array
    {
        return self::$users[$remoteId] ?? throw new \RuntimeException('Unknown fake VPN user.');
    }
}
