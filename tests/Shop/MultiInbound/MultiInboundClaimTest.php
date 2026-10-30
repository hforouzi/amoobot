<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Reseller\Application\ServiceOwnership;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * Claim links on a service whose client is on several inbounds (phase M2a, design 5): the claim writes tgId/comment
 * into the one client record by email and "reset my links" gives that record new credentials; both keep every inbound,
 * and the reset stores the panel's links of all of them.
 */
final class MultiInboundClaimTest extends IntegrationTestCase
{
    use ClaimFixtures;

    private const CLAIMER = '8702';
    private const UUID = '11111111-2222-4333-8444-555555555555';
    private const SUB_ID = 'multisub01234567';

    private Fake3xuiPanel $fake;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        // The sold service lives on a modern panel, its client on inbounds 1 and 2 (a plan with an additional inbound).
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        foreach ([$panel, $primary, $vmess] as $entity) {
            $this->em->persist($entity);
        }
        $this->email = 'tg_claim_'.$this->sold->getId();
        $this->fake->clients[$this->email] = [
            'email' => $this->email, 'id' => self::UUID, 'subId' => self::SUB_ID, 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1, 2], 'dbId' => 1,
        ];
        $this->em->flush();
        $sold = $this->em->find(VpnService::class, $this->sold->getId()) ?? self::fail('service gone');
        $sold->setPanel($panel)->setInbound($primary)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($panel->getId(), $primary->getId(), '1', self::UUID, $this->email, self::SUB_ID))
            ->setClientUuid(self::UUID)->setClientEmail($this->email)->setSubId(self::SUB_ID);
        $this->em->persist((new VpnServiceInbound($sold, $vmess, new \DateTimeImmutable()))->recordAttempt(true, null, new \DateTimeImmutable()));
        $this->em->flush();
        $this->sold = $sold;
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testClaimingAndResettingLinksKeepEveryInbound(): void
    {
        $this->createUser(self::CLAIMER);
        [, $service] = $this->claimWith($this->makeLink()->token, self::CLAIMER);

        self::assertSame((int) self::CLAIMER, $this->fake->clients[$this->email]['tgId'], 'the claim wrote the new owner into the one record');
        self::assertSame([1, 2], $this->fake->clients[$this->email]['inboundIds']);

        $this->em->clear();
        $service = $this->em->find(VpnService::class, $service->getId()) ?? self::fail('service gone');
        $ownership = self::getContainer()->get(ServiceOwnership::class);
        [$outcome] = $ownership->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $service->getId(), $ownership->resetNonce($service));

        self::assertSame(ServiceOwnership::RESET_DONE, $outcome);
        $client = $this->fake->clients[$this->email];
        self::assertNotSame(self::UUID, $client['id']);
        self::assertSame([1, 2], $client['inboundIds'], 'new credentials on the same record, every inbound kept');
        $links = json_decode((string) $this->row('vpn_service', (int) $service->getId())['config_links'], true);
        self::assertCount(2, $links, 'the links of both inbounds, from the panel');
        foreach ($links as $link) {
            self::assertStringContainsString((string) $client['id'], $link);
        }
        self::assertSame(['attached'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]));
    }
}
