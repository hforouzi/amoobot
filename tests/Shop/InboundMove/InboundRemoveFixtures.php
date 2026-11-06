<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;

/**
 * kind=remove (docs/design/plan-inbound-add-remove.md, PR P11c): plan "Gold" sold DE-1 (remote 1) and NL-2 (remote 2) as an
 * additional inbound, its services' clients are on both, and the plan no longer sells NL-2: what an owner has after
 * taking NL-2 off the plan.
 *
 * @mixin \App\Tests\Support\IntegrationTestCase
 */
trait InboundRemoveFixtures
{
    use InboundAddFixtures;

    private function setUpRemove(bool $multiOn = true): void
    {
        $this->setUpAdd($multiOn);
        $this->sellNoMore();
    }

    /** A service of Gold whose client is on DE-1 and NL-2, with its row for NL-2. */
    private function onBoth(int $n, string $status = 'active', string $rowStatus = 'attached'): VpnService
    {
        $service = $this->goldService($n, $status);
        $this->fake->clients['tg_p1_'.$n]['inboundIds'] = [1, 2];
        $this->rowFor($service, $rowStatus, ['attempts' => 0, 'last_error' => null, 'attached_at' => '2026-01-01 00:00:00', 'checked_at' => '2026-01-01 00:00:00']);

        return $service;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string} exit code, output
     */
    private function remove(array $input = []): array
    {
        return $this->command(['--kind' => 'remove', '--inbound' => (string) $this->nl->getId()] + $input);
    }

    /** A planned remove run, without a pass (a test decides who runs it). */
    private function plannedRemove(?InboundMoveScope $scope = null, int $graceHours = 0): int
    {
        $this->em->clear();
        $created = self::getContainer()->get(InboundMovePlanner::class)->createRemove($this->managed($this->gold), $this->managed($this->nl), $scope ?? new InboundMoveScope(), $graceHours);
        self::assertIsArray($created, is_string($created) ? $created : '');
        $this->fake->requests = [];

        return $created[0];
    }

    /** @return list<string> the detach calls the fake received */
    private function detachCalls(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/detach')));
    }

    /** The guard's condition (FinalConfigLinkProvider::linksPending()): the stored links hide until the refresh. */
    private function guardOn(VpnService $service): bool
    {
        $row = $this->serviceRow((int) $service->getId());

        return null !== $row['inbound_changed_at'] && (null === $row['last_access_info_synced_at'] || $row['last_access_info_synced_at'] < $row['inbound_changed_at']);
    }

    private function inboundExists(VpnInbound $inbound): bool
    {
        return 1 === (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_inbound WHERE id = ?', [$inbound->getId()]);
    }
}
