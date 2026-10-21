<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A super admin links an admin to a Telegram user id in the admin-user form: digits only, one admin per Telegram id.
 */
final class AdminUserTelegramLinkTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $super = $this->admin('link-super@shop.test', AdminUser::ROLE_SUPER_ADMIN);
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($super, 'main');
    }

    public function testTheTelegramIdIsSavedValidatedAndUnique(): void
    {
        $first = $this->admin('first@shop.test', AdminUser::ROLE_ADMIN);
        $second = $this->admin('second@shop.test', AdminUser::ROLE_SUPPORT);

        self::assertSame(422, $this->submit($first, 'not-a-number'));
        self::assertNull($this->reload($first)->getTelegramUserId());

        self::assertSame(302, $this->submit($first, ' 123456789 '));
        self::assertSame('123456789', $this->reload($first)->getTelegramUserId());

        self::assertSame(422, $this->submit($second, '123456789'), 'already linked to another admin');
        self::assertNull($this->reload($second)->getTelegramUserId());

        self::assertSame(302, $this->submit($first, ''));
        self::assertNull($this->reload($first)->getTelegramUserId(), 'empty unlinks');
    }

    private function submit(AdminUser $admin, string $telegramUserId): int
    {
        $crawler = $this->client->request('GET', '/admin/admin-user/'.$admin->getId().'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="AdminUser"]')->form();
        $form['AdminUser[telegramUserId]'] = $telegramUserId;
        $this->client->submit($form);

        return $this->client->getResponse()->getStatusCode();
    }

    private function reload(AdminUser $admin): AdminUser
    {
        $this->em->clear();
        $reloaded = $this->em->find(AdminUser::class, $admin->getId());
        self::assertInstanceOf(AdminUser::class, $reloaded);

        return $reloaded;
    }

    private function admin(string $email, string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
