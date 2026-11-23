<?php

declare(strict_types=1);

namespace App\Tests\Admin\Dashboard;

use App\Admin\Application\{CardReceiptQueue, PaymentTabs, ServiceTabs, GlobalAdminSearch};
use App\Admin\UI\Dashboard\{AdminCalendar, DashboardOverview};
use App\Admin\UI\Shell\AdminUiMode;
use App\Entity\{AdminUser, Wallet, WalletTransaction};
use App\Payment\Application\Repair\StuckPaymentFinder;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminActor;
use App\Shop\Domain\OrderType;
use App\Tests\Support\{IntegrationTestCase, QueryCounter};
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ModernDashboardTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [AdminUiMode::SETTING]);
        parent::tearDown();
    }

    private function overview(): DashboardOverview { return self::getContainer()->get(DashboardOverview::class); }
    private function actor(string $role = AdminUser::ROLE_SUPER_ADMIN): AdminActor
    {
        return AdminActor::fromAdminUser((new AdminUser())->setEmail('ad2@example.com')->setRoles([$role])->setIsActive(true));
    }

    public function testTehranMidnightAndLocalizedDates(): void
    {
        $calendar = new AdminCalendar();
        $instant = new \DateTimeImmutable('2026-11-22 21:00:00 UTC');
        self::assertSame('2026-11-22 20:30:00', $calendar->midnight($instant)->format('Y-m-d H:i:s'));
        self::assertSame('2026/11/23', $calendar->date($instant, 'en'));
        self::assertSame("\u{06F1}\u{06F4}\u{06F0}\u{06F5}/\u{06F0}\u{06F9}/\u{06F0}\u{06F2}", $calendar->date($instant, 'fa'));
    }

    public function testRevenueRangeAndSevenDaysUseLedgerCreditAndExcludeWalletSpending(): void
    {
        $user = $this->createUser(); $plan = $this->createPlan(1000, $this->createTestInbound());
        $from = new \DateTimeImmutable('2026-11-22 20:30:00 UTC');
        foreach ([1000, 2000, 3000] as $i => $amount) {
            $order = $this->createOrder($user, $plan, 2 === $i ? OrderType::WALLET_TOPUP : OrderType::NEW_SERVICE, $amount);
            $payment = $this->createPayment($order, status: PaymentStatus::CONFIRMED)->setConfirmedAt(new \DateTimeImmutable('2026-11-22 21:00:00 UTC'));
            if (1 === $i) { $payment->setMethod('wallet'); }
            if (2 === $i) {
                $wallet = new Wallet($user); $this->em->persist($wallet);
                $this->em->persist(new WalletTransaction($wallet, 'topup', 2900, 2900, 'topup:payment:'.$payment->getId(), 'system'));
            }
        }
        $this->em->flush();
        self::assertSame(['payments' => 3, 'revenue' => 3900], $this->overview()->range($from, $from->modify('+1 day')));
        self::assertSame(['payments' => 0, 'revenue' => 0], $this->overview()->range($from->modify('-1 day'), $from));
        $days = $this->overview()->sevenDays($from);
        self::assertCount(7, $days); self::assertSame(3900, $days[6]['revenue']); self::assertSame(0, $days[5]['revenue']);
        self::assertCount(3, $this->overview()->latestPayments());
    }

    public function testExpiryTodayIsHalfOpenTehranCalendarDay(): void
    {
        $user = $this->createUser();
        $today = new \DateTimeImmutable('2026-11-22 20:30:00 UTC');
        $this->createActiveService($user)->setExpiresAt((new \DateTimeImmutable('2026-11-23 23:59:00 Asia/Tehran'))->setTimezone(new \DateTimeZone('UTC')));
        $this->createActiveService($user)->setExpiresAt((new \DateTimeImmutable('2026-11-24 00:01:00 Asia/Tehran'))->setTimezone(new \DateTimeZone('UTC')));
        $this->createActiveService($user)->setExpiresAt((new \DateTimeImmutable('2026-11-24 00:00:00 Asia/Tehran'))->setTimezone(new \DateTimeZone('UTC')));
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET created_at = ?', ['2026-11-22 21:00:00']);
        $counts = ServiceTabs::countByTab($this->em, $today, $today);
        self::assertSame(1, $counts['expiring_today']); self::assertSame(3, $counts['active']); self::assertSame(3, $counts['new_today']);
    }

    public function testReceiptFilterAndCountMatchAndStuckCountDoesNotCallPanels(): void
    {
        $user = $this->createUser(); $plan = $this->createPlan(1000, $this->createTestInbound());
        $order = $this->createOrder($user, $plan);
        $receipt = $this->createPayment($order, status: PaymentStatus::PENDING)->setTrackingCode('abc');
        $this->createPayment($order, status: PaymentStatus::NEEDS_REVIEW)->setReceiptFileId('photo');
        $this->createPayment($this->createOrder($user, $plan), status: PaymentStatus::CONFIRMED)->setConfirmedAt(new \DateTimeImmutable('-2 hours'));
        $this->createPayment($this->createOrder($user, $plan, OrderType::WALLET_TOPUP), status: PaymentStatus::CONFIRMED)->setConfirmedAt(new \DateTimeImmutable('-2 hours'));
        $this->em->flush();
        $qb = $this->em->createQueryBuilder()->select('p.id')->from(\App\Entity\Payment::class, 'p');
        self::assertSame([['id' => $receipt->getId()]], PaymentTabs::apply($qb, 'p', 'receipts')->getQuery()->getArrayResult());
        self::assertSame(1, self::getContainer()->get(CardReceiptQueue::class)->count());
        self::assertSame(1, PaymentTabs::countByTab($this->em, true)['receipts']);
        self::assertSame(1, self::getContainer()->get(StuckPaymentFinder::class)->count(new \DateTimeImmutable('-30 minutes')));
    }

    public function testColdOverviewBudgetAndPermissionIsolation(): void
    {
        self::getContainer()->get('cache.app')->clear();
        $counter = self::getContainer()->get(QueryCounter::class);
        $overview = $this->overview();
        $counter->start(); $data = $overview->dashboard($this->actor()); $count = $counter->stop();
        self::assertLessThanOrEqual(12, $count); self::assertNull($data['change']); self::assertCount(7, $data['days']);
        foreach ([AdminUser::ROLE_SUPER_ADMIN, AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $data = $overview->dashboard($this->actor($role));
            // Same PAYMENTS_VIEW matrix as the classic dashboard, including support.
            self::assertNotNull($data['today']); self::assertNotNull($data['services']);
        }
        $counter->start(); $hidden = $overview->dashboard(AdminActor::console('unprivileged')); $counter->stop();
        self::assertNull($hidden['today']); self::assertNull($hidden['services']); self::assertNull($hidden['latest']); self::assertSame([], $hidden['worklist']);
    }



    public function testInboundAndFailedMoveRowsUseTheirExistingListFilters(): void
    {
        $user = $this->createUser(); $inbound = $this->createTestInbound(); $plan = $this->createPlan(1000, $inbound);
        $service = $this->createActiveService($user, $inbound);
        $this->em->persist(new \App\Entity\VpnServiceInbound($service, $inbound, new \DateTimeImmutable()));
        $run = new \App\Entity\InboundMoveRun($plan, 'primary', $inbound, [], 0, false, null, new \DateTimeImmutable());
        $this->em->persist($run);
        $move = new \App\Entity\ServiceInboundMove($run, $service, $inbound, $inbound, new \DateTimeImmutable());
        $this->em->persist($move); $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET state = 'failed' WHERE id = ?", [$move->getId()]);
        $this->em->getConnection()->executeStatement("INSERT INTO setting (key_name, value, type, created_at) VALUES ('plans.multi_inbound_enabled', '1', 'bool', NOW())");
        self::getContainer()->get('cache.app')->clear();
        $counter = self::getContainer()->get(QueryCounter::class);
        $counter->start(); $data = $this->overview()->dashboard($this->actor()); $count = $counter->stop();
        self::assertLessThanOrEqual(12, $count);
        $rows = array_column($data['worklist'], null, 'key');
        self::assertSame(1, $rows['inbounds']['count']); self::assertSame(['inbounds' => 'not_connected'], $rows['inbounds']['parameters']);
        self::assertSame(1, $rows['moves']['count']); self::assertSame(['moves' => 'failed'], $rows['moves']['parameters']);
        self::assertSame([], $this->http->requests());
    }

    public function testWorklistIsFreshWhileKpisAreCached(): void
    {
        self::getContainer()->get('cache.app')->clear();
        $actor = $this->actor(); $overview = $this->overview();
        $first = $overview->dashboard($actor);
        $user = $this->createUser(); $plan = $this->createPlan();
        $receipt = $this->createPayment($this->createOrder($user, $plan), status: PaymentStatus::PENDING)->setTrackingCode('received');
        $this->em->flush();
        $second = $overview->dashboard($actor);
        self::assertSame($first['customers'], $second['customers']);
        self::assertContains('receipts', array_column($second['worklist'], 'key'));
        $receipt->setStatus(PaymentStatus::CONFIRMED)->setConfirmedAt(new \DateTimeImmutable()); $this->em->flush();
        $third = $overview->dashboard($actor);
        self::assertNotContains('receipts', array_column($third['worklist'], 'key'));
        self::assertSame($first['today'], $third['today']);
    }

    public function testLatestPaymentsAreEightNewestAndNumericSearchHasFoundInChoices(): void
    {
        $user = $this->createUser(); $plan = $this->createPlan();
        $ids = [];
        for ($i = 0; $i < 10; ++$i) { $ids[] = $this->createPayment($this->createOrder($user, $plan))->getId(); }
        self::assertSame(array_slice(array_reverse($ids), 0, 8), array_column($this->overview()->latestPayments(), 'id'));
        $results = self::getContainer()->get(GlobalAdminSearch::class)->search($this->actor(), (string) $ids[9]);
        self::assertContains('admin_payment_detail', array_column($results, 'route'));
        self::assertSame([], self::getContainer()->get(GlobalAdminSearch::class)->search(AdminActor::console('nobody'), (string) $ids[9]));
    }

    public function testModernPageAndSearchAsEveryRole(): void
    {
        $user = $this->createUser(); $service = $this->createActiveService($user)->setUsername('ad2_user');
        $service->setExpiresAt((new \DateTimeImmutable('now', new \DateTimeZone(AdminCalendar::TIMEZONE)))->setTime(23, 59)->setTimezone(new \DateTimeZone('UTC')));
        $plan = $this->createPlan();
        $this->createPayment($this->createOrder($user, $plan), status: PaymentStatus::PENDING)->setTrackingCode('receipt');
        $this->createPayment($this->createOrder($user, $plan), status: PaymentStatus::PROVISIONING_FAILED);
        $this->createPayment($this->createOrder($user, $plan), status: PaymentStatus::NEEDS_REVIEW);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, 'string', NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value)", [AdminUiMode::SETTING, 'modern']);
        $this->em->getConnection()->executeStatement("INSERT INTO setting (key_name, value, type, created_at) VALUES ('plans.multi_inbound_enabled', '1', 'bool', NOW())");
        $client = new KernelBrowser(self::$kernel); $client->disableReboot();
        foreach ([AdminUser::ROLE_SUPER_ADMIN, AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $admin = (new AdminUser())->setEmail(strtolower($role).bin2hex(random_bytes(4)).'@ad2.test')->setRoles([$role])->setIsActive(true)->setPassword('unused');
            $this->em->persist($admin); $this->em->flush(); $client->loginUser($admin, 'main');
            self::getContainer()->get(AdminUiMode::class)->reset();
            self::getContainer()->get('cache.app')->clear();
            $counter = self::getContainer()->get(QueryCounter::class); $counter->start();
            $client->request('GET', '/admin');
            self::assertLessThanOrEqual(12, $counter->stop(), $role.' cold full page, multi-inbound enabled');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('ad2-dashboard', (string) $client->getResponse()->getContent());
            $crawler = new \Symfony\Component\DomCrawler\Crawler((string) $client->getResponse()->getContent());
            $links = $crawler->filter('.ad2-worklist a')->each(static fn ($node): string => $node->attr('href'));
            self::assertCount(4, $links);
            foreach ($links as $link) {
                $client->request('GET', $link); self::assertSame(200, $client->getResponse()->getStatusCode(), $link);
            }
            $client->request('GET', '/admin/search?q=ad2_user'); self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('/admin/vpn-service/'.$service->getId(), (string) $client->getResponse()->getContent());
        }
        self::assertSame([], self::getContainer()->get(GlobalAdminSearch::class)->search(AdminActor::console('unprivileged'), 'ad2_user'));
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [AdminUiMode::SETTING]);
    }
}
