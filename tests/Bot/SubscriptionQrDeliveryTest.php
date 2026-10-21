<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\VpnService;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;

/**
 * "Subscription QR" in the bot: a short subscription URL is sent as a photo; a long config link (when there is no
 * subscription URL) as an uncompressed document, since Telegram's photo recompression makes dense codes unreadable.
 */
final class SubscriptionQrDeliveryTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const CUSTOMER = '7801';
    private const LONG_LINK = 'vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge-de-01.example-shop.com:443?type=tcp&security=reality&pbk=Z84J2IelR9ch3k8VtlVhhs5ycBUlXA7wHBWcBrjqnAw&fp=chrome&sni=www.speedtest.net&sid=a1b2c3d4e5f60718&spx=%2F&flow=xtls-rprx-vision&encryption=none#Germany-01';

    public function testAShortSubscriptionUrlIsSentAsAPhoto(): void
    {
        $service = $this->service('https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a', []);

        $this->pressButton(self::CUSTOMER, 'service_subscription_qr:'.$service->getId());

        self::assertCount(1, $this->http->requests('/sendPhoto'));
        self::assertCount(0, $this->http->requests('/sendDocument'));
    }

    public function testALongConfigLinkIsSentAsADocument(): void
    {
        $service = $this->service(null, [self::LONG_LINK]);

        $this->pressButton(self::CUSTOMER, 'service_subscription_qr:'.$service->getId());

        self::assertCount(1, $this->http->requests('/sendDocument'));
        self::assertCount(0, $this->http->requests('/sendPhoto'));
        self::assertSame([], glob(self::getContainer()->getParameter('kernel.project_dir').'/var/cache/qr/service-'.$service->getId().'-*') ?: [], 'temporary image removed');
    }

    /**
     * @param list<string> $links
     */
    private function service(?string $subscriptionUrl, array $links): VpnService
    {
        $service = $this->createActiveService($this->createUser(self::CUSTOMER), $this->createTestInbound())
            ->setSubscriptionUrl($subscriptionUrl)
            ->setSubId('k3j9x7q2m1zt5w8a')
            ->setConfigLinks($links)
            ->setConfigText([] !== $links ? implode("\n", $links) : null);
        $this->em->flush();
        $this->http->reset();

        return $service;
    }
}
