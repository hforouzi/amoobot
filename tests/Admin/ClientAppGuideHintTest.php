<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Apps → Client apps (ClientAppCrudController, templates/admin/client_app/index.html.twig): the list explains that the
 * active apps are the apps of the customer guide (first per device = recommended) and warns about each device no
 * active app is set up for, where the guide falls back to the general text (G-3).
 */
final class ClientAppGuideHintTest extends IntegrationTestCase
{
    use GuideClientAppFixtures;

    private KernelBrowser $client;
    private string $locale = 'fa';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', '10.81.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');
        $this->switchOffClientApps();
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        parent::tearDown();
    }

    public function testTheListExplainsTheGuideAndWarnsAboutEveryDeviceWithoutAnApp(): void
    {
        $page = $this->index();

        self::assertSame($this->trans('client_app_guide.hint', 'admin'), $page->filter('[data-guide-apps-hint]')->text());
        $warning = $page->filter('[data-guide-apps-missing]')->text();
        foreach (['android', 'ios', 'windows', 'macos', 'linux'] as $platform) {
            self::assertStringContainsString($this->label($platform), $warning);
        }
    }

    public function testOnlyDevicesWithoutAnActiveAppAreListed(): void
    {
        $this->addClientApp('Alpha Client', ['android', 'ios'], 10, 'https://store.example/alpha');
        $this->addClientApp('Beta Client', ['windows'], 20, 'https://store.example/beta', active: false);

        $warning = $this->index()->filter('[data-guide-apps-missing]')->text();
        self::assertStringNotContainsString($this->label('android'), $warning);
        self::assertStringNotContainsString($this->label('ios'), $warning);
        foreach (['windows', 'macos', 'linux'] as $platform) {
            self::assertStringContainsString($this->label($platform), $warning, 'an inactive app does not count');
        }
    }

    public function testNoWarningWhenEveryDeviceHasAnApp(): void
    {
        $this->addClientApp('Alpha Client', ['android', 'ios', 'windows', 'macos', 'linux'], 10, 'https://store.example/alpha');

        $page = $this->index();
        self::assertCount(0, $page->filter('[data-guide-apps-missing]'));
        self::assertCount(1, $page->filter('[data-guide-apps-hint]'));
    }

    private function index(): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/apps/clients');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->locale = (string) $crawler->filter('html')->attr('lang');

        return $crawler;
    }

    /** In the admin's language (the page's lang). */
    private function trans(string $key, string $domain): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, [], $domain, $this->locale);
    }

    private function label(string $platform): string
    {
        return $this->trans('platform.'.$platform, 'subscription');
    }

    private function admin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-apps-guide@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
