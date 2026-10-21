<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The schema form in PaymentGatewayCrudController (install + "edit config") is the only editor for a manual-card
 * gateway: it creates one and edits every key the old ManualCardPaymentGatewayCrudController edited.
 */
final class PaymentGatewaySchemaFormTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('gateways@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testManualCardGatewayIsCreatedAndEditedThroughTheSchemaForm(): void
    {
        $crawler = $this->client->request('GET', '/admin/payment-gateways/install/'.PaymentGatewayType::MANUAL_CARD);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('form')->form(), [
            'form[title]' => 'Card to card',
            'form[currency]' => 'irr',
            'form[isActive]' => true,
            'form[card_number]' => '6037991234567890',
            'form[card_holder]' => 'Shop Owner',
            'form[bank_name]' => 'Melli',
            'form[instructions]' => 'Send the receipt.',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $gateway = $this->em->getRepository(PaymentGateway::class)->findOneBy(['type' => PaymentGatewayType::MANUAL_CARD]);
        self::assertInstanceOf(PaymentGateway::class, $gateway);
        self::assertSame('Card to card', $gateway->getTitle());
        self::assertSame('IRR', $gateway->getCurrency());
        self::assertTrue($gateway->isActive());
        self::assertSame('6037991234567890', $gateway->getManualCardNumber());
        self::assertSame('Shop Owner', $gateway->getManualCardHolder());
        self::assertSame('Melli', $gateway->getManualBankName());
        self::assertSame('Send the receipt.', $gateway->getManualInstructions());
        self::assertTrue($gateway->isConfigured());

        $crawler = $this->client->request('GET', '/admin/payment-gateways/'.$gateway->getId().'/config');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('form')->form(), [
            'form[card_number]' => '6219861234567890',
            'form[card_holder]' => 'New Owner',
            'form[bank_name]' => '',
            'form[instructions]' => 'Updated.',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $gateway = $this->em->getRepository(PaymentGateway::class)->find($gateway->getId());
        self::assertInstanceOf(PaymentGateway::class, $gateway);
        self::assertSame('Card to card', $gateway->getTitle());
        self::assertSame('6219861234567890', $gateway->getManualCardNumber());
        self::assertSame('New Owner', $gateway->getManualCardHolder());
        self::assertNull($gateway->getManualBankName());
        self::assertSame('Updated.', $gateway->getManualInstructions());
    }
}
