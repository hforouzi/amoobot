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
 * Orders and payments are never deleted from the admin. Neither are customers on the web (their services would go with
 * the row and stay on the panel), wallet or not.
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

    /** The EasyAdmin delete token ("ea-delete"), from a page that still offers a delete (a plan's). */
    private function deleteToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/plan/'.$this->plan->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $crawler->filter('input[name="token"]')->attr('value');
    }

    public function testOrdersAndPaymentsHaveNoDeleteActionAndADeleteRequestIsRefused(): void
    {
        self::getContainer()->get(WalletService::class)->credit($this->user, 200000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        $token = $this->deleteToken();

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

    public function testNoCustomerIsDeletedFromTheWeb(): void
    {
        self::getContainer()->get(WalletService::class)->credit($this->user, 1000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $token = $this->deleteToken();

        foreach ([$this->user, $this->createUser()] as $customer) {
            $crawler = $this->client->request('GET', '/admin/customer/'.$customer->getId());
            self::assertCount(0, $crawler->filter('form[action$="/delete"], a.action-delete'), 'the page offers no delete');

            $this->client->request('POST', '/admin/customer/'.$customer->getId().'/delete', ['token' => $token]);

            self::assertSame(403, $this->client->getResponse()->getStatusCode());
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `user` WHERE id = ?', [$customer->getId()]));
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action LIKE '%delete%'"), 'no delete was audited');
    }

    public function testTheCustomerListOffersNoBatchDeleteAndRefusesOne(): void
    {
        $customer = $this->createUser();
        $crawler = $this->client->request('GET', '/admin/customer');
        self::assertCount(0, $crawler->filter('[data-action-batch][data-action-name="batchDelete"]'));

        $this->client->request('POST', '/admin/customer/batch-delete', [
            'batchActionName' => 'batchDelete',
            'entityFqcn' => User::class,
            'batchActionCsrfToken' => 'x',
            'batchActionEntityIds' => [(string) $customer->getId()],
        ]);

        // With a valid token it is a 403: AdminSafetyTest.
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `user` WHERE id = ?', [$customer->getId()]));
    }
}
