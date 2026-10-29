<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * "Reset my links" (claim links, D4) on a modern 3x-ui panel with Hosts (phase H0): after the rotation the service
 * stores exactly the panel's subLinks of the new credentials, nothing built from the inbound; a panel that cannot give
 * the links afterwards leaves no stale old-UUID link and tells the customer nothing about the panel. The daily limit
 * still applies.
 */
final class ClaimResetModernPanelTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;

    private const CLAIMER = '8701';
    private const UUID = '11111111-2222-4333-8444-555555555555';
    private const SUB_ID = 'claimsub01234567';

    private Fake3xuiPanel $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        $this->createUser(self::CLAIMER);
        $this->claimWith($this->makeLink()->token, self::CLAIMER);

        // The sold service now lives on a modern 3x-ui panel whose inbound has two hosts.
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setSubscriptionBaseUrl('https://sub.example.com')->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('ws')->setProtocol('vless')
            ->setHost('origin.example.com')->setPort(443)->setNetwork('tcp')->setSecurity('none');
        $this->em->persist($panel);
        $this->em->persist($inbound);
        $email = 'tg_claim_'.$this->sold->getId();
        $this->fake->clients[$email] = [
            'email' => $email, 'id' => self::UUID, 'subId' => self::SUB_ID, 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1], 'dbId' => 1,
        ];
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com']);
        $this->em->flush();
        $sold = $this->em->find(VpnService::class, $this->sold->getId()) ?? self::fail('service gone');
        $sold->setPanel($panel)->setInbound($inbound)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($panel->getId(), $inbound->getId(), '1', self::UUID, $email, self::SUB_ID))
            ->setClientUuid(self::UUID)->setClientEmail($email)->setSubId(self::SUB_ID)
            ->setConfigLinks(['vless://'.self::UUID.'@origin.example.com:443?type=tcp#old'])->setConfigText('old');
        $this->em->flush();
        $this->sold = $sold;
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testTheResetStoresThePanelsLinksOfTheNewCredentialsOnly(): void
    {
        [$outcome] = $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());

        self::assertSame(ServiceOwnership::RESET_DONE, $outcome);
        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertNotSame(self::UUID, $row['client_uuid']);
        $links = json_decode((string) $row['config_links'], true);
        self::assertCount(2, $links, 'one per host');
        foreach ($links as $link) {
            self::assertStringContainsString((string) $row['client_uuid'], $link);
            self::assertStringNotContainsString('origin.example.com', $link, 'nothing built from the inbound');
            self::assertStringNotContainsString(self::UUID, $link, 'no link of the old credentials');
        }
        self::assertSame(implode("\n", $links), $row['config_text']);
    }

    public function testAPanelThatCannotGiveTheLinksAfterTheResetLeavesNoStaleLinkAndNoPanelDetailForTheCustomer(): void
    {
        $this->resetHttp();
        // Every subLinks call fails from here on: the rotation's read-back and the regeneration after it.
        $this->failSubLinks();
        $this->pressButton(self::CLAIMER, 'cl_rst_ok:'.$this->sold->getId().':'.$this->nonce());

        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertNotSame(self::UUID, $row['client_uuid'], 'the reset was applied');
        self::assertStringNotContainsString(self::UUID, (string) $row['config_links'], 'the old credentials\' links are gone');
        self::assertStringNotContainsString('origin.example.com', (string) $row['config_links']);
        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsString('panel.example.test', $text);
            self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $text);
            self::assertStringNotContainsString('/secret/', $text);
            self::assertStringNotContainsString('unauthorized', $text);
        }
        self::assertTrue($this->sentTextContains($this->botText('claim.reset_done', ['service' => ['id' => $this->sold->getId()]])));
    }

    public function testTheDailyLimitStillApplies(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            [$outcome] = $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
            self::assertSame(ServiceOwnership::RESET_DONE, $outcome);
        }
        try {
            $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
            self::fail('a fourth reset');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::RATE_LIMITED, $e->error);
        }
    }

    private function failSubLinks(): void
    {
        $fake = $this->fake;
        $this->http->on('panel.example.test', static function (string $method, string $url, array $options) use ($fake) {
            if (str_contains($url, '/clients/subLinks/')) {
                return Fake3xuiPanel::envelope(false, null, 'unauthorized', 401);
            }

            return $fake($method, $url, $options);
        });
    }

    private function ownership(): ServiceOwnership
    {
        return self::getContainer()->get(ServiceOwnership::class);
    }

    private function nonce(): string
    {
        $this->em->clear();
        $service = $this->em->find(VpnService::class, $this->sold->getId()) ?? self::fail('service gone');

        return $this->ownership()->resetNonce($service);
    }
}
