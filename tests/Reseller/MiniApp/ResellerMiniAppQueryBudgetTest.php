<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Entity\ResellerCustomer;
use App\Entity\ResellerCustomerService;
use App\Entity\VpnService;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use App\Tests\Support\ResellerMiniAppFixtures;

/**
 * The reseller screens run a fixed number of queries, whatever the number of orders, services and labels: the same
 * count with 1 sold service as with many (aggregates and joins, never a query per row).
 */
final class ResellerMiniAppQueryBudgetTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testDashboardListsAndLabelsDoNotGrowWithTheData(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $label = new ResellerCustomer($this->reseller, 'One', null, null, new \DateTimeImmutable());
        $this->em->persist($label);
        $this->em->persist(new ResellerCustomerService($label, $this->sold, new \DateTimeImmutable()));
        $this->em->flush();
        $pages = ['/app/view/reseller', '/app/view/reseller/services', '/app/view/reseller/services?tab=expiring', '/app/view/reseller/customers', '/app/view/reseller/customers/'.$label->getId(), '/app/view/reseller/links'];
        $this->claimLinks()->createForReseller($this->user, (int) $this->sold->getId());
        // Once to warm up (per-process caches: settings, the session's last-seen write), then measured.
        $this->counts($session, $pages);
        $before = $this->counts($session, $pages);

        // Many more orders, services, labels, assignments and links.
        $this->fund(2_000_000);
        for ($i = 0; $i < 6; ++$i) {
            $this->buy();
        }
        $labelId = (int) $label->getId();
        $services = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM vpn_service WHERE sold_by_reseller_id = ?', [$this->reseller->getId()]);
        foreach ($services as $n => $serviceId) {
            $this->em->getConnection()->insert('reseller_customer', ['reseller_id' => $this->reseller->getId(), 'name' => 'L'.$n, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            $this->em->getConnection()->executeStatement('INSERT IGNORE INTO reseller_customer_service (customer_id, service_id, assigned_at) VALUES (?, ?, NOW())', [$labelId, $serviceId]);
            $service = $this->em->find(VpnService::class, (int) $serviceId);
            if ($service instanceof VpnService && $service->getUser()->getId() === $this->user->getId()) {
                $this->claimLinks()->createForReseller($this->user, (int) $serviceId);
            }
        }
        self::assertGreaterThanOrEqual(7, count($services));
        $after = $this->counts($session, $pages);

        self::assertSame($before, $after);
        foreach ($after as $page => $count) {
            self::assertLessThanOrEqual(20, $count, $page);
        }
    }

    /**
     * @param array{token: string, csrf: string} $session
     * @param list<string>                       $pages
     *
     * @return array<string, int>
     */
    private function counts(array $session, array $pages): array
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $counts = [];
        foreach ($pages as $page) {
            $this->em->clear();
            $counter->start();
            $this->appGet($page, $session);
            $counts[$page] = $counter->stop();
            self::assertSame(200, $this->httpStatus(), $page);
        }

        return $counts;
    }
}
