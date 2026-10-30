<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which plans use which inbounds (docs/design/multi-inbound-plans.md 6): the plan list's "Inbounds: N" column, the
 * panel inbound page's "Used by plans", the refusal to delete an inbound a plan sells, and the warning when the switch
 * is turned off while plans keep additional inbounds. Each answer is a fixed number of queries for every row of a page
 * (no query per row), remembered for the request.
 */
final class PlanInboundUsage implements ResetInterface
{
    /** @var array<int, list<array{id: int, title: string, active: bool}>>|null plan id => its additional inbounds, in order */
    private ?array $additionalByPlan = null;
    /** @var array<int, list<array{planId: int, planTitle: string, role: string}>>|null inbound id => plans */
    private ?array $plansByInbound = null;

    public const ROLE_PRIMARY = 'primary';
    public const ROLE_ADDITIONAL = 'additional';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function reset(): void
    {
        $this->additionalByPlan = null;
        $this->plansByInbound = null;
    }

    /**
     * @return list<array{id: int, title: string, active: bool}> the plan's additional inbounds, in order
     */
    public function additionalInboundsOf(int $planId): array
    {
        if (null === $this->additionalByPlan) {
            $this->additionalByPlan = [];
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT a.plan_id, i.id, i.title, i.is_active FROM plan_additional_inbound a JOIN vpn_inbound i ON i.id = a.inbound_id ORDER BY a.plan_id, a.sort_order, a.id',
            );
            foreach ($rows as $row) {
                $this->additionalByPlan[(int) $row['plan_id']][] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'active' => (bool) $row['is_active']];
            }
        }

        return $this->additionalByPlan[$planId] ?? [];
    }

    /**
     * @return list<array{planId: int, planTitle: string, role: string}> the plans selling this inbound, primary first
     */
    public function plansUsing(int $inboundId): array
    {
        if (null === $this->plansByInbound) {
            $this->plansByInbound = [];
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT p.inbound_id AS inbound_id, p.id AS plan_id, p.title, 'primary' AS role FROM plan p WHERE p.inbound_id IS NOT NULL
                 UNION ALL
                 SELECT a.inbound_id, p.id, p.title, 'additional' FROM plan_additional_inbound a JOIN plan p ON p.id = a.plan_id
                 ORDER BY inbound_id, role DESC, plan_id",
            );
            foreach ($rows as $row) {
                $this->plansByInbound[(int) $row['inbound_id']][] = ['planId' => (int) $row['plan_id'], 'planTitle' => (string) $row['title'], 'role' => (string) $row['role']];
            }
        }

        return $this->plansByInbound[$inboundId] ?? [];
    }

    /**
     * The plans whose additional inbounds include one of these inbounds (an inbound in that list cannot be deleted).
     *
     * @param list<int> $inboundIds
     *
     * @return list<array{id: int, title: string}>
     */
    public function plansWithAdditional(array $inboundIds): array
    {
        if ([] === $inboundIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT DISTINCT p.id, p.title FROM plan_additional_inbound a JOIN plan p ON p.id = a.plan_id WHERE a.inbound_id IN (?) ORDER BY p.id',
            [array_values($inboundIds)],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $rows);
    }

    /**
     * Every plan that has additional inbounds (they are not sold, whatever the switch).
     *
     * @return list<array{id: int, title: string}>
     */
    public function plansWithAnyAdditional(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT p.id, p.title FROM plan_additional_inbound a JOIN plan p ON p.id = a.plan_id ORDER BY p.id',
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $rows);
    }

    /**
     * Titles for a message: "#3 Gold, #7 Silver" (at most 10, then "(+N)").
     *
     * @param list<array{id: int, title: string}> $plans
     */
    public static function names(array $plans): string
    {
        $shown = array_map(static fn (array $p): string => sprintf('#%d %s', $p['id'], $p['title']), array_slice($plans, 0, 10));

        return implode(', ', $shown).(count($plans) > 10 ? sprintf(' (+%d)', count($plans) - 10) : '');
    }
}
