<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Bot\Domain\BotMessageDirection;
use App\Entity\AdminUser;
use App\Entity\BotMessageLog;
use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use App\Entity\OrderDraft;
use App\Entity\ServiceNotificationLog;
use App\Entity\VpnPanel;
use App\Payment\Domain\PaymentGatewayType;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Detail pages of entities with JSON (array) properties render (no 500), show the data pretty-printed and mask
 * sensitive keys; their edit pages still load.
 */
final class JsonDetailPagesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('json@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM discount_code WHERE code = 'JSONTEST'");
        parent::tearDown();
    }

    public function testOrderWithBotMetadata(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $order->setMetadata([
            'finalUsername' => 'shop_user_1042',
            'trafficGb' => 50,
            'durationDays' => 30,
            'source' => 'telegram',
            'discount' => ['code' => 'NOWRUZ', 'amount' => 15000],
            'payment_token' => 'tok-should-not-show-81f2',
            'request' => ['headers' => ['Authorization' => 'Bearer hdr-should-not-show-77aa']],
        ]);
        $this->em->flush();

        $html = $this->detail('/admin/order/'.$order->getId());
        self::assertStringContainsString('shop_user_1042', $html);
        self::assertStringContainsString('NOWRUZ', $html);
        self::assertStringContainsString('payment_token', $html, 'the key is shown, only its value is masked');
        self::assertStringNotContainsString('tok-should-not-show-81f2', $html);
        self::assertStringNotContainsString('hdr-should-not-show-77aa', $html);
        $this->assertEditLoads('/admin/order/'.$order->getId());
    }

    public function testBotMessageLogWithATelegramUpdate(): void
    {
        $log = (new BotMessageLog())->setDirection(BotMessageDirection::INCOMING)->setTelegramId('42')->setUpdateType('message')->setPayload([
            'update_id' => 900001,
            'message' => ['message_id' => 7, 'text' => 'سلام، سرویس می‌خواهم', 'from' => ['id' => 42, 'username' => 'buyer_42'], 'chat' => ['id' => 42, 'type' => 'private']],
            'bot_token' => '123456:bot-token-should-not-show',
        ]);
        $this->em->persist($log);
        $this->em->flush();

        $html = $this->detail('/admin/logs/bot-messages/'.$log->getId());
        self::assertStringContainsString('buyer_42', $html);
        self::assertStringContainsString('سلام، سرویس می‌خواهم', $html);
        self::assertStringNotContainsString('bot-token-should-not-show', $html);
        $this->assertEditLoads('/admin/logs/bot-messages/'.$log->getId());
    }

    public function testServiceNotificationLogWithAPayload(): void
    {
        $user = $this->createUser();
        $service = $this->createActiveService($user, $this->createTestInbound());
        $log = (new ServiceNotificationLog())->setService($service)->setUser($user)->setType('expiry')->setKeyName('expiry_3d')
            ->setSentAt(new \DateTimeImmutable())->setPayload(['daysLeft' => 3, 'expiresAt' => '2026-11-01', 'api_key' => 'ak-should-not-show-4c']);
        $this->em->persist($log);
        $this->em->flush();

        $html = $this->detail('/admin/logs/notifications/'.$log->getId());
        self::assertStringContainsString('daysLeft', $html);
        self::assertStringContainsString('2026-11-01', $html);
        self::assertStringNotContainsString('ak-should-not-show-4c', $html);
        $this->assertEditLoads('/admin/logs/notifications/'.$log->getId());
    }

    public function testPaymentWithRawGatewayData(): void
    {
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()));
        $payment->setRequestPayload(['amount' => 100000, 'api_key' => 'pay-key-should-not-show'])
            ->setCallbackPayload(['trackId' => 'TRK-55', 'status' => 2])
            ->setVerifyPayload(['result' => 100, 'cardNumber' => '6037****1234'])
            ->setIpnPayload(['payment_status' => 'finished', 'ipn_secret' => 'ipn-should-not-show']);
        $this->em->flush();

        $html = $this->detail('/admin/payment/'.$payment->getId());
        self::assertStringNotContainsString('pay-key-should-not-show', $html);
        self::assertStringNotContainsString('ipn-should-not-show', $html);
        $this->assertEditLoads('/admin/payment/'.$payment->getId());
    }

    public function testOrderDraftAndDiscountUsageWithJson(): void
    {
        $user = $this->createUser();
        $plan = $this->createPlan();
        $draft = (new OrderDraft())->setUser($user)->setPlan($plan)->setStatus('active')->setStep('traffic')
            ->setData(['navigation' => ['history' => ['plan', 'traffic']], 'customTrafficGb' => 75])
            ->setPriceSnapshot(['base' => 100000, 'final' => 85000]);
        $code = (new DiscountCode())->setCode('JSONTEST')->setType(DiscountCode::TYPE_PERCENT)->setValue(10);
        $usage = (new DiscountUsage())->setDiscountCode($code)->setUser($user)->setAmountBefore(100000)->setDiscountAmount(10000)
            ->setAmountAfter(90000)->setUsedAt(new \DateTimeImmutable())->setMetadata(['orderType' => 'new_service', 'session_token' => 'sess-should-not-show']);
        foreach ([$draft, $code, $usage] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $html = $this->detail('/admin/order-draft/'.$draft->getId());
        self::assertStringContainsString('customTrafficGb', $html);
        $this->assertEditLoads('/admin/order-draft/'.$draft->getId());

        $html = $this->detail('/admin/discount-usage/'.$usage->getId());
        self::assertStringContainsString('new_service', $html);
        self::assertStringNotContainsString('sess-should-not-show', $html);
        $this->assertEditLoads('/admin/discount-usage/'.$usage->getId());
    }

    public function testConfigDisplaysMaskSecrets(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Config panel')->setBaseUrl('https://panel.example.test:2053/');
        $panel->setConfig(['timeout' => 20, 'api_token' => 'legacy-plain-token-should-not-show', 'proxy_password' => 'proxy-pass-should-not-show']);
        $this->em->persist($panel);
        $inbound = $this->createTestInbound();
        $inbound->setConfig(['streamSettings' => ['realitySettings' => ['privateKey' => 'reality-private-should-not-show', 'shortIds' => ['ab12']]]]);
        $gateway = $this->createGateway(PaymentGatewayType::CUSTOM_API, ['create_url' => 'https://pay.example.test/create', 'api_key' => 'custom-key-should-not-show']);
        $this->em->flush();

        $html = $this->detail('/admin/vpn-panel/'.$panel->getId());
        self::assertStringContainsString('timeout', $html);
        self::assertStringNotContainsString('legacy-plain-token-should-not-show', $html);
        self::assertStringNotContainsString('proxy-pass-should-not-show', $html);

        $html = $this->detail('/admin/vpn-inbound/'.$inbound->getId());
        self::assertStringContainsString('ab12', $html);
        self::assertStringNotContainsString('reality-private-should-not-show', $html);

        $html = $this->detail('/admin/custom-api-payment-gateway/'.$gateway->getId());
        self::assertStringContainsString('https://pay.example.test/create', $html);
        self::assertStringNotContainsString('custom-key-should-not-show', $html);
    }

    private function detail(string $path): string
    {
        $this->client->request('GET', $path);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'GET '.$path);

        return (string) $response->getContent();
    }

    private function assertEditLoads(string $path): void
    {
        $this->client->request('GET', $path.'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET '.$path.'/edit');
    }
}
