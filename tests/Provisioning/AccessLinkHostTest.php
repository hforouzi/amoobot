<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Domain\PublicUrlPolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The shop reaches the panel through http://127.0.0.1:2096; that address must never end up in a customer's config
 * link or subscription URL.
 */
final class AccessLinkHostTest extends KernelTestCase
{
    public function testALoopbackPanelAddressIsNotUsedAsTheServerHost(): void
    {
        $links = $this->generate((new VpnPanel())->setBaseUrl('http://127.0.0.1:2096'));

        self::assertSame([], $links['configLinks'], 'no link pointing at 127.0.0.1');
        self::assertContains('host', $links['missing']);
        self::assertNull($links['subscriptionUrl'], 'no subscription URL derived from the panel address');
    }

    public function testAPrivatePanelAddressIsNotUsedEither(): void
    {
        $links = $this->generate((new VpnPanel())->setBaseUrl('https://192.168.1.20:2053/panel'));

        self::assertSame([], $links['configLinks']);
        self::assertNull($links['subscriptionUrl']);
    }

    public function testThePublicHostIsUsedWhenTheBaseUrlIsLoopback(): void
    {
        $links = $this->generate((new VpnPanel())->setBaseUrl('http://127.0.0.1:2096')->setPublicHost('edge.example.com'));

        self::assertCount(1, $links['configLinks']);
        self::assertSame('edge.example.com', PublicUrlPolicy::linkHost($links['configLinks'][0]));
    }

    public function testAPublicPanelAddressIsStillAFallback(): void
    {
        $links = $this->generate((new VpnPanel())->setBaseUrl('https://panel.example.com:2053'));

        self::assertCount(1, $links['configLinks']);
        self::assertSame('panel.example.com', PublicUrlPolicy::linkHost($links['configLinks'][0]));
    }

    public function testTheSubscriptionBaseUrlBuildsTheUrl(): void
    {
        $links = $this->generate((new VpnPanel())->setBaseUrl('http://127.0.0.1:2096')->setSubscriptionBaseUrl('https://sub.example.com:2096/'));

        self::assertSame('https://sub.example.com:2096/sub/sub123', $links['subscriptionUrl']);
    }

    /**
     * @return array{subscriptionUrl: ?string, configLinks: array<int, string>, missing: array<int, string>}
     */
    private function generate(VpnPanel $panel): array
    {
        $panel->setType('test')->setTitle('Panel');
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('In')->setProtocol('vless')->setPort(443);
        $service = (new VpnService())->setPanel($panel)->setInbound($inbound)->setClientUuid('b831381d-6324-4d53-ad4f-8cda48b30811')
            ->setClientEmail('c@shop')->setUsername('c')->setSubId('sub123');

        return self::getContainer()->get(VpnAccessLinkGenerator::class)->generate($service);
    }
}
