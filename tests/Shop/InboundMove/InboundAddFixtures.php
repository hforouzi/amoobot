<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\VpnService;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Shop\Application\MultiInboundSettings;

/**
 * kind=add (docs/design/plan-inbound-add-remove.md, PR P11b): plan "Gold" sells DE-1 (remote 1) and NL-2 (remote 2) as
 * an additional inbound, and its services' clients are on DE-1 only: what an owner has after adding NL-2 to the plan.
 *
 * @mixin \App\Tests\Support\IntegrationTestCase
 */
trait InboundAddFixtures
{
    use InboundMoveFixtures;

    private function setUpAdd(bool $multiOn = true): void
    {
        $this->setUpMove();
        if ($multiOn) {
            $this->setting(MultiInboundSettings::ENABLED, 'true', 'boolean');
        }
        $this->sellAlso();
    }

    /** The plan now sells NL-2 as well (as the form would have saved it). */
    private function sellAlso(): void
    {
        $this->managed($this->gold)->setAdditionalInbounds([$this->managed($this->nl)]);
        $this->em->flush();
    }

    private function sellNoMore(): void
    {
        $this->managed($this->gold)->setAdditionalInbounds([]);
        $this->em->flush();
    }

    private function goldService(int $n, string $status = 'active'): VpnService
    {
        return $this->planService($this->gold, $this->de, $status, $n);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string} exit code, output
     */
    private function add(array $input = []): array
    {
        return $this->command(['--kind' => 'add', '--inbound' => (string) $this->nl->getId()] + $input);
    }

    /** A planned add run, without a pass (a test decides who runs it). */
    private function plannedAdd(?InboundMoveScope $scope = null): int
    {
        $this->em->clear();
        $created = self::getContainer()->get(InboundMovePlanner::class)->createAdd($this->managed($this->gold), $this->managed($this->nl), $scope ?? new InboundMoveScope());
        self::assertIsArray($created, is_string($created) ? $created : '');
        $this->fake->requests = [];

        return $created[0];
    }

    /** A row of the service for NL-2. */
    private function rowFor(VpnService $service, string $status, array $more = []): void
    {
        $this->em->getConnection()->insert('vpn_service_inbound', $more + [
            'service_id' => $service->getId(),
            'inbound_id' => $this->nl->getId(),
            'remote_inbound_id' => '2',
            'status' => $status,
            'attempts' => 1,
            'last_error' => 'inbound not found',
            'updated_at' => '2026-01-01 00:00:00',
            'reattach_count' => 0,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function nlRow(VpnService $service): ?array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM vpn_service_inbound WHERE service_id = ? AND inbound_id = ?', [$service->getId(), $this->nl->getId()]) ?: null;
    }

    /** @return list<int> the client's inbounds on the fake */
    private function onPanel(int $n): array
    {
        return $this->fake->clients['tg_p1_'.$n]['inboundIds'];
    }

    /**
     * What must never change on the client or the service (design 6.2).
     *
     * @return array<string, mixed>
     */
    private function identityOf(VpnService $service, int $n): array
    {
        $row = $this->serviceRow((int) $service->getId());
        $client = $this->fake->clients['tg_p1_'.$n];
        unset($client['inboundIds']);

        return [
            'client' => $client,
            'service' => array_intersect_key($row, array_flip(['inbound_id', 'remote_id', 'client_uuid', 'sub_id', 'client_email', 'public_token', 'expires_at', 'status', 'traffic_used_gb', 'traffic_limit_gb', 'traffic_used_bytes', 'traffic_limit_bytes', 'ip_limit', 'subscription_url'])),
        ];
    }
}
