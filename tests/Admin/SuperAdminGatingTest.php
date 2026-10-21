<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Crud\CustomApiPaymentGatewayCrudController;
use App\Admin\UI\Crud\NowPaymentsPaymentGatewayCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PluginCrudController;
use App\Admin\UI\Crud\SettingCrudController;
use App\Admin\UI\Crud\StorePaymentMethodCrudController;
use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\ZibalPaymentGatewayCrudController;
use App\Entity\AdminUser;
use App\Entity\Plugin;
use App\Entity\Setting;
use App\Entity\StorePaymentMethod;
use App\Tests\Support\IntegrationTestCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Super-admin pages are closed on the server, not only hidden from the menu: a plain ROLE_ADMIN (or ROLE_SUPPORT)
 * gets 403 on every URL of the gated CRUD controllers (including direct pretty URLs such as autocomplete and
 * batch-delete) and on the gated custom routes, and the denial comes from #[IsGranted('ROLE_SUPER_ADMIN')].
 */
final class SuperAdminGatingTest extends IntegrationTestCase
{
    private const GATED_CRUDS = [
        PaymentGatewayCrudController::class,
        StorePaymentMethodCrudController::class,
        VpnPanelCrudController::class,
        VpnInboundCrudController::class,
        SettingCrudController::class,
        PluginCrudController::class,
        // Not in the menu, but routable: the only editors for some Zibal / NOWPayments / custom API config keys.
        ZibalPaymentGatewayCrudController::class,
        NowPaymentsPaymentGatewayCrudController::class,
        CustomApiPaymentGatewayCrudController::class,
    ];

    private KernelBrowser $client;
    private AdminUser $superAdmin;
    private AdminUser $admin;
    private AdminUser $support;
    /** @var array<class-string, int> entity id per gated CRUD controller */
    private array $ids = [];
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->superAdmin = $this->createAdmin('super@shop.test', [AdminUser::ROLE_SUPER_ADMIN]);
        $this->admin = $this->createAdmin('admin@shop.test', [AdminUser::ROLE_ADMIN]);
        $this->support = $this->createAdmin('support@shop.test', [AdminUser::ROLE_SUPPORT]);

        $inbound = $this->createTestInbound();
        $gateway = $this->createGateway();
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle('Card')->setCurrency('IRR')->setIsActive(true)->setSortOrder(0);
        $setting = (new Setting())->setKeyName('gating.test.'.bin2hex(random_bytes(4)))->setValue('1');
        $code = 'gating_test_'.bin2hex(random_bytes(4));
        $plugin = (new Plugin())->setCode($code)->setType(Plugin::TYPE_PAYMENT_GATEWAY)->setName(['en' => 'Gating test'])
            ->setVersion('1.0.0')->setStatus(Plugin::STATUS_DISABLED)->setPath('plugins/'.$code)->setManifest([]);
        foreach ([$method, $setting, $plugin] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->serviceId = (int) $this->createActiveService($this->createUser(), $inbound)->getId();

        $this->ids = [
            PaymentGatewayCrudController::class => (int) $gateway->getId(),
            StorePaymentMethodCrudController::class => (int) $method->getId(),
            VpnPanelCrudController::class => (int) $inbound->getPanel()->getId(),
            VpnInboundCrudController::class => (int) $inbound->getId(),
            SettingCrudController::class => (int) $setting->getId(),
            PluginCrudController::class => (int) $plugin->getId(),
            ZibalPaymentGatewayCrudController::class => (int) $gateway->getId(),
            NowPaymentsPaymentGatewayCrudController::class => (int) $gateway->getId(),
            CustomApiPaymentGatewayCrudController::class => (int) $gateway->getId(),
        ];

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM plugin WHERE code LIKE 'gating\\_test\\_%'");
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'gating.test.%'");
        parent::tearDown();
    }

    public function testSuperAdminImpliesAdminThroughTheRoleHierarchy(): void
    {
        $reachable = self::getContainer()->get(RoleHierarchyInterface::class)->getReachableRoleNames([AdminUser::ROLE_SUPER_ADMIN]);

        self::assertContains(AdminUser::ROLE_ADMIN, $reachable);
        self::assertNotContains(AdminUser::ROLE_SUPER_ADMIN, self::getContainer()->get(RoleHierarchyInterface::class)->getReachableRoleNames([AdminUser::ROLE_SUPPORT]));
    }

    public function testPlainAdminAndSupportAreDeniedEveryGatedUrl(): void
    {
        $urls = $this->gatedUrls();
        self::assertGreaterThan(40, count($urls), 'every EasyAdmin action route of the gated CRUDs plus the custom routes');

        foreach ([$this->admin, $this->support] as $user) {
            $this->client->loginUser($user, 'main');
            foreach ($urls as [$method, $url, $body]) {
                $this->client->catchExceptions(true);
                $this->client->request($method, $url, $body);
                self::assertSame(403, $this->client->getResponse()->getStatusCode(), sprintf('%s %s %s', $user->getEmail(), $method, $url));

                // The 403 comes from the super-admin gate (not from a CSRF check or a disabled EasyAdmin action).
                $this->client->catchExceptions(false);
                try {
                    $this->client->request($method, $url, $body);
                    self::fail(sprintf('%s %s %s was not denied', $user->getEmail(), $method, $url));
                } catch (AccessDeniedException $e) {
                    self::assertSame([AdminUser::ROLE_SUPER_ADMIN], $e->getAttributes(), sprintf('%s %s', $method, $url));
                }
            }

            // The pre-pretty-URL query format does not reach a gated CRUD either.
            $this->client->catchExceptions(true);
            $this->client->request('GET', '/admin?'.http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => VpnPanelCrudController::class]));
            self::assertStringNotContainsString('Test panel', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testSuperAdminReachesTheGatedPages(): void
    {
        $this->client->loginUser($this->superAdmin, 'main');
        $pages = [];
        foreach (self::GATED_CRUDS as $controller) {
            $pages[] = $this->crudPath($controller, 'index');
            $pages[] = $this->crudPath($controller, 'detail', $this->ids[$controller]);
        }
        foreach ([StorePaymentMethodCrudController::class, VpnPanelCrudController::class, VpnInboundCrudController::class, SettingCrudController::class] as $controller) {
            $pages[] = $this->crudPath($controller, 'edit', $this->ids[$controller]);
        }
        $pages[] = $this->crudPath(StorePaymentMethodCrudController::class, 'new');
        $pages[] = $this->crudPath(VpnInboundCrudController::class, 'new');
        $pages[] = '/admin/payment-gateways/install';
        $pages[] = '/admin/payment-gateways/'.$this->ids[PaymentGatewayCrudController::class].'/config';
        $pages[] = '/admin/plugins/upload';
        $pages[] = '/admin/settings/renewal-pricing';
        $pages[] = '/admin/settings/automation';

        foreach ($pages as $page) {
            $this->client->request('GET', $page);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET '.$page);
        }

        // Actions that redirect back to a list (the test panel is not a 3x-ui panel, so nothing is sent anywhere).
        $panelId = $this->ids[VpnPanelCrudController::class];
        $inboundId = $this->ids[VpnInboundCrudController::class];
        foreach (['/admin/vpn-panel/'.$panelId.'/sync-inbounds', '/admin/vpn-panel/'.$panelId.'/inbounds', '/admin/vpn-inbound/'.$inboundId.'/test-create-client'] as $action) {
            $this->client->request('GET', $action);
            self::assertTrue($this->client->getResponse()->isRedirect(), 'GET '.$action.' answered '.$this->client->getResponse()->getStatusCode());
        }
    }

    public function testPagesThatStayOpenToPlainAdmins(): void
    {
        $this->client->loginUser($this->admin, 'main');
        foreach (['/admin', '/admin/plan', '/admin/vpn-service', '/admin/plans/bulk-adjust-prices'] as $page) {
            $this->client->request('GET', $page);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET '.$page);
        }
        // The service list no longer offers the (super-admin) "regenerate config" button.
        $this->client->request('GET', '/admin/vpn-service');
        self::assertStringNotContainsString('/regenerate-config', (string) $this->client->getResponse()->getContent());
    }

    public function testMenuShowsGatedEntriesOnlyToSuperAdmins(): void
    {
        $gatedLinks = ['/admin/payment-gateway"', '/admin/store-payment-method"', '/admin/vpn-panel"', '/admin/vpn-inbound"', '/admin/setting"', '/admin/plugin"', 'routeName=admin_renewal_pricing_settings"', 'routeName=admin_automation_settings"'];

        $this->client->loginUser($this->admin, 'main');
        $this->client->request('GET', '/admin');
        $content = (string) $this->client->getResponse()->getContent();
        foreach ($gatedLinks as $link) {
            self::assertStringNotContainsString($link, $content, 'plain admin menu');
        }
        self::assertStringContainsString('routeName=admin_bulk_plan_price_adjustment"', $content);

        $this->client->loginUser($this->superAdmin, 'main');
        $this->client->request('GET', '/admin');
        $content = (string) $this->client->getResponse()->getContent();
        foreach ($gatedLinks as $link) {
            self::assertStringContainsString($link, $content, 'super admin menu');
        }
    }

    /**
     * Every EasyAdmin route of the gated CRUD controllers (index, new, detail, edit, delete, batch-delete, autocomplete,
     * render-filters) and every gated custom route.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function gatedUrls(): array
    {
        $urls = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            $controller = $route->getDefault('crudControllerFqcn');
            if (!in_array($controller, self::GATED_CRUDS, true)) {
                continue;
            }
            $methods = $route->getMethods();
            // A well-formed batch request: without it EasyAdmin's BatchActionDto resolver fails before any controller runs.
            $body = 'batchDelete' === $route->getDefault('crudAction')
                ? [EA::BATCH_ACTION_NAME => 'delete', EA::BATCH_ACTION_ENTITY_IDS => [$this->ids[$controller]], EA::ENTITY_FQCN => $controller, EA::BATCH_ACTION_CSRF_TOKEN => 'x']
                : [];
            $urls[] = [[] === $methods || in_array('GET', $methods, true) ? 'GET' : 'POST', str_replace('{entityId}', (string) $this->ids[$controller], $route->getPath()), $body];
        }

        $gatewayId = $this->ids[PaymentGatewayCrudController::class];
        $pluginId = $this->ids[PluginCrudController::class];
        $panelId = $this->ids[VpnPanelCrudController::class];
        $inboundId = $this->ids[VpnInboundCrudController::class];

        return [...$urls,
            ['GET', '/admin/payment-gateways/install', []],
            ['GET', '/admin/payment-gateways/install/manual_card', []],
            ['POST', '/admin/payment-gateways/install/manual_card', []],
            ['GET', '/admin/payment-gateways/install/success/'.$gatewayId, []],
            ['POST', '/admin/payment-gateways/'.$gatewayId.'/add-store-method', []],
            ['GET', '/admin/payment-gateways/'.$gatewayId.'/config', []],
            ['POST', '/admin/payment-gateways/'.$gatewayId.'/config', []],
            ['GET', '/admin/plugins/upload', []],
            ['POST', '/admin/plugins/upload', []],
            ['GET', '/admin/plugins/'.$pluginId.'/enable', []],
            ['GET', '/admin/plugins/'.$pluginId.'/disable', []],
            ['GET', '/admin/vpn-panel/'.$panelId.'/sync-inbounds', []],
            ['GET', '/admin/vpn-panel/'.$panelId.'/inbounds', []],
            ['GET', '/admin/vpn-inbound/'.$inboundId.'/test-create-client', []],
            ['GET', '/admin/vpn-inbound/'.$inboundId.'/resync', []],
            ['GET', '/admin/vpn-inbound/'.$inboundId.'/sync-access-metadata', []],
            ['GET', '/admin/vpn-inbound/'.$inboundId.'/regenerate-service-configs', []],
            ['GET', '/admin/vpn-service/'.$this->serviceId.'/regenerate-config', []],
            ['GET', '/admin/settings/renewal-pricing', []],
            ['POST', '/admin/settings/renewal-pricing', []],
            ['GET', '/admin/settings/automation', []],
            ['POST', '/admin/settings/automation', []],
        ];
    }

    private function crudPath(string $controller, string $action, ?int $id = null): string
    {
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            if ($controller === $route->getDefault('crudControllerFqcn') && $action === $route->getDefault('crudAction')) {
                return str_replace('{entityId}', (string) $id, $route->getPath());
            }
        }
        self::fail(sprintf('No EasyAdmin %s route for %s', $action, $controller));
    }

    /**
     * @param list<string> $roles
     */
    private function createAdmin(string $email, array $roles): AdminUser
    {
        $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles($roles)->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
