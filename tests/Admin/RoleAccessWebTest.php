<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Web access per role: every CRUD index, the write pages, and the custom routes answer 200 / 403 as the permission
 * matrix says. The expected permission of each page is written out here (the specification), not read from the map.
 */
final class RoleAccessWebTest extends IntegrationTestCase
{
    /** CRUD controller (short name) => permission its pages need. */
    private const VIEW = [
        'OrderCrudController' => 'ORDERS_VIEW',
        'OrderDraftCrudController' => 'ORDERS_VIEW',
        'PaymentCrudController' => 'PAYMENTS_VIEW',
        'PaymentNeedsReviewCrudController' => 'PAYMENTS_VIEW',
        'PaymentProvisioningFailedCrudController' => 'PAYMENTS_VIEW',
        'DiscountCodeCrudController' => 'DISCOUNTS_MANAGE',
        'DiscountUsageCrudController' => 'DISCOUNTS_MANAGE',
        'PlanCrudController' => 'PLANS_MANAGE',
        'TrialPlanCrudController' => 'PLANS_MANAGE',
        'TrialClaimCrudController' => 'PLANS_MANAGE',
        'UserCrudController' => 'CUSTOMERS_VIEW',
        'TelegramAccountCrudController' => 'CUSTOMERS_VIEW',
        'VpnServiceCrudController' => 'SERVICES_VIEW',
        'BroadcastMessageCrudController' => 'BROADCAST_SEND',
        'BroadcastDeliveryCrudController' => 'BROADCAST_SEND',
        'BotMessageTemplateCrudController' => 'BOT_TEXTS_MANAGE',
        'BotButtonLabelCrudController' => 'BOT_TEXTS_MANAGE',
        'SettingCrudController' => 'SETTINGS_MANAGE',
        'RequiredChannelCrudController' => 'SETTINGS_MANAGE',
        'PaymentGatewayCrudController' => 'GATEWAYS_MANAGE',
        'ZibalPaymentGatewayCrudController' => 'GATEWAYS_MANAGE',
        'NowPaymentsPaymentGatewayCrudController' => 'GATEWAYS_MANAGE',
        'CustomApiPaymentGatewayCrudController' => 'GATEWAYS_MANAGE',
        'StorePaymentMethodCrudController' => 'GATEWAYS_MANAGE',
        'VpnPanelCrudController' => 'PANELS_MANAGE',
        'VpnInboundCrudController' => 'PANELS_MANAGE',
        'PluginCrudController' => 'PLUGINS_MANAGE',
        'AdminUserCrudController' => 'ADMINS_MANAGE',
        'ServiceNotificationLogCrudController' => 'LOGS_VIEW',
        'BotMessageLogCrudController' => 'LOGS_VIEW',
    ];

    private const ROLE_PERMISSIONS = [
        AdminUser::ROLE_SUPPORT => ['ORDERS_VIEW', 'PAYMENTS_VIEW', 'CUSTOMERS_VIEW', 'SERVICES_VIEW', 'SERVICES_ASSIST'],
        AdminUser::ROLE_ADMIN => ['ORDERS_VIEW', 'ORDERS_MANAGE', 'PAYMENTS_VIEW', 'PAYMENTS_MODERATE', 'CUSTOMERS_VIEW', 'CUSTOMERS_MANAGE', 'SERVICES_VIEW', 'SERVICES_ASSIST', 'SERVICES_MANAGE', 'SERVICES_DELETE', 'PLANS_MANAGE', 'DISCOUNTS_MANAGE', 'BROADCAST_SEND', 'BOT_TEXTS_MANAGE'],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    public function testEveryCrudControllerIsInThePermissionMap(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/src/Admin/UI/Crud/*CrudController.php') ?: [] as $file) {
            $class = 'App\\Admin\\UI\\Crud\\'.basename($file, '.php');
            self::assertTrue(is_subclass_of($class, AbstractCrudController::class));
            self::assertNotNull(AdminCrudPermissions::forController($class), $class.' has no permission: it would be closed to everyone');
            self::assertArrayHasKey(basename($file, '.php'), self::VIEW, 'add '.basename($file, '.php').' to this test');
            self::assertSame(self::VIEW[basename($file, '.php')], AdminCrudPermissions::forController($class)->view->name, $class);
        }
    }

    public function testEachRoleOpensExactlyItsIndexPages(): void
    {
        foreach ([AdminUser::ROLE_SUPER_ADMIN, AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            foreach (self::VIEW as $controller => $permission) {
                $path = $this->indexPath('App\\Admin\\UI\\Crud\\'.$controller);
                $this->client->request('GET', $path);
                self::assertSame($this->allowed($role, $permission) ? 200 : 403, $this->client->getResponse()->getStatusCode(), sprintf('%s GET %s', $role, $path));
            }
        }
    }

    public function testWritePagesFollowTheManagePermissions(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user, $this->createPlan());
        $payment = $this->createPayment($order);
        $service = $this->createActiveService($user, $this->createTestInbound());

        $pages = [
            ['/admin/order/'.$order->getId(), 'ORDERS_VIEW'],
            ['/admin/order/'.$order->getId().'/edit', 'ORDERS_MANAGE'],
            ['/admin/order/new', 'ORDERS_MANAGE'],
            ['/admin/payment/'.$payment->getId(), 'PAYMENTS_VIEW'],
            ['/admin/payment/'.$payment->getId().'/edit', 'PAYMENTS_MODERATE'],
            ['/admin/user/'.$user->getId(), 'CUSTOMERS_VIEW'],
            ['/admin/user/'.$user->getId().'/edit', 'CUSTOMERS_MANAGE'],
            ['/admin/vpn-service/'.$service->getId(), 'SERVICES_VIEW'],
            ['/admin/vpn-service/'.$service->getId().'/edit', 'SERVICES_MANAGE'],
            ['/admin/plans/bulk-adjust-prices', 'PLANS_MANAGE'],
            ['/admin/settings/renewal-pricing', 'SETTINGS_MANAGE'],
            ['/admin/settings/automation', 'SETTINGS_MANAGE'],
            ['/admin/panel-setup/new', 'PANELS_MANAGE'],
            ['/admin/payment-gateways/install', 'GATEWAYS_MANAGE'],
            ['/admin/plugins/upload', 'PLUGINS_MANAGE'],
        ];
        foreach ([AdminUser::ROLE_SUPER_ADMIN, AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            foreach ($pages as [$path, $permission]) {
                $this->client->request('GET', $path);
                self::assertSame($this->allowed($role, $permission) ? 200 : 403, $this->client->getResponse()->getStatusCode(), sprintf('%s GET %s', $role, $path));
            }

            // Delete (POST) needs SERVICES_DELETE; the CSRF check comes later, so an allowed role is not answered 403.
            $this->client->request('POST', '/admin/vpn-service/'.$service->getId().'/delete');
            self::assertSame($this->allowed($role, 'SERVICES_DELETE'), 403 !== $this->client->getResponse()->getStatusCode(), $role.' delete service');

            // Approve / reject (POST, own routes) need PAYMENTS_MODERATE; the buttons are only shown with it.
            $this->client->request('POST', '/admin/payment/'.$payment->getId().'/confirm');
            self::assertSame($this->allowed($role, 'PAYMENTS_MODERATE'), 403 !== $this->client->getResponse()->getStatusCode(), $role.' confirm payment');
            $this->client->request('GET', '/admin/payment/'.$payment->getId());
            self::assertSame($this->allowed($role, 'PAYMENTS_MODERATE'), str_contains((string) $this->client->getResponse()->getContent(), '/confirm'), $role.' approve button');
        }
    }

    private function allowed(string $role, string $permission): bool
    {
        return AdminUser::ROLE_SUPER_ADMIN === $role || in_array($permission, self::ROLE_PERMISSIONS[$role], true);
    }

    private function admin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
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
}
