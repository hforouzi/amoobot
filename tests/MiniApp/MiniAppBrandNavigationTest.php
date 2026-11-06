<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\AdminUser;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * MiniAppSections on the brand host: the same permissions decide as on the main host, the host does not.
 */
final class MiniAppBrandNavigationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;
    use MiniAppTestTrait;

    private const ADMIN = '9401';
    private const CUSTOMER = '9402';
    private const DEACTIVATED = '9403';

    protected function setUp(): void
    {
        $this->useBrandDomain();
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->startMiniAppClient();
        $this->client->setServerParameter('HTTP_HOST', 'brand.example.org');
        $this->client->setServerParameter('HTTPS', 'on');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    public function testAnAdminSeesTheAdminMenuWithTheAreasOfTheirRole(): void
    {
        $this->linkedAdmin('super@example.test', AdminUser::ROLE_SUPER_ADMIN, self::ADMIN);
        $session = $this->authenticate(self::ADMIN);

        self::assertContains('admin', $this->navKeys($session));

        $home = new Crawler($this->appGet('/app/admin', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertGreaterThan(0, $home->filter('a[data-area]')->count());
    }

    public function testACustomerSeesNoAdminMenu(): void
    {
        self::assertNotContains('admin', $this->navKeys($this->authenticate(self::CUSTOMER)));
    }

    public function testADeactivatedAdminSeesNoAdminMenu(): void
    {
        $this->linkedAdmin('gone@example.test', AdminUser::ROLE_SUPER_ADMIN, self::DEACTIVATED, false);

        self::assertNotContains('admin', $this->navKeys($this->authenticate(self::DEACTIVATED)));
    }

    /**
     * @param array{token: string, csrf: string} $session
     *
     * @return list<string>
     */
    private function navKeys(array $session): array
    {
        $html = $this->appGet('/app/view/nav', $session);
        self::assertSame(200, $this->httpStatus());

        return (new Crawler($html))->filter('a[data-nav-key]')->each(static fn (Crawler $c): string => (string) $c->attr('data-nav-key'));
    }
}
