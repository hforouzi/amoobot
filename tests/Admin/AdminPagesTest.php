<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ProdBundlesKernel;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every EasyAdmin page a logged-in super admin reaches renders (200), with the prod bundle set (ProdBundlesKernel):
 * the dashboard, each CRUD controller's index, and the "new", first detail and first edit pages its index links to.
 * Regression: TwigExtraBundle was registered for dev/test only, so /admin was a 500 in prod (unknown html_classes()).
 */
final class AdminPagesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return ProdBundlesKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('pages@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        // One row for most entities, so their index lists something and links to a detail page.
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $plan = $this->createPlan(inbound: $inbound);
        $this->createPayment($this->createOrder($user, $plan));
        $this->createActiveService($user, $inbound);

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testDashboardRenders(): void
    {
        $this->client->request('GET', '/admin');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->failure('GET /admin'));
        self::assertStringContainsString('/admin/plan"', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param class-string $controller
     */
    #[DataProvider('crudControllers')]
    public function testCrudPagesRender(string $controller): void
    {
        $index = $this->indexPath($controller);
        $crawler = $this->client->request('GET', $index);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->failure('GET '.$index));

        $linked = [];
        foreach ($crawler->filter('a[href]')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
            $path = (string) parse_url($href, PHP_URL_PATH);
            if (preg_match('#^'.preg_quote($index, '#').'/(new|\d+|\d+/edit)$#', $path, $m)) {
                $linked[match (true) { 'new' === $m[1] => 'new', str_ends_with($m[1], '/edit') => 'edit', default => 'detail' }] ??= $path;
            }
        }
        foreach ($linked as $page => $path) {
            $this->client->request('GET', $path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->failure(sprintf('GET %s (%s)', $path, $page)));
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function crudControllers(): iterable
    {
        $files = glob(dirname(__DIR__, 2).'/src/Admin/UI/Crud/*CrudController.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'App\\Admin\\UI\\Crud\\'.basename($file, '.php');
            yield basename($file, '.php') => [$class];
        }
    }

    /**
     * The kernel above only proves bundles.php's prod set works; this keeps it complete: a bundle whose package is
     * installed with composer install --no-dev must not be limited to dev/test.
     */
    public function testEveryBundleShippedToProdIsEnabledInProd(): void
    {
        $bundlesPath = dirname(__DIR__, 2).'/config/bundles.php';
        $enabledInProd = ProdBundlesKernel::prodBundleClasses($bundlesPath);
        $missing = [];
        foreach (array_keys(require $bundlesPath) as $bundle) {
            $package = self::packageOf($bundle);
            if (null !== $package && !self::isDevPackage($package) && !in_array($bundle, $enabledInProd, true)) {
                $missing[] = $bundle.' ('.$package.')';
            }
        }

        self::assertSame([], $missing, 'Installed in prod but not enabled for prod in config/bundles.php');
    }

    private function indexPath(string $controller): string
    {
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            if ($controller === $route->getDefault('crudControllerFqcn') && 'index' === $route->getDefault('crudAction')) {
                return $route->getPath();
            }
        }
        self::fail('No EasyAdmin index route for '.$controller);
    }

    private function failure(string $request): string
    {
        $content = (string) $this->client->getResponse()->getContent();
        preg_match('#<title>(.*?)</title>#s', $content, $title);

        return sprintf('%s answered %d: %s', $request, $this->client->getResponse()->getStatusCode(), trim(html_entity_decode($title[1] ?? substr($content, 0, 300))));
    }

    private static function packageOf(string $class): ?string
    {
        $file = (string) realpath((string) (new \ReflectionClass($class))->getFileName());
        $package = null;
        $longest = 0;
        // The longest matching install path: the root package's (the project dir) also contains vendor/.
        foreach (InstalledVersions::getAllRawData() as $installed) {
            foreach ($installed['versions'] as $name => $version) {
                $path = isset($version['install_path']) ? realpath($version['install_path']) : false;
                if (false !== $path && strlen($path) > $longest && str_starts_with($file, $path.'/')) {
                    [$package, $longest] = [$name, strlen($path)];
                }
            }
        }

        return $package;
    }

    private static function isDevPackage(string $package): bool
    {
        foreach (InstalledVersions::getAllRawData() as $installed) {
            if (isset($installed['versions'][$package])) {
                return $installed['versions'][$package]['dev_requirement'];
            }
        }

        return false;
    }
}
