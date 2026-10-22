<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\AdminUser;
use App\Entity\User;
use App\Security\AdminPermission;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Part 5: wallets for admins. WALLETS_VIEW (super admin, admin, support) sees wallets and ledgers; WALLETS_MANAGE
 * (super admin, admin) adjusts with a reason and a confirmation, sets the credit limit and freezes. Every write goes
 * through WalletService and is audited with its source.
 */
final class WalletAdminTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private KernelBrowser $web;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
        $this->web = $this->client;
    }

    private function wallets(): WalletService
    {
        return self::getContainer()->get(WalletService::class);
    }

    private function walletId(User $user): int
    {
        return (int) $this->wallets()->walletFor($user)->getId();
    }

    private function balance(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$user->getId()]);
    }

    private function webAdmin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->web->loginUser($admin, 'main');

        return $admin;
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

    // --- web -------------------------------------------------------------------------------------------

    #[DataProvider('roles')]
    public function testWebAccessPerRoleAndRoute(string $role, bool $manage): void
    {
        $walletId = $this->walletId($this->buyer);
        $this->webAdmin($role);

        $this->web->request('GET', '/admin/wallets');
        self::assertSame(200, $this->web->getResponse()->getStatusCode(), $role.' list');
        $page = $this->web->request('GET', '/admin/wallets/'.$walletId);
        self::assertSame(200, $this->web->getResponse()->getStatusCode(), $role.' detail');
        self::assertSame($manage, $page->filter('[data-adjust-form]')->count() > 0, $role.' sees the adjustment form');

        foreach (['adjust', 'credit-limit', 'freeze', 'unfreeze'] as $action) {
            $this->web->request('POST', '/admin/wallets/'.$walletId.'/'.$action, ['_token' => 'x']);
            self::assertSame($manage, 403 !== $this->web->getResponse()->getStatusCode(), $role.' POST '.$action);
        }
        $dashboard = $this->web->request('GET', '/admin');
        self::assertCount(2, $dashboard->filter('[data-card="wallets"]'), $role.' dashboard wallet card');
    }

    public function testAnAdjustmentIsConfirmedReasonedAppliedOnceAndAudited(): void
    {
        $admin = $this->webAdmin(AdminUser::ROLE_ADMIN);
        $walletId = $this->walletId($this->buyer);
        $form = $this->web->request('GET', '/admin/wallets/'.$walletId)->filter('[data-adjust-form]');
        $fields = ['_token' => $form->filter('input[name="_token"]')->attr('value'), 'form_key' => $form->filter('input[name="form_key"]')->attr('value'), 'direction' => 'credit', 'amount' => '25000', 'reason' => 'Goodwill for the outage'];

        // Without a reason: refused, nothing written.
        $this->web->request('POST', '/admin/wallets/'.$walletId.'/adjust', ['reason' => ''] + $fields);
        self::assertSame(0, $this->balance($this->buyer));

        // First step: the confirmation page only.
        $confirm = $this->web->request('POST', '/admin/wallets/'.$walletId.'/adjust', $fields);
        self::assertCount(1, $confirm->filter('[data-adjust-confirm]'));
        self::assertSame(0, $this->balance($this->buyer), 'nothing before the confirmation');

        $this->web->request('POST', '/admin/wallets/'.$walletId.'/adjust', $fields + ['confirmed' => '1']);
        $this->web->request('POST', '/admin/wallets/'.$walletId.'/adjust', $fields + ['confirmed' => '1']);

        self::assertSame(25000, $this->balance($this->buyer), 'the same form applied once');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM wallet_transaction');
        self::assertSame(WalletTransactionType::ADJUSTMENT, $row['type']);
        self::assertSame('Goodwill for the outage', $row['note']);
        self::assertSame((int) $admin->getId(), (int) $row['created_by_admin_id']);
        self::assertSame('web', $row['created_by_source']);
        $audit = $this->em->getConnection()->fetchAllAssociative("SELECT * FROM admin_audit_log WHERE action = 'wallet.adjust'");
        self::assertCount(1, $audit);
        self::assertSame('web', $audit[0]['source']);
        self::assertStringContainsString('Goodwill', (string) $audit[0]['details']);
    }

    public function testADebitBeyondTheCreditIsRefused(): void
    {
        $this->webAdmin(AdminUser::ROLE_ADMIN);
        $walletId = $this->walletId($this->buyer);
        $form = $this->web->request('GET', '/admin/wallets/'.$walletId)->filter('[data-adjust-form]');

        $this->web->request('POST', '/admin/wallets/'.$walletId.'/adjust', ['_token' => $form->filter('input[name="_token"]')->attr('value'), 'form_key' => $form->filter('input[name="form_key"]')->attr('value'), 'direction' => 'debit', 'amount' => '1000', 'reason' => 'Test debit', 'confirmed' => '1']);

        self::assertSame(0, $this->balance($this->buyer));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
    }

    public function testCreditLimitAndFreezeAreSavedAndAudited(): void
    {
        $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
        $walletId = $this->walletId($this->buyer);
        $token = $this->web->request('GET', '/admin/wallets/'.$walletId)->filter('[data-credit-limit-form] input[name="_token"]')->attr('value');

        $this->web->request('POST', '/admin/wallets/'.$walletId.'/credit-limit', ['_token' => $token, 'credit_limit' => '40000']);
        $this->web->request('POST', '/admin/wallets/'.$walletId.'/freeze', ['_token' => $token]);

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM wallet WHERE id = ?', [$walletId]);
        self::assertSame(40000, (int) $row['credit_limit']);
        self::assertSame(WalletStatus::FROZEN, $row['status']);
        self::assertSame(['wallet.credit_limit', 'wallet.freeze'], $this->em->getConnection()->fetchFirstColumn("SELECT action FROM admin_audit_log WHERE action LIKE 'wallet.%' ORDER BY id"));

        $this->web->request('POST', '/admin/wallets/'.$walletId.'/unfreeze', ['_token' => 'wrong']);
        self::assertSame(WalletStatus::FROZEN, $this->em->getConnection()->fetchOne('SELECT status FROM wallet WHERE id = ?', [$walletId]), 'no change without a valid CSRF token');
    }

    public function testTheListShowsTotalsAndTheLedgerShowsEveryRow(): void
    {
        $this->wallets()->credit($this->buyer, 80000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());
        $this->wallets()->setCreditLimit($this->buyer, 30000, WalletActor::system());
        $other = $this->createUser();
        $this->wallets()->setCreditLimit($other, 10000, WalletActor::system());
        $this->wallets()->debit($other, 7000, WalletTransactionType::PURCHASE, 'other-buy', WalletActor::system());
        $this->webAdmin(AdminUser::ROLE_SUPPORT);

        $list = $this->web->request('GET', '/admin/wallets');
        $totals = $list->filter('[data-wallet-totals]')->text();
        self::assertStringContainsString('80,000', $totals, 'outstanding');
        self::assertStringContainsString('7,000', $totals, 'credit in use');
        self::assertStringContainsString('40,000', $totals, 'credit granted');
        self::assertCount(2, $list->filter('[data-wallet-id]'));

        $detail = $this->web->request('GET', '/admin/wallets/'.$this->walletId($other));
        self::assertCount(1, $detail->filter('[data-transaction-id]'));
    }

    // --- Mini App ------------------------------------------------------------------------------------

    public function testTheCustomerDetailShowsTheWalletToViewersAndTheFormToManagers(): void
    {
        $this->wallets()->credit($this->buyer, 12000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());

        $support = new Crawler($this->appGet('/app/admin/customers/'.$this->buyer->getId(), $this->authenticate(self::SUPPORT)));
        self::assertSame('12000', $support->filter('[data-wallet-balance]')->attr('data-wallet-balance'));
        self::assertCount(0, $support->filter('[data-wallet-adjust]'));

        $admin = new Crawler($this->appGet('/app/admin/customers/'.$this->buyer->getId(), $this->authenticate(self::ADMIN)));
        self::assertCount(1, $admin->filter('[data-wallet-adjust]'));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, $admin->filter('[data-customer-wallet]')->text(), 'no raw translation keys');

        $ledger = new Crawler($this->appGet('/app/admin/customers/'.$this->buyer->getId().'/wallet', $this->authenticate(self::SUPPORT)));
        self::assertCount(1, $ledger->filter('[data-transaction-id]'));
    }

    public function testAMiniAppAdjustmentIsConfirmedOnceAndAudited(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $path = '/app/api/admin/customers/'.$this->buyer->getId().'/wallet/adjust';
        $key = self::newKey();

        $answer = $this->confirmedPost($path, $session, ['direction' => 'credit', 'amount' => '۱۵۰۰۰', 'reason' => 'Refund by hand'], $key);
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $this->appPostJson($path, $session, ['direction' => 'credit', 'amount' => '15000', 'reason' => 'Refund by hand'], $key);

        self::assertSame(15000, $this->balance($this->buyer), 'one Idempotency-Key, one adjustment');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM wallet_transaction');
        self::assertSame('miniapp', $row['created_by_source']);
        self::assertSame((int) $this->adminUser->getId(), (int) $row['created_by_admin_id']);
        $audit = array_values(array_filter($this->auditRows(), static fn (array $r): bool => 'wallet.adjust' === $r['action']));
        self::assertCount(1, $audit);
        self::assertSame('miniapp', $audit[0]['source']);

        $this->appPostJson($path, $session, ['direction' => 'credit', 'amount' => '100', 'reason' => ''], self::newKey());
        self::assertSame(422, $this->httpStatus(), 'a reason is required');

        $this->appPostJson($path, $this->authenticate(self::SUPPORT), ['direction' => 'credit', 'amount' => '100', 'reason' => 'nope'], self::newKey());
        self::assertSame(403, $this->httpStatus(), 'support may not adjust');
        self::assertSame(15000, $this->balance($this->buyer));
    }

    public function testThePermissionsAreInTheMatrix(): void
    {
        $policy = self::getContainer()->get(\App\Security\AdminAccessPolicy::class);
        foreach ([AdminUser::ROLE_SUPER_ADMIN => [true, true], AdminUser::ROLE_ADMIN => [true, true], AdminUser::ROLE_SUPPORT => [true, false]] as $role => [$view, $manage]) {
            $actor = \App\Security\AdminActor::fromAdminUser((new AdminUser())->setEmail('m@x.test')->setRoles([$role])->setIsActive(true));
            self::assertSame($view, $policy->can($actor, AdminPermission::WALLETS_VIEW), $role);
            self::assertSame($manage, $policy->can($actor, AdminPermission::WALLETS_MANAGE), $role);
        }
    }
}
