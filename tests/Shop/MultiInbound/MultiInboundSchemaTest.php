<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Plan;
use App\Entity\PlanAdditionalInbound;
use App\Entity\VpnInbound;
use App\Entity\VpnServiceInbound;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The M1 tables on MariaDB (docs/design/multi-inbound-plans.md 2.1-2.2): a row never blocks deleting its plan, its
 * service or the user it goes with (CASCADE); the inbound is RESTRICT. Plan::getInbounds() is the primary first, then the
 * additional ones by their order; a plan with any additional inbound is not sold (fail closed until provisioning
 * supports them), and the catalog list stays one query.
 */
final class MultiInboundSchemaTest extends IntegrationTestCase
{
    public function testDeletingThePlanRemovesItsRows(): void
    {
        [$plan] = $this->planWithAdditional();

        $this->em->getConnection()->executeStatement('DELETE FROM plan WHERE id = ?', [$plan->getId()]);

        self::assertSame(0, $this->rows('plan_additional_inbound'));
    }

    public function testDeletingAServiceOrItsUserRemovesItsRows(): void
    {
        [$primary, $second, $third] = $this->inbounds(3);
        $user = $this->createUser();
        $service = $this->createActiveService($user, $primary);
        $other = $this->createActiveService($user, $primary);
        $this->em->persist(new VpnServiceInbound($service, $second, new \DateTimeImmutable()));
        $this->em->persist(new VpnServiceInbound($other, $third, new \DateTimeImmutable()));
        $this->em->flush();

        $this->em->getConnection()->executeStatement('DELETE FROM vpn_service WHERE id = ?', [$service->getId()]);
        self::assertSame(1, $this->rows('vpn_service_inbound'));
        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$user->getId()]);
        self::assertSame(0, $this->rows('vpn_service_inbound'), 'the user goes with his services and their rows');
    }

    public function testAnInboundAPlanSellsOrAServiceHoldsCannotBeDeleted(): void
    {
        [, $additional] = $this->planWithAdditional();

        try {
            $this->em->getConnection()->executeStatement('DELETE FROM vpn_inbound WHERE id = ?', [$additional->getId()]);
            self::fail('the inbound of a plan was deleted');
        } catch (ForeignKeyConstraintViolationException) {
        }

        [$primary, $held] = $this->inbounds(2);
        $service = $this->createActiveService($this->createUser(), $primary);
        $this->em->persist(new VpnServiceInbound($service, $held, new \DateTimeImmutable()));
        $this->em->flush();
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('DELETE FROM vpn_inbound WHERE id = ?', [$held->getId()]);
    }

    public function testTheSamePlanAndInboundCannotBeStoredTwice(): void
    {
        [$plan, $additional] = $this->planWithAdditional();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->insert('plan_additional_inbound', ['plan_id' => $plan->getId(), 'inbound_id' => $additional->getId(), 'sort_order' => 2, 'created_at' => '2026-01-01 00:00:00']);
    }

    public function testGetInboundsIsThePrimaryThenTheAdditionalOnesInOrder(): void
    {
        [$primary, $a, $b, $c] = $this->inbounds(4);
        $plan = $this->createPlan(100000, $primary);
        $plan->setAdditionalInbounds([$c, $a, $c, $b]);
        $this->em->flush();
        $this->em->clear();

        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan gone');
        self::assertSame([$primary->getId(), $c->getId(), $a->getId(), $b->getId()], array_map(static fn (VpnInbound $i): ?int => $i->getId(), $plan->getInbounds()), 'a repeat is kept once');

        // Reordering keeps the rows of inbounds that stay (no delete + insert on the unique key), drops the others.
        $ids = $this->em->getConnection()->fetchAllKeyValue('SELECT inbound_id, id FROM plan_additional_inbound');
        $plan->setAdditionalInbounds([$this->em->find(VpnInbound::class, $b->getId()), $this->em->find(VpnInbound::class, $c->getId())]);
        $this->em->flush();
        $after = $this->em->getConnection()->fetchAllKeyValue('SELECT inbound_id, id FROM plan_additional_inbound');
        ksort($after);
        $expected = [$b->getId() => (int) $ids[$b->getId()], $c->getId() => (int) $ids[$c->getId()]];
        ksort($expected);
        self::assertSame($expected, array_map('intval', $after), 'kept rows keep their id; the dropped one is gone');
        $this->em->clear();
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan gone');
        self::assertSame([$primary->getId(), $b->getId(), $c->getId()], array_map(static fn (VpnInbound $i): ?int => $i->getId(), $plan->getInbounds()));
    }

    public function testAPlanWithAdditionalInboundsIsNotSoldWhateverTheSwitch(): void
    {
        $single = $this->createPlan(100000, $this->inbounds(1)[0]);
        [$multi] = $this->planWithAdditional();
        foreach (['0', '1'] as $switch) {
            $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', ['plans.multi_inbound_enabled']);
            $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => $switch, 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
            $this->em->clear();
            $catalog = self::getContainer()->get(PlanCatalog::class);

            self::assertSame([$single->getId()], array_map(static fn (Plan $p): ?int => $p->getId(), $catalog->forSale()), 'switch '.$switch);
            self::assertNull($catalog->findForSale((int) $multi->getId()));
            self::assertNotNull($catalog->findForSale((int) $single->getId()));
        }
    }

    public function testTheCatalogListIsStillOneQuery(): void
    {
        foreach (range(1, 5) as $n) {
            $this->planWithAdditional();
            $this->createPlan(100000, $this->inbounds(1)[0]);
        }
        $this->em->clear();
        $counter = self::getContainer()->get(QueryCounter::class);
        $catalog = self::getContainer()->get(PlanCatalog::class);
        $counter->start();
        $forSale = $catalog->forSale();
        $queries = $counter->stop();

        self::assertCount(5, $forSale);
        self::assertLessThanOrEqual(3, $queries, 'plans, their additional inbounds and inbounds/panels in a fixed number of queries, not one per plan');
    }

    /**
     * @return array{Plan, VpnInbound}
     */
    private function planWithAdditional(): array
    {
        [$primary, $additional] = $this->inbounds(2);
        $plan = $this->createPlan(100000, $primary);
        $this->em->persist(new PlanAdditionalInbound($plan, $additional, 1, new \DateTimeImmutable()));
        $this->em->flush();

        return [$plan, $additional];
    }

    /**
     * @return list<VpnInbound> inbounds of one new panel
     */
    private function inbounds(int $count): array
    {
        $first = $this->createTestInbound();
        $inbounds = [$first];
        for ($n = 2; $n <= $count; ++$n) {
            $inbound = (new VpnInbound())->setPanel($first->getPanel())->setRemoteInboundId((string) $n)->setTitle('inbound '.$n);
            $this->em->persist($inbound);
            $inbounds[] = $inbound;
        }
        $this->em->flush();

        return $inbounds;
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
