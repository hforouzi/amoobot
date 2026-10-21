<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Edit config" (the schema form in PaymentGatewayCrudController) overlays the submitted schema fields on the stored
 * config: keys the form does not show survive, a secret left empty keeps its stored value, a changed value is saved.
 */
final class PaymentGatewayConfigPreservationTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('config@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testZibalKeysOutsideTheSchemaSurviveAnEdit(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, [
            'merchant' => 'old-merchant',
            'sandbox' => false,
            'callback_base_url' => 'https://shop.test',
            'mobile' => '09120000000',
            'allowedCards' => ['6037990000000000'],
            'percentMode' => '1',
            'feeMode' => '2',
            'description' => 'Shop payment',
        ]);

        $this->submitConfig($gateway, ['form[merchant]' => 'new-merchant']);

        $config = $this->storedConfig($gateway);
        self::assertSame('new-merchant', $config['merchant']);
        self::assertSame('https://shop.test', $config['callback_base_url']);
        self::assertSame('09120000000', $config['mobile'] ?? null);
        self::assertSame(['6037990000000000'], $config['allowedCards'] ?? null);
        self::assertSame('1', $config['percentMode'] ?? null);
        self::assertSame('2', $config['feeMode'] ?? null);
        self::assertSame('Shop payment', $config['description'] ?? null);
    }

    public function testNowPaymentsKeepsExtraKeysAndAnEmptySecretAndSavesAChangedOne(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, [
            'api_key' => 'old-api-key',
            'ipn_secret' => 'old-ipn-secret',
            'payment_mode' => 'invoice',
            'price_currency' => 'usd',
            'pay_currency' => 'usdttrc20',
            'amount_unit' => 'toman',
            'toman_per_usd' => 60000,
            'callback_base_url' => 'https://shop.test',
            'sandbox' => true,
            'success_url' => 'https://shop.test/ok',
            'cancel_url' => 'https://shop.test/cancel',
            'min_price_amount_override' => 5,
            'irr_to_usd_rate' => 600000,
            'lock_pay_currency' => true,
            'order_description' => 'VPN order',
        ], 'USD');

        $this->submitConfig($gateway, [
            'form[api_key]' => '',
            'form[ipn_secret]' => 'new-ipn-secret',
            'form[toman_per_usd]' => '70000',
        ]);

        $config = $this->storedConfig($gateway);
        self::assertSame('old-api-key', $config['api_key'] ?? null, 'a secret left empty keeps its stored value');
        self::assertSame('new-ipn-secret', $config['ipn_secret'] ?? null, 'a changed secret is saved');
        self::assertSame(70000, $config['toman_per_usd'] ?? null);
        self::assertTrue($config['sandbox'] ?? null);
        self::assertSame('https://shop.test/ok', $config['success_url'] ?? null);
        self::assertSame('https://shop.test/cancel', $config['cancel_url'] ?? null);
        self::assertSame(5, $config['min_price_amount_override'] ?? null);
        self::assertSame(600000, $config['irr_to_usd_rate'] ?? null);
        self::assertTrue($config['lock_pay_currency'] ?? null);
        self::assertSame('VPN order', $config['order_description'] ?? null);
    }

    public function testClearingAnOptionalNonSecretFieldStillRemovesIt(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, [
            'merchant' => 'zibal',
            'callback_base_url' => 'https://shop.test',
            'underpayment_tolerance_percent' => 2.5,
            'mobile' => '09120000000',
        ]);

        $this->submitConfig($gateway, ['form[underpayment_tolerance_percent]' => '']);

        $config = $this->storedConfig($gateway);
        self::assertArrayNotHasKey('underpayment_tolerance_percent', $config);
        self::assertSame('09120000000', $config['mobile'] ?? null);
    }

    /**
     * custom_api has no schema module: "edit config" is not offered for it (404), and the generic EasyAdmin edit form
     * (title, currency, active, description) leaves its config alone.
     */
    public function testCustomApiConfigIsNotTouchedByTheAdminForms(): void
    {
        $config = [
            'create_url' => 'https://pay.example.test/create',
            'verify_url' => 'https://pay.example.test/verify',
            'api_key' => 'custom-secret',
            'response_map' => ['payment_url' => 'data.url', 'reference' => 'data.id'],
        ];
        $gateway = $this->createGateway(PaymentGatewayType::CUSTOM_API, $config);

        $this->client->request('GET', '/admin/payment-gateways/'.$gateway->getId().'/config');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $crawler = $this->client->request('GET', '/admin/payment-gateway/'.$gateway->getId().'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="PaymentGateway"]')->form();
        $form['PaymentGateway[title]'] = 'Renamed gateway';
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(PaymentGateway::class)->find($gateway->getId());
        self::assertInstanceOf(PaymentGateway::class, $reloaded);
        self::assertSame('Renamed gateway', $reloaded->getTitle());
        self::assertSame($config, $reloaded->getConfig());
    }

    /**
     * @param array<string, string> $values
     */
    private function submitConfig(PaymentGateway $gateway, array $values): void
    {
        $crawler = $this->client->request('GET', '/admin/payment-gateways/'.$gateway->getId().'/config');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('form')->form(), $values);
        self::assertTrue($this->client->getResponse()->isRedirect(), sprintf('the form was not saved (HTTP %d)', $this->client->getResponse()->getStatusCode()));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedConfig(PaymentGateway $gateway): array
    {
        $this->em->clear();
        $reloaded = $this->em->getRepository(PaymentGateway::class)->find($gateway->getId());
        self::assertInstanceOf(PaymentGateway::class, $reloaded);

        return (array) $reloaded->getConfig();
    }
}
