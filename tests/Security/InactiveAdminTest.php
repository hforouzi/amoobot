<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Deactivating an admin ends their web session on the next request (not only at the next login).
 */
final class InactiveAdminTest extends IntegrationTestCase
{
    public function testADeactivatedAdminIsLoggedOutOnTheNextRequest(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('soon-off@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $client->request('GET', '/admin/order');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->getConnection()->executeStatement('UPDATE admin_user SET is_active = 0 WHERE id = ?', [$admin->getId()]);
        $this->em->clear();

        foreach (['/admin/order', '/admin'] as $page) {
            $client->request('GET', $page);
            self::assertTrue($client->getResponse()->isRedirect(), $page.' answered '.$client->getResponse()->getStatusCode());
            self::assertStringEndsWith('/admin/login', (string) $client->getResponse()->headers->get('Location'));
        }
    }
}
