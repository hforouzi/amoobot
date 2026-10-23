<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\AdminUser;
use App\Entity\User;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Second review, minor 3: the wallet ledger references payments (by key) and customers (foreign keys, RESTRICT).
 * Orders and payments are never deleted from the admin; a customer with a wallet is refused with a clear message
 * (not a raw foreign key error page); a customer without a wallet is deleted as before.
 */
final class WalletAdminDeleteTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        $admin = (new AdminUser())->setEmail('delete-super@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    /** The EasyAdmin delete token ("ea-delete"), from a page that still offers a delete (a customer's). */
    private function deleteToken(User $customer): string
    {
        $crawler = $this->client->request('GET', '/admin/customer/'.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $crawler->filter('input[name="token"]')->attr('value');
    }

    public function testOrdersAndPaymentsHaveNoDeleteActionAndADeleteRequestIsRefused(): void
    {
        self::getContainer()->get(WalletService::class)->credit($this->user, 200000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        $token = $this->deleteToken($this->createUser());

        foreach (['order' => (int) $order->getId(), 'payment' => (int) $payment->getId()] as $page => $id) {
            $crawler = $this->client->request('GET', sprintf('/admin/%s/%d', $page, $id));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(0, $crawler->filter(sprintf('form[action*="/admin/%s/%d/delete"], a.action-delete', $page, $id)), $page.' detail offers no delete');

            $this->client->request('POST', sprintf('/admin/%s/%d/delete', $page, $id), ['token' => $token]);
            self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), $page.' delete is refused');
        }

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order`'));
        self::assertSame(1, $this->rows('payment'));
        self::assertSame(50000, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));
    }

    public function testACustomerWithAWalletIsNotDeletedAndTheAdminIsToldWhy(): void
    {
        self::getContainer()->get(WalletService::class)->credit($this->user, 1000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $customerId = (int) $this->user->getId();

        $this->client->request('POST', '/admin/customer/'.$customerId.'/delete', ['token' => $this->deleteToken($this->user)]);

        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/admin/customer', (string) $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        // The admin's default language is Persian: "customer #N has a wallet and cannot be deleted".
        self::assertStringContainsString('مشتری #'.$customerId.' کیف پول دارد و حذف نمی‌شود', $page->text());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `user` WHERE id = ?', [$customerId]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action LIKE '%delete%'"), 'no delete was audited');
    }

    public function testACustomerWithoutAWalletIsDeletedAsBefore(): void
    {
        $customer = $this->createUser();
        $customerId = (int) $customer->getId();

        $this->client->request('POST', '/admin/customer/'.$customerId.'/delete', ['token' => $this->deleteToken($customer)]);

        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `user` WHERE id = ?', [$customerId]));
    }
}
