<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\PaymentGateway;
use App\Entity\VpnPanel;
use App\Payment\Domain\PaymentGatewayType;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Stored secrets never reach the page HTML: the gateway "edit config" password fields and the panel API token field
 * are always rendered empty, and submitting them empty keeps the stored value.
 */
final class SecretsNotRenderedTest extends IntegrationTestCase
{
    private const API_KEY = 'np-api-key-5f1c0e9a77';
    private const IPN_SECRET = 'np-ipn-secret-3b2d8c41';
    private const PANEL_TOKEN = 'panel-token-9e7a4d21c6';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('secrets@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testGatewayConfigPageDoesNotRenderStoredSecretsAndAnUntouchedSubmitKeepsThem(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, [
            'api_key' => self::API_KEY,
            'ipn_secret' => self::IPN_SECRET,
            'payment_mode' => 'invoice',
            'price_currency' => 'usd',
            'toman_per_usd' => 60000,
            'callback_base_url' => 'https://shop.test',
        ], 'USD');

        $crawler = $this->client->request('GET', '/admin/payment-gateways/'.$gateway->getId().'/config');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::API_KEY, $html);
        self::assertStringNotContainsString(self::IPN_SECRET, $html);

        $this->client->submit($crawler->filter('form')->form());
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $config = (array) $this->em->getRepository(PaymentGateway::class)->find($gateway->getId())?->getConfig();
        self::assertSame(self::API_KEY, $config['api_key'] ?? null);
        self::assertSame(self::IPN_SECRET, $config['ipn_secret'] ?? null);
    }

    /**
     * A form re-rendered after a validation error does not echo a secret that was just typed.
     */
    public function testGatewayConfigPageDoesNotEchoATypedSecretAfterAValidationError(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, [
            'api_key' => self::API_KEY,
            'payment_mode' => 'invoice',
            'price_currency' => 'usd',
            'toman_per_usd' => 60000,
            'callback_base_url' => 'https://shop.test',
        ], 'USD');

        $crawler = $this->client->request('GET', '/admin/payment-gateways/'.$gateway->getId().'/config');
        $this->client->submit($crawler->filter('form')->form(), [
            'form[api_key]' => 'np-new-key-typed-0a9b8c',
            'form[toman_per_usd]' => '-1',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('np-new-key-typed-0a9b8c', (string) $this->client->getResponse()->getContent());
    }

    public function testPanelPagesDoNotRenderTheApiToken(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Secret panel')->setBaseUrl('https://panel.example.test:2053/secret/')->setPlainApiToken(self::PANEL_TOKEN);
        $this->em->persist($panel);
        $this->em->flush();

        foreach (['/admin/panel-setup/'.$panel->getId().'/edit', '/admin/panel-setup/'.$panel->getId(), '/admin/vpn-panel/'.$panel->getId()] as $page) {
            $this->client->request('GET', $page);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET '.$page);
            self::assertStringNotContainsString(self::PANEL_TOKEN, (string) $this->client->getResponse()->getContent(), 'GET '.$page);
        }
    }
}
