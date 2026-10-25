<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\ClaimAdminController;
use App\Entity\AdminUser;
use App\Entity\ServiceOwnershipEvent;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Reseller\Application\ResellerSettings;
use App\Security\AdminPermission;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Claim links in the web panel: SERVICES_VIEW (super admin, admin, support) sees every link and each service's links
 * and owner history; SERVICES_MANAGE (super admin, admin) makes a link (shown once), revokes one and returns a wrongly
 * claimed service to its reseller (reason required). Every write is POST + CSRF and audited, without the token.
 */
final class ClaimAdminTest extends IntegrationTestCase
{
    use ClaimFixtures;

    private KernelBrowser $web;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin_audit_log', 'admin_user'] as $table) {
            $this->em->getConnection()->executeStatement('DELETE FROM '.$table);
        }
        $this->setUpClaims();
        $this->web = new KernelBrowser(self::$kernel);
        $this->web->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
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
        $link = $this->makeLink();
        $this->webAdmin($role);
        $id = (int) $this->sold->getId();

        foreach (['/admin/claims', '/admin/claims?status=open', '/admin/claims/service/'.$id] as $path) {
            $page = $this->web->request('GET', $path);
            self::assertSame(200, $this->web->getResponse()->getStatusCode(), $role.' GET '.$path);
            self::assertStringNotContainsString($link->token, (string) $this->web->getResponse()->getContent());
        }
        self::assertSame($manage, $page->filter('[data-create-link-form]')->count() > 0, $role.' sees "make a link"');

        foreach (["/admin/claims/service/$id/link", '/admin/claims/'.$link->claim->getId().'/revoke', "/admin/claims/service/$id/return"] as $path) {
            $this->web->request('POST', $path, ['_token' => 'x', 'reason' => 'a reason']);
            self::assertSame($manage, 403 !== $this->web->getResponse()->getStatusCode(), $role.' POST '.$path);
        }
        self::assertSame('open', $this->claimStatus((int) $link->claim->getId()), 'a wrong token changes nothing');
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));
    }

    public function testEveryClaimRouteHasAPermission(): void
    {
        $routes = 0;
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'admin_claim')) {
                continue;
            }
            ++$routes;
            if (in_array('POST', $route->getMethods(), true)) {
                self::assertSame(AdminPermission::SERVICES_MANAGE, AdminCrudPermissions::forRoute($name), $name);
            } else {
                self::assertSame(AdminPermission::SERVICES_VIEW, AdminCrudPermissions::forController(ClaimAdminController::class)?->view, $name);
            }
        }
        self::assertSame(5, $routes);
    }

    public function testAnAdminMakesALinkThatIsShownOnceAndAudited(): void
    {
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $page = '/admin/claims/service/'.$this->sold->getId();
        $token = (string) $this->web->request('GET', $page)->filter('[data-create-link-form] input[name="_token"]')->attr('value');

        $answer = $this->web->request('POST', $page.'/link', ['_token' => $token]);

        self::assertSame(200, $this->web->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $this->web->getResponse()->headers->get('Cache-Control'));
        $link = (string) $answer->filter('[data-created-link] [data-link]')->text();
        self::assertMatchesRegularExpression('#^https://t\.me/'.self::BOT_USERNAME.'\?start=claim_[A-Za-z0-9_-]{43}$#D', $link);
        $claimToken = substr($link, -43);
        self::assertSame(hash('sha256', $claimToken), $this->em->getConnection()->fetchOne('SELECT token_hash FROM service_claim'));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT reseller_id FROM service_claim'), 'an admin link is nobody\'s reseller link');

        $this->web->request('GET', $page);
        self::assertStringNotContainsString($claimToken, (string) $this->web->getResponse()->getContent(), 'shown once');
        $audit = (string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'claim.create_admin'");
        self::assertNotSame('', $audit);
        self::assertStringNotContainsString($claimToken, $audit);

        // It works like any link.
        $this->claimWith($claimToken, '8701');
        self::assertSame($this->claimer('8701')->getId(), $this->ownerOf($this->sold));
    }

    public function testRevokeFromTheWeb(): void
    {
        $link = $this->makeLink();
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $page = '/admin/claims/service/'.$this->sold->getId();
        $token = (string) $this->web->request('GET', $page)->filter('[data-revoke-form] input[name="_token"]')->attr('value');

        $this->web->request('POST', '/admin/claims/'.$link->claim->getId().'/revoke', ['_token' => $token]);
        self::assertSame(302, $this->web->getResponse()->getStatusCode());
        self::assertSame('revoked', $this->claimStatus((int) $link->claim->getId()));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'claim.revoke_admin'"));

        // Again: nothing changes, nothing more audited.
        $this->web->request('POST', '/admin/claims/'.$link->claim->getId().'/revoke', ['_token' => $token]);
        self::assertSame(['1'], array_map('strval', $this->em->getConnection()->fetchFirstColumn("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'claim.revoke_admin'")));
    }

    public function testTheWrongPersonClaimedItAndAnAdminReturnsItToTheReseller(): void
    {
        $this->claimWith($this->makeLink()->token, '8790');
        $wrong = $this->claimer('8790');
        $pageToken = $this->row('vpn_service', (int) $this->sold->getId())['public_token'];
        $this->driver()->ownerUpdates = [];
        $this->resetHttp();
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $page = '/admin/claims/service/'.$this->sold->getId();
        $token = (string) $this->web->request('GET', $page)->filter('[data-return-form] input[name="_token"]')->attr('value');

        // A reason is required.
        $this->web->request('POST', $page.'/return', ['_token' => $token, 'reason' => ' x ']);
        self::assertSame($wrong->getId(), $this->ownerOf($this->sold));

        $this->web->request('POST', $page.'/return', ['_token' => $token, 'reason' => 'Claimed by the wrong customer (ticket 42)']);

        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
        self::assertNotSame($pageToken, $this->row('vpn_service', (int) $this->sold->getId())['public_token'], 'the wrong person\'s page link stops working');
        $event = $this->em->getRepository(ServiceOwnershipEvent::class)->findOneBy(['actorType' => ServiceOwnershipEvent::ACTOR_ADMIN]) ?? self::fail('no event');
        self::assertSame([$wrong->getId(), $this->user->getId(), 'Claimed by the wrong customer (ticket 42)'], [$event->getFromUser()->getId(), $event->getToUser()->getId(), $event->getReason()]);
        self::assertNull($event->getClaim());
        $audit = (string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'claim.revert'");
        self::assertStringContainsString('ticket 42', $audit);
        self::assertSame([['remoteId' => $this->row('vpn_service', (int) $this->sold->getId())['remote_id'], 'telegramId' => (int) self::CUSTOMER, 'comment' => sprintf('amoobot order #%d', (int) $this->sold->getOrder()?->getId())]], $this->driver()->ownerUpdates, 'the panel names the reseller again');
        self::assertNull(self::getContainer()->get(CustomerServiceFinder::class)->ownedBy($wrong, (int) $this->sold->getId()));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());

        // Not again (already with the reseller), and the reseller may hand it over again.
        $this->web->request('POST', $page.'/return', ['_token' => $token, 'reason' => 'twice']);
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_ownership_event WHERE actor_type = 'admin'"));
        $this->claimWith($this->makeLink()->token, '8791');
        self::assertSame($this->claimer('8791')->getId(), $this->ownerOf($this->sold));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testWithClaimLinksOffThePagesStayButNoLinkCanBeMade(): void
    {
        $this->makeLink();
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $page = $this->web->request('GET', '/admin/claims/service/'.$this->sold->getId());
        self::assertSame(200, $this->web->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('[data-claims-off]'));
        self::assertCount(0, $page->filter('[data-create-link-form]'));
        $token = (string) $page->filter('[data-revoke-form] input[name="_token"]')->attr('value');

        $this->web->request('POST', '/admin/claims/service/'.$this->sold->getId().'/link', ['_token' => $token]);
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));
    }

    private function webAdmin(string $role): void
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->web->loginUser($admin, 'main');
    }
}
