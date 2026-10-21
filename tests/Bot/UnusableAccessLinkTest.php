<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\VpnService;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;

/**
 * A subscription URL or config link a phone cannot reach (127.0.0.1, a private address, ...) is never sent to the
 * customer: they are told to contact support and the admins are alerted, once per service.
 */
final class UnusableAccessLinkTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const CUSTOMER = '7901';
    private const LOOPBACK_URL = 'http://127.0.0.1:2096/sub/k3j9x7q2m1zt5w8a';
    private const PUBLIC_URL = 'https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a';
    private const PUBLIC_LINK = 'vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example-shop.com:443?type=tcp&security=none#shop';
    private const LOOPBACK_LINK = 'vless://b831381d-6324-4d53-ad4f-8cda48b30811@127.0.0.1:443?type=tcp&security=none#shop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
    }

    public function testALoopbackSubscriptionUrlIsWithheldAndAdminsAlertedOnce(): void
    {
        $service = $this->service(self::LOOPBACK_URL, []);

        $this->pressButton(self::CUSTOMER, 'service_subscription:'.$service->getId());

        $this->assertNothingSentContains('127.0.0.1');
        self::assertTrue($this->customerToldToContactSupport());
        self::assertCount(1, $this->alerts($service), 'admins alerted');
        self::assertStringContainsString('Subscription base URL', $this->alerts($service)[0]);

        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'service_subscription:'.$service->getId());
        self::assertTrue($this->customerToldToContactSupport());
        self::assertCount(0, $this->alerts($service), 'alerted once per service');
    }

    public function testNoQrIsMadeOfALoopbackSubscriptionUrl(): void
    {
        $service = $this->service(self::LOOPBACK_URL, []);

        $this->pressButton(self::CUSTOMER, 'service_subscription_qr:'.$service->getId());

        self::assertCount(0, $this->http->requests('/sendPhoto'));
        self::assertCount(0, $this->http->requests('/sendDocument'));
        $this->assertNothingSentContains('127.0.0.1');
        self::assertTrue($this->customerToldToContactSupport());
        self::assertCount(1, $this->alerts($service));
    }

    public function testUsableLinksAreStillSentWithoutTheUnusableSubscriptionUrl(): void
    {
        $service = $this->service(self::LOOPBACK_URL, [self::PUBLIC_LINK]);

        $this->pressButton(self::CUSTOMER, 'service_config_links:'.$service->getId());

        $this->assertNothingSentContains('127.0.0.1');
        self::assertTrue($this->sentTextContains('edge.example-shop.com'), 'the working config link is delivered');
        self::assertTrue($this->customerToldToContactSupport(), 'and the missing subscription link explained');
        self::assertCount(1, $this->alerts($service));
    }

    public function testALoopbackConfigLinkIsDropped(): void
    {
        $service = $this->service(self::PUBLIC_URL, [self::LOOPBACK_LINK]);

        $this->pressButton(self::CUSTOMER, 'service_subscription:'.$service->getId());

        $this->assertNothingSentContains('127.0.0.1');
        self::assertTrue($this->sentTextContains(self::PUBLIC_URL));
        self::assertCount(1, $this->alerts($service));
    }

    public function testTheServiceDetailDoesNotShowIt(): void
    {
        $service = $this->service(self::LOOPBACK_URL, []);

        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        $this->assertNothingSentContains('127.0.0.1');
        self::assertTrue($this->customerToldToContactSupport());
    }

    public function testAWorkingServiceRaisesNoAlert(): void
    {
        $service = $this->service(self::PUBLIC_URL, [self::PUBLIC_LINK]);

        $this->pressButton(self::CUSTOMER, 'service_subscription:'.$service->getId());
        $this->pressButton(self::CUSTOMER, 'service_subscription_qr:'.$service->getId());

        self::assertTrue($this->sentTextContains(self::PUBLIC_URL));
        self::assertCount(1, $this->http->requests('/sendPhoto'));
        self::assertFalse($this->customerToldToContactSupport());
        self::assertCount(0, $this->alerts($service));
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

    private function assertNothingSentContains(string $needle): void
    {
        foreach ($this->requestsTo(self::CUSTOMER) as $body) {
            self::assertStringNotContainsString($needle, (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private function customerToldToContactSupport(): bool
    {
        foreach ($this->requestsTo(self::CUSTOMER) as $body) {
            if (str_contains((string) ($body['text'] ?? ''), 'پشتیبانی')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin alerts (to TELEGRAM_ADMIN_CHAT_ID) about this service since the last reset.
     *
     * @return list<string>
     */
    private function alerts(VpnService $service): array
    {
        $alerts = [];
        foreach ($this->requestsTo(self::ENV_ADMIN) as $body) {
            $text = (string) ($body['text'] ?? '');
            if (str_contains($text, 'سرویس #'.$service->getId())) {
                $alerts[] = $text;
            }
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestsTo(string $chatId): array
    {
        $bodies = [];
        foreach ($this->http->requests('api.telegram.org') as $request) {
            $body = $this->requestBody($request);
            if ((string) ($body['chat_id'] ?? '') === $chatId) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }
}
