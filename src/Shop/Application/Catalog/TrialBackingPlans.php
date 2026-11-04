<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Shop\Domain\OrderType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Trial backing plans (docs/design/trial-backing-plans.md): the hidden Plan row a trial plan is provisioned through
 * (TrialAccountService::syncBackingPlan()). They belong to the trial area only; the sales area (plan lists and
 * pickers, prices, the shop) never shows, changes or sells them.
 *
 * A plan is a backing plan when a trial plan points at it (trial_plan.backing_plan_id) or a trial order was made on it
 * (the backing plan of a trial plan deleted since, kept because its orders refer to it). Both are relations with an
 * index (the foreign keys); the "[Trial]" title is never looked at, so a normal plan called "[Trial] ..." stays a normal
 * plan. Nothing is remembered between calls: the answer is always the database's.
 */
final class TrialBackingPlans
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Adds "is not a trial backing plan" for the plan alias to a query builder (the one way every sales-side DQL
     * query excludes them: lists, searches, filters, pickers and counts).
     */
    public static function exclude(QueryBuilder $qb, string $alias): QueryBuilder
    {
        return $qb->andWhere(self::notBackingDql($alias));
    }

    /**
     * exclude() for a picker of a form: the value the record already has stays a choice (a discount code limited to a
     * backing plan before this rule is shown as it is, never silently widened to every plan when saved).
     */
    public static function excludeKeeping(QueryBuilder $qb, string $alias, ?int $keepPlanId): QueryBuilder
    {
        if (null === $keepPlanId) {
            return self::exclude($qb, $alias);
        }

        return $qb->andWhere(sprintf('(%s) OR %s.id = :tbp_keep_%s', self::notBackingDql($alias), $alias, $alias))->setParameter('tbp_keep_'.$alias, $keepPlanId);
    }

    /** The DQL condition of exclude(), for a query that builds its own WHERE. */
    public static function notBackingDql(string $alias): string
    {
        return sprintf(
            'NOT EXISTS (SELECT tbp_t_%1$s.id FROM %2$s tbp_t_%1$s WHERE tbp_t_%1$s.backingPlan = %1$s) AND NOT EXISTS (SELECT tbp_o_%1$s.id FROM %3$s tbp_o_%1$s WHERE tbp_o_%1$s.plan = %1$s AND tbp_o_%1$s.type = \'%4$s\')',
            $alias,
            TrialPlan::class,
            Order::class,
            OrderType::TRIAL,
        );
    }

    /** The same condition in SQL, for raw queries on the plan table (the column is "<alias>.id"). */
    public static function notBackingSql(string $alias): string
    {
        return sprintf(
            "NOT EXISTS (SELECT 1 FROM trial_plan tbp_t WHERE tbp_t.backing_plan_id = %1\$s.id) AND NOT EXISTS (SELECT 1 FROM `order` tbp_o WHERE tbp_o.plan_id = %1\$s.id AND tbp_o.type = '%2\$s')",
            $alias,
            OrderType::TRIAL,
        );
    }

    public function isBacking(Plan $plan): bool
    {
        return null !== $plan->getId() && [] !== $this->backingAmong([(int) $plan->getId()]);
    }

    /**
     * Which of these plan ids are backing plans (two indexed lookups, whatever the number of ids).
     *
     * @param list<int> $planIds
     *
     * @return list<int>
     */
    public function backingAmong(array $planIds): array
    {
        if ([] === $planIds) {
            return [];
        }
        $ids = $this->entityManager->getConnection()->executeQuery(
            "SELECT backing_plan_id FROM trial_plan WHERE backing_plan_id IN (?)
             UNION SELECT plan_id FROM `order` WHERE plan_id IN (?) AND type = ?",
            [$planIds, $planIds, OrderType::TRIAL],
            [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
        )->fetchFirstColumn();

        return array_map('intval', $ids);
    }

    /**
     * The plans of the sales area (every plan but the backing plans), as findBy() would return them.
     *
     * @param array<string, mixed>       $criteria field => value (a list: IN)
     * @param array<string, 'ASC'|'DESC'> $orderBy
     *
     * @return list<Plan>
     */
    public function salesPlans(array $criteria = [], array $orderBy = ['id' => 'ASC'], ?int $limit = null, ?int $offset = null): array
    {
        $qb = $this->salesQuery($criteria);
        foreach ($orderBy as $field => $direction) {
            $qb->addOrderBy('p.'.$field, $direction);
        }
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }
        if (null !== $offset) {
            $qb->setFirstResult($offset);
        }

        /** @var list<Plan> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function countSalesPlans(array $criteria = []): int
    {
        return (int) $this->salesQuery($criteria)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    /** The trial plan a backing plan belongs to now (null: none points at it, its trial plan was deleted). */
    public function trialPlanOf(Plan $plan): ?TrialPlan
    {
        return null === $plan->getId() ? null : $this->entityManager->getRepository(TrialPlan::class)->findOneBy(['backingPlan' => $plan], ['id' => 'ASC']);
    }

    /**
     * Every backing plan id, ascending (the audit command; scans the trial orders).
     *
     * @return list<int>
     */
    public function allIds(): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT backing_plan_id FROM trial_plan WHERE backing_plan_id IS NOT NULL
             UNION SELECT DISTINCT plan_id FROM `order` WHERE type = ? AND plan_id IS NOT NULL ORDER BY 1",
            [OrderType::TRIAL],
        );

        return array_map('intval', $ids);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function salesQuery(array $criteria): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->select('p')->from(Plan::class, 'p');
        foreach ($criteria as $field => $value) {
            $parameter = 'c_'.$field;
            $qb->andWhere(is_array($value) ? sprintf('p.%s IN (:%s)', $field, $parameter) : sprintf('p.%s = :%s', $field, $parameter))->setParameter($parameter, $value);
        }

        return self::exclude($qb, 'p');
    }
}
