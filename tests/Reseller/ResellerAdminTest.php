<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\ResellerAdminController;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ResellerStatus;
use App\Security\AdminPermission;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Resellers in the web panel (S1): RESELLERS_VIEW (super admin, admin, support) looks, RESELLERS_MANAGE (super admin,
 * admin) creates, edits, sets plan rules and enables / disables, every write POST + CSRF and audited. Every reseller
 * route has a permission (fail closed). Disabling ends the reseller's Mini App sessions and leaves his services
 * running. The "resellers" settings tab: off by default, floor 50 %, rounding 1,000.
 */
final class ResellerAdminTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private KernelBrowser $web;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
        $this->web = $this->client;
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'reseller.%'");
        $this->plan = $this->createPlan(150000);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function roles(): iterable
    {
        yield 'super admin' => [AdminUser::ROLE_SUPER_ADMIN, true];
        yield 'admin' => [AdminUser::ROLE_ADMIN, true];
        yield 'support' => [AdminUser::ROLE_SUPPORT, false];
    }

    #[DataProvider('roles')]
    public function testAccessPerRole(string $role, bool $manage): void
    {
        $reseller = $this->reseller();
        $this->webAdmin($role);

        foreach (['/admin/resellers', '/admin/resellers/'.$reseller->getId(), '/admin/resellers/report'] as $path) {
            $this->web->request('GET', $path);
            self::assertSame(200, $this->web->getResponse()->getStatusCode(), $role.' GET '.$path);
        }
        $detail = $this->web->request('GET', '/admin/resellers/'.$reseller->getId());
        self::assertSame($manage, $detail->filter('[data-update-form]')->count() > 0, $role.' sees the edit form');

        $id = $reseller->getId();
        foreach (['/admin/resellers/new', "/admin/resellers/$id/update", "/admin/resellers/$id/plan-price", "/admin/resellers/$id/plan-price/".$this->plan->getId().'/remove', "/admin/resellers/$id/enable", "/admin/resellers/$id/disable", '/admin/resellers/orders/1/refund'] as $path) {
            $this->web->request('POST', $path, ['_token' => 'x']);
            self::assertSame($manage, 403 !== $this->web->getResponse()->getStatusCode(), $role.' POST '.$path);
        }
        $reseller = $this->fresh(Reseller::class, (int) $reseller->getId());
        self::assertSame(ResellerStatus::ACTIVE, $reseller->getStatus(), 'a wrong token changes nothing');
    }

    /** Every reseller route has a permission; every write needs RESELLERS_MANAGE. */
    public function testEveryResellerRouteIsClosedByDefault(): void
    {
        $routes = 0;
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'admin_reseller')) {
                continue;
            }
            ++$routes;
            $permission = AdminCrudPermissions::forRoute($name) ?? AdminCrudPermissions::forController(ResellerAdminController::class)?->view;
            if (in_array('POST', $route->getMethods(), true)) {
                self::assertSame(AdminPermission::RESELLERS_MANAGE, AdminCrudPermissions::forRoute($name), $name);
            } else {
                self::assertSame(AdminPermission::RESELLERS_VIEW, $permission, $name);
            }
        }
        self::assertSame(10, $routes);
    }

    public function testAnAdminCreatesEditsAndSetsPlanRulesAllAudited(): void
    {
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $token = $this->token('/admin/resellers', '[data-create-form]');

        $this->web->request('POST', '/admin/resellers/new', ['_token' => $token, 'customer' => self::BUYER, 'discount_percent' => '95']);
        self::assertSame(0, $this->rows('reseller'), 'over 90 % is refused');
        $this->web->request('POST', '/admin/resellers/new', ['_token' => $token, 'customer' => 'nobody-here']);
        self::assertSame(0, $this->rows('reseller'));
        $this->web->request('POST', '/admin/resellers/new', ['_token' => $token, 'customer' => self::BUYER, 'discount_percent' => '12.5', 'daily_spend_limit' => '2,000,000', 'display_name' => 'Ali shop']);
        $reseller = $this->em->getRepository(Reseller::class)->findOneBy(['user' => $this->buyer]) ?? self::fail('not created');
        self::assertSame(1250, $reseller->getDiscountBp());
        self::assertSame(2000000, $reseller->getDailySpendLimit());
        self::assertTrue($this->web->getResponse()->isRedirect('/admin/resellers/'.$reseller->getId()));
        $this->web->request('POST', '/admin/resellers/new', ['_token' => $token, 'customer' => self::BUYER, 'discount_percent' => '1']);
        self::assertSame(1, $this->rows('reseller'), 'one profile per customer');

        $id = $reseller->getId();
        $this->web->request('POST', "/admin/resellers/$id/update", ['_token' => $token, 'discount_percent' => '20', 'daily_spend_limit' => '', 'display_name' => 'Ali', 'note' => 'VIP']);
        $reseller = $this->fresh(Reseller::class, (int) $reseller->getId());
        self::assertSame(2000, $reseller->getDiscountBp());
        self::assertNull($reseller->getDailySpendLimit());

        $plan = $this->plan;
        $this->web->request('POST', "/admin/resellers/$id/plan-price", ['_token' => $token, 'plan_id' => $plan->getId(), 'kind' => 'percent', 'value' => '90']);
        $page = $this->web->followRedirect();
        self::assertCount(1, $page->filter('[data-rule-plan="'.$plan->getId().'"] [data-clipped]'), 'the floor overrides 90 %');
        self::assertStringContainsString('alert-warning', (string) $this->web->getResponse()->getContent());

        $custom = (new Plan())->setTitle('Custom')->setDurationDays(30)->setPrice(0)->setIsCustomizable(true)->setPricePerGb(1000)->setMinTrafficGb(1)->setMaxTrafficGb(10);
        $this->em->persist($custom);
        $this->em->flush();
        $this->web->request('POST', "/admin/resellers/$id/plan-price", ['_token' => $token, 'plan_id' => $custom->getId(), 'kind' => 'fixed', 'value' => '50000']);
        self::assertNull($this->em->getRepository(ResellerPlanPrice::class)->findOneBy(['plan' => $custom]), 'no fixed price on a customizable plan');

        $this->web->request('POST', "/admin/resellers/$id/plan-price/".$plan->getId().'/remove', ['_token' => $token]);
        self::assertSame(0, $this->rows('reseller_plan_price'));

        self::assertSame(['reseller.create', 'reseller.update', 'reseller.plan_price', 'reseller.plan_price'], $this->auditActions('reseller.'));
        $update = $this->em->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'reseller.update']) ?? self::fail('no reseller.update entry');
        self::assertSame(['discount_bp' => 1250, 'daily_spend_limit' => 2000000, 'display_name' => 'Ali shop', 'note' => null], $update->getDetails()['from'] ?? null);
        self::assertSame('web', $update->getSource());
    }

    public function testDisablingEndsMiniAppSessionsAndKeepsServicesRunning(): void
    {
        $reseller = $this->reseller();
        $this->authenticate(self::BUYER);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mini_app_session WHERE telegram_user_id = ? AND revoked_at IS NULL', [self::BUYER]));
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $token = $this->token('/admin/resellers/'.$reseller->getId(), '[data-status-form]');

        $this->web->request('POST', '/admin/resellers/'.$reseller->getId().'/disable', ['_token' => $token]);

        $reseller = $this->fresh(Reseller::class, (int) $reseller->getId());
        self::assertSame(ResellerStatus::DISABLED, $reseller->getStatus());
        self::assertNotNull($reseller->getDisabledAt());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mini_app_session WHERE telegram_user_id = ? AND revoked_at IS NULL', [self::BUYER]), 'signed out of the Mini App');
        self::assertSame(VpnServiceStatus::ACTIVE, $this->fresh(\App\Entity\VpnService::class, (int) $this->service->getId())->getStatus(), 'services already sold keep running');
        $audit = $this->em->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'reseller.disable']);
        self::assertSame(1, $audit?->getDetails()['sessions_revoked'] ?? null);

        $this->web->request('POST', '/admin/resellers/'.$reseller->getId().'/enable', ['_token' => $token]);
        $reseller = $this->fresh(Reseller::class, (int) $reseller->getId());
        self::assertTrue($reseller->isActive());
        self::assertSame(['reseller.disable', 'reseller.enable'], $this->auditActions('reseller.'));
    }

    public function testTheSettingsTabIsOffByDefaultAndValidates(): void
    {
        $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
        $page = $this->web->request('GET', '/admin/settings?tab=resellers');
        self::assertSame(200, $this->web->getResponse()->getStatusCode());
        self::assertCount(0, $page->filter('#reseller_enabled[checked]'), 'off by default');
        self::assertSame('50', $page->filter('#reseller_floor_percent')->attr('value'));
        self::assertSame('1000', $page->filter('#reseller_rounding')->attr('value'));
        $settings = self::getContainer()->get(ResellerSettings::class);

        $this->web->request('POST', '/admin/settings?tab=resellers', ['reseller_enabled' => 'on', 'reseller_floor_percent' => '120', 'reseller_rounding' => '1000']);
        self::assertFalse($settings->enabled(), 'a refused form saves nothing');
        $this->web->request('POST', '/admin/settings?tab=resellers', ['reseller_enabled' => 'on', 'reseller_floor_percent' => '40', 'reseller_rounding' => '0']);
        self::assertFalse($settings->enabled());

        $this->web->request('POST', '/admin/settings?tab=resellers', ['reseller_enabled' => 'on', 'reseller_floor_percent' => '37.5', 'reseller_rounding' => '500']);
        self::assertTrue($settings->enabled());
        self::assertSame(3750, $settings->floorBp());
        self::assertSame(500, $settings->rounding());
        self::assertSame(['settings.update'], $this->auditActions('settings.'));
    }

    public function testTheReportShowsWholesaleRetailAndQuotesSpreadsheetFormulas(): void
    {
        $reseller = $this->reseller()->setDisplayName('=HYPERLINK("x")');
        $this->em->flush();
        $order = $this->createOrder($this->buyer, $this->plan, 'new_service', 120000)
            ->attributeToReseller($reseller, 120000, 150000, 75000)->setStatus('provisioned')->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->createPayment($order, null, 'confirmed');
        $this->webAdmin(AdminUser::ROLE_SUPPORT);

        $page = $this->web->request('GET', '/admin/resellers/report');
        $row = $page->filter('[data-report-reseller="'.$reseller->getId().'"]');
        self::assertSame('120000', $row->filter('[data-wholesale]')->attr('data-wholesale'));
        self::assertSame('150000', $row->filter('[data-retail]')->attr('data-retail'));
        self::assertSame('30000', $row->filter('[data-discount-given]')->attr('data-discount-given'));

        $this->web->request('GET', '/admin/resellers/report?format=csv');
        $csv = (string) $this->web->getResponse()->getContent();
        self::assertStringContainsString("'=HYPERLINK", $csv, 'no formula from an admin-typed name');
        self::assertStringContainsString(',120000,150000,30000,0', $csv);
    }

    /** A reseller (here without a wallet yet) cannot be deleted as a customer: a clear refusal, not a key error. */
    public function testAResellersCustomerAccountIsNotDeleted(): void
    {
        $this->reseller();
        $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
        $customerId = (int) $this->buyer->getId();
        $token = (string) $this->web->request('GET', '/admin/customer/'.$customerId)->filter('input[name="token"]')->attr('value');

        $this->web->request('POST', '/admin/customer/'.$customerId.'/delete', ['token' => $token]);

        self::assertTrue($this->web->getResponse()->isRedirect(), (string) $this->web->getResponse()->getStatusCode());
        self::assertStringContainsString('مشتری #'.$customerId.' نماینده است', $this->web->followRedirect()->text());
        self::assertSame(1, $this->rows('reseller'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `user` WHERE id = ?', [$customerId]));
    }

    private function reseller(): Reseller
    {
        $reseller = (new Reseller($this->buyer))->setDiscountBp(2000);
        $this->em->persist($reseller);
        $this->em->flush();

        return $reseller;
    }

    /**
     * The entity as the database has it now (the web client clears the entity manager between requests).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function fresh(string $class, int $id): object
    {
        $this->em->clear();

        return $this->em->find($class, $id) ?? self::fail($class.' #'.$id.' is gone');
    }

    private function webAdmin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->web->loginUser($admin, 'main');

        return $admin;
    }

    private function token(string $page, string $form): string
    {
        return (string) $this->web->request('GET', $page)->filter($form.' input[name="_token"]')->attr('value');
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /**
     * @return list<string>
     */
    private function auditActions(string $prefix): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM admin_audit_log WHERE action LIKE ? ORDER BY id', [$prefix.'%']));
    }
}
