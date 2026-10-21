<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\BroadcastMessage;
use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Entity\VpnPanel;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * The in-bot store management that replaced the web links: what each role sees, and each screen and change allowed
 * or refused, confirmed first, audited (source bot, the linked admin).
 */
final class BotAdminStoreTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const SUPER = '7201';
    private const ADMIN = '7202';
    private const SUPPORT = '7203';
    private const INACTIVE = '7204';
    private const STRANGER = '7299';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        foreach (['broadcast_delivery', 'broadcast_message', 'trial_claim', 'trial_plan', 'admin_audit_log', 'admin_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $this->linkedAdmin('store-super@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::SUPER);
        $this->linkedAdmin('store-admin@shop.test', AdminUser::ROLE_ADMIN, self::ADMIN);
        $this->linkedAdmin('store-support@shop.test', AdminUser::ROLE_SUPPORT, self::SUPPORT);
        $this->linkedAdmin('store-off@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::INACTIVE, false);
    }

    protected function tearDown(): void
    {
        // Rows outside IntegrationTestCase's purge list: other tests would see them orphaned.
        $connection = $this->em->getConnection();
        foreach (['broadcast_delivery', 'broadcast_message', 'trial_claim', 'trial_plan'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement("DELETE FROM discount_code WHERE code LIKE 'BOT%'");
        parent::tearDown();
    }

    public function testTheStoreMenuPerRole(): void
    {
        $everything = ['admin.dash', 'admin.o:l:0:a', 'admin.p:pend:0', 'admin.p:att:0', 'admin.c:l:0', 'admin.pl:l:0', 'admin.pl:tc:0', 'admin.d:l:0', 'admin.m:l', 'admin.m:g', 'admin.s:l', 'admin.pn:l', 'admin.b:l', 'admin.menu'];
        $expected = [
            self::SUPER => $everything,
            self::ENV_ADMIN => $everything,
            self::ADMIN => ['admin.dash', 'admin.o:l:0:a', 'admin.p:pend:0', 'admin.p:att:0', 'admin.c:l:0', 'admin.pl:l:0', 'admin.pl:tc:0', 'admin.d:l:0', 'admin.b:l', 'admin.menu'],
            self::SUPPORT => ['admin.dash', 'admin.o:l:0:a', 'admin.p:pend:0', 'admin.p:att:0', 'admin.c:l:0', 'admin.menu'],
        ];
        foreach ($expected as $telegramId => $callbacks) {
            $this->http->reset();
            $this->pressButton((string) $telegramId, 'admin.store_menu');
            self::assertSame($callbacks, $this->sentCallbacks(), 'store menu for '.$telegramId);
        }
        foreach ([self::INACTIVE, self::STRANGER] as $telegramId) {
            $this->http->reset();
            $this->pressButton($telegramId, 'admin.store_menu');
            self::assertTrue($this->refused());
            self::assertSame([], $this->sentCallbacks());
        }
    }

    public function testEveryScreenIsRefusedWithoutItsPermission(): void
    {
        $support = ['admin.pl:l:0', 'admin.pl:t:1', 'admin.pl:tk:1:0', 'admin.pl:pr:1', 'admin.pl:pk:1:90000', 'admin.pl:bulk', 'admin.pl:bk:+10', 'admin.pl:tc:0', 'admin.d:l:0', 'admin.d:tk:1:0', 'admin.m:l', 'admin.m:tk:1:0', 'admin.m:uk:1', 'admin.m:g', 'admin.m:gk:1:0', 'admin.s:l', 'admin.s:tk:0:0', 'admin.s:nk:11:5', 'admin.pn:l', 'admin.pn:x:1', 'admin.pn:tk:1:0', 'admin.b:l', 'admin.b:ck:1', 'admin.b:rk:1', 'admin.p:fk:1', 'admin.p:ak:1', 'admin.p:rk:1'];
        foreach ($support as $data) {
            $this->http->reset();
            $this->pressButton(self::SUPPORT, $data);
            self::assertTrue($this->refused(), 'support '.$data);
        }
        foreach (['admin.m:l', 'admin.m:gk:1:0', 'admin.s:l', 'admin.s:tk:0:0', 'admin.pn:l', 'admin.pn:x:1'] as $data) {
            $this->http->reset();
            $this->pressButton(self::ADMIN, $data);
            self::assertTrue($this->refused(), 'admin '.$data);
        }
        foreach (['admin.o:l:0:a', 'admin.dash', 'admin.zz:l'] as $data) {
            $this->http->reset();
            $this->pressButton(self::STRANGER, $data);
            self::assertTrue($this->refused(), 'stranger '.$data);
            self::assertSame([], $this->sentCallbacks(), 'nothing shown to a stranger');
        }
        self::assertSame(0, $this->em->getRepository(AdminAuditLog::class)->count([]), 'nothing changed');
    }

    public function testDashboardAndOrderPaging(): void
    {
        $user = $this->createUser();
        $plan = $this->createPlan();
        for ($i = 0; $i < 23; ++$i) {
            $this->createOrder($user, $plan);
        }
        $paid = $this->createOrder($user, $plan)->setStatus(OrderStatus::PAID);
        $this->em->flush();

        $this->pressButton(self::SUPPORT, 'admin.dash');
        self::assertTrue($this->sentTextContains('24'), 'orders today');

        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin.o:l:0:a');
        self::assertContains('admin.o:l:1:a', $this->sentCallbacks());
        self::assertNotContains('admin.o:l:-1:a', $this->sentCallbacks());
        self::assertTrue($this->sentTextContains('1 از 3'));

        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin.o:l:2:a');
        self::assertContains('admin.o:l:1:a', $this->sentCallbacks());
        self::assertNotContains('admin.o:l:3:a', $this->sentCallbacks());
        self::assertSame(4, count(array_filter($this->sentCallbacks(), static fn (string $c): bool => str_starts_with($c, 'admin.o:v:'))), 'last page: 24 - 20');

        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin.o:l:99:a');
        self::assertTrue($this->sentTextContains('3 از 3'), 'a page past the end shows the last one');

        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin.o:l:0:'.array_search(OrderStatus::PAID, OrderStatus::ALL, true));
        self::assertSame(['admin.o:v:'.$paid->getId()], array_values(array_filter($this->sentCallbacks(), static fn (string $c): bool => str_starts_with($c, 'admin.o:v:'))));

        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin_orders');
        self::assertContains('admin.o:l:1:a', $this->sentCallbacks(), 'the old orders button opens the same list');

        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'admin.o:v:'.$paid->getId());
        self::assertTrue($this->sentTextContains('سفارش #'.$paid->getId()));
    }

    public function testPlanToggleIsConfirmedAndAudited(): void
    {
        $plan = $this->createPlan(120000);
        $id = (int) $plan->getId();

        $this->pressButton(self::ADMIN, 'admin.pl:t:'.$id);
        self::assertContains('admin.pl:tk:'.$id.':0', $this->sentCallbacks(), 'asks first');
        self::assertTrue($this->reloadPlan($id)->isActive());

        $this->pressButton(self::ADMIN, 'admin.pl:tk:'.$id.':0');
        self::assertFalse($this->reloadPlan($id)->isActive());
        $this->assertAudited('plan.deactivate', 'Plan', (string) $id);

        // Pressing the same confirmation again does not toggle back.
        $this->http->reset();
        $this->pressButton(self::ADMIN, 'admin.pl:tk:'.$id.':0');
        self::assertFalse($this->reloadPlan($id)->isActive());
        self::assertTrue($this->sentTextContains($this->botText('admin.state_changed')));
    }

    public function testDiscountsMethodsGatewaysAndBroadcasts(): void
    {
        $code = (new DiscountCode())->setCode('BOT10')->setType(DiscountCode::TYPE_PERCENT)->setValue(10);
        $this->em->persist($code);
        $usage = (new DiscountUsage())->setDiscountCode($code)->setUser($this->createUser())->setAmountBefore(1000)->setDiscountAmount(100)->setAmountAfter(900)->setUsedAt(new \DateTimeImmutable());
        $this->em->persist($usage);
        $this->em->flush();

        $this->pressButton(self::ADMIN, 'admin.d:l:0');
        self::assertTrue($this->sentTextContains('BOT10'));
        self::assertTrue($this->sentTextContains('استفاده: 1'));
        $this->pressButton(self::ADMIN, 'admin.d:tk:'.$code->getId().':0');
        $this->em->clear();
        self::assertFalse($this->em->find(DiscountCode::class, $code->getId())?->isActive());
        $this->assertAudited('discount_code.deactivate', 'DiscountCode', (string) $code->getId());

        // Payment methods: order and on/off (super admin only).
        $gateway = $this->createGateway();
        $first = (new StorePaymentMethod())->setGateway($gateway)->setTitle('First')->setCurrency('IRR')->setIsActive(true)->setSortOrder(0);
        $second = (new StorePaymentMethod())->setGateway($gateway)->setTitle('Second')->setCurrency('IRR')->setIsActive(true)->setSortOrder(0);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $this->pressButton(self::SUPER, 'admin.m:u:'.$second->getId());
        self::assertContains('admin.m:uk:'.$second->getId(), $this->sentCallbacks());
        $this->pressButton(self::SUPER, 'admin.m:uk:'.$second->getId());
        $this->em->clear();
        self::assertLessThan($this->em->find(StorePaymentMethod::class, $first->getId())?->getSortOrder(), $this->em->find(StorePaymentMethod::class, $second->getId())?->getSortOrder());
        $this->assertAudited('store_payment_method.move', 'StorePaymentMethod', (string) $second->getId());

        $this->pressButton(self::SUPER, 'admin.m:gk:'.$gateway->getId().':0');
        $this->em->clear();
        self::assertFalse($this->em->find(PaymentGateway::class, $gateway->getId())?->isActive());
        $this->assertAudited('payment_gateway.deactivate', 'PaymentGateway', (string) $gateway->getId());

        // Broadcasts: cancel and retry failed.
        $broadcast = (new BroadcastMessage())->setTitle('Promo')->setBody('Hi')->setTargetType(BroadcastMessage::TARGET_ALL)->setStatus(BroadcastMessage::STATUS_SENDING)->setFailedCount(1)->setTotalRecipients(1);
        $this->em->persist($broadcast);
        $this->em->flush();
        $this->http->reset();
        $this->pressButton(self::ADMIN, 'admin.b:l');
        self::assertContains('admin.b:c:'.$broadcast->getId(), $this->sentCallbacks());
        self::assertContains('admin.b:r:'.$broadcast->getId(), $this->sentCallbacks());
        $this->pressButton(self::ADMIN, 'admin.b:ck:'.$broadcast->getId());
        $this->em->clear();
        self::assertSame(BroadcastMessage::STATUS_CANCELLED, $this->em->find(BroadcastMessage::class, $broadcast->getId())?->getStatus());
        $this->assertAudited('broadcast.cancel', 'BroadcastMessage', (string) $broadcast->getId());
    }

    public function testRejectAsRefunded(): void
    {
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), null, PaymentStatus::NEEDS_REVIEW);
        $id = (int) $payment->getId();

        $this->pressButton(self::ADMIN, 'admin.p:v:'.$id);
        self::assertContains('admin.p:rf:'.$id, $this->sentCallbacks());
        $this->pressButton(self::ADMIN, 'admin.p:rf:'.$id);
        self::assertContains('admin.p:fk:'.$id, $this->sentCallbacks());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', $id)['status']);

        $this->pressButton(self::ADMIN, 'admin.p:fk:'.$id);
        $row = $this->row('payment', $id);
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertNotNull($row['refunded_at']);
        $log = $this->assertAudited('payment.reject', 'Payment', (string) $id);
        self::assertTrue($log->getDetails()['refunded'] ?? null);
    }

    public function testPanelTestAndToggle(): void
    {
        $fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Bot panel')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN);
        $this->em->persist($panel);
        $this->em->flush();

        $this->pressButton(self::SUPER, 'admin.pn:x:'.$panel->getId());
        self::assertTrue($this->sentTextContains($this->botText('admin.panels.test_ok')));
        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $text);
            self::assertStringNotContainsString('/secret/', $text, 'no panel URL in the bot');
        }
        $this->assertAudited('vpn_panel.test', 'VpnPanel', (string) $panel->getId());

        $this->pressButton(self::SUPER, 'admin.pn:tk:'.$panel->getId().':0');
        $this->em->clear();
        self::assertFalse($this->em->find(VpnPanel::class, $panel->getId())?->isActive());
    }

    public function testTrialClaimsAndOldWebLinks(): void
    {
        $trialPlan = (new TrialPlan())->setTitle('Free day')->setDurationDays(1)->setTrafficGb(1);
        $this->em->persist($trialPlan);
        $claim = (new TrialClaim())->setTelegramAccount($this->createUser('5551234')->getTelegramAccount())->setTrialPlan($trialPlan)->setStatus(TrialClaim::STATUS_PROVISIONED);
        $this->em->persist($claim);
        $this->em->flush();

        $this->pressButton(self::ADMIN, 'admin.pl:tc:0');
        self::assertTrue($this->sentTextContains('Free day'));
        self::assertTrue($this->sentTextContains('5551234'));

        // A "store management" button from an old message: no link any more.
        $this->http->reset();
        $this->pressButton(self::SUPER, 'admin.store.plans');
        self::assertTrue($this->sentTextContains($this->botText('admin.web_only')));
        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsString('http', $text);
        }
    }

    private function reloadPlan(int $id): Plan
    {
        $this->em->clear();
        $plan = $this->em->find(Plan::class, $id);
        self::assertInstanceOf(Plan::class, $plan);

        return $plan;
    }

    private function assertAudited(string $action, string $targetType, string $targetId): AdminAuditLog
    {
        $this->em->clear();
        $logs = $this->em->getRepository(AdminAuditLog::class)->findBy(['action' => $action, 'targetType' => $targetType, 'targetId' => $targetId]);
        self::assertCount(1, $logs, $action);
        self::assertSame(AdminAuditLog::SOURCE_BOT, $logs[0]->getSource());
        self::assertNotNull($logs[0]->getActorAdminUserId(), 'the linked admin');

        return $logs[0];
    }
}
