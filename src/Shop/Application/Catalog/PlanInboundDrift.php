<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How many existing services of a plan are not on the inbound the plan now sells (docs/design/plan-inbound-change.md
 * 3.1, P1). Changing a plan's inbound never moves its services: this only makes that visible. Read only: no lock, no
 * write, no panel call, never on a sale, checkout or settlement path.
 *
 * - A service belongs to a plan through its order (vpn_service has no plan column); deleted services are left out;
 *   active, suspended and expired ones are counted, by inbound and by status.
 * - Trial services belong to the trial plan's hidden backing plan; they are compared with the trial plan's inbound
 *   (what new trials get), as TrialAccountService copies it onto the backing plan only at the next claim.
 * - Reseller-sold and claimed services are counted like any other. Nothing here names a customer, a reseller or a link
 *   (D5): the answers are counts and inbound titles.
 * - A service on another panel than the plan's primary is counted apart (no attach can reach it, design 4.4).
 * - With plans.multi_inbound_enabled on, also: services missing one of the plan's additional inbounds, and services
 *   still holding an inbound the plan no longer has.
 *
 * Each answer is a fixed number of indexed queries (order.plan_id, vpn_service.order_id), never one per row; the list
 * page's totals are one grouped query, remembered for the request.
 */
final class PlanInboundDrift implements ResetInterface
{
    /** At most this many inbounds in a breakdown, then "…". */
    public const BREAKDOWN_LIMIT = 3;

    private const COUNTED_ROWS = [VpnServiceInbound::STATUS_ATTACHED, VpnServiceInbound::STATUS_PENDING, VpnServiceInbound::STATUS_FAILED];

    /** @var array<int, int>|null plan id => services not on the plan's inbound */
    private ?array $totals = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MultiInboundSettings $multiInbound,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function reset(): void
    {
        $this->totals = null;
    }

    /**
     * The plan's services that are not on $primary (default: the inbound new sales of the plan get, see
     * effectivePrimary()). Nothing is counted for a plan not stored yet or without an inbound.
     */
    public function ofPlan(Plan $plan, ?VpnInbound $primary = null): PlanInboundDriftCount
    {
        $planId = (int) $plan->getId();
        // Ids only: no inbound or panel is loaded for this.
        $primaryId = null !== $primary ? $primary->getId() : (0 === $planId ? null : $this->trialInboundId($planId) ?? $plan->getInbound()?->getId());
        if (0 === $planId || null === $primaryId) {
            return PlanInboundDriftCount::none($planId, $primaryId);
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT s.inbound_id, i.title, s.status, (s.panel_id <=> (SELECT pi.panel_id FROM vpn_inbound pi WHERE pi.id = :primary)) AS same_panel, COUNT(*) AS n
             FROM vpn_service s JOIN `order` o ON o.id = s.order_id LEFT JOIN vpn_inbound i ON i.id = s.inbound_id
             WHERE o.plan_id = :plan AND s.status <> :deleted AND (s.inbound_id IS NULL OR s.inbound_id <> :primary)
             GROUP BY s.inbound_id, i.title, s.status, same_panel',
            ['plan' => $planId, 'primary' => $primaryId, 'deleted' => VpnServiceStatus::DELETED],
        );
        $total = 0;
        $otherPanel = 0;
        $byStatus = [];
        $byInbound = [];
        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $total += $n;
            $byStatus[(string) $row['status']] = ($byStatus[(string) $row['status']] ?? 0) + $n;
            if (!(bool) $row['same_panel']) {
                $otherPanel += $n;
            }
            $key = null === $row['inbound_id'] ? 'none' : (string) $row['inbound_id'];
            $byInbound[$key] ??= ['id' => null === $row['inbound_id'] ? null : (int) $row['inbound_id'], 'title' => null === $row['title'] ? null : (string) $row['title'], 'count' => 0];
            $byInbound[$key]['count'] += $n;
        }
        $byInbound = array_values($byInbound);
        usort($byInbound, static fn (array $a, array $b): int => [$b['count'], $a['id'] ?? PHP_INT_MAX] <=> [$a['count'], $b['id'] ?? PHP_INT_MAX]);
        ksort($byStatus);
        [$missing, $extra] = $this->multiInbound->enabled() ? $this->additionalDrift($planId) : [null, null];

        return new PlanInboundDriftCount($planId, $primaryId, $total, $byInbound, $byStatus, $otherPanel, $missing, $extra);
    }

    /**
     * The inbound new sales of this plan get: the plan's primary, or for a trial plan's hidden backing plan the trial
     * plan's inbound (copied onto the backing plan at the next claim).
     */
    public function effectivePrimary(Plan $plan): ?VpnInbound
    {
        if (null === $plan->getId()) {
            return $plan->getInbound();
        }
        $trial = $this->entityManager->getRepository(TrialPlan::class)->findOneBy(['backingPlan' => $plan]);

        return $trial?->getInbound() ?? $plan->getInbound();
    }

    /** The trial plan's inbound when this plan is a trial plan's hidden backing plan, else null (one query). */
    private function trialInboundId(int $planId): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT inbound_id FROM trial_plan WHERE backing_plan_id = ? AND inbound_id IS NOT NULL ORDER BY id LIMIT 1', [$planId]);

        return false === $id || null === $id ? null : (int) $id;
    }

    /**
     * Per plan, the services not on the plan's inbound, for a list page: one grouped query for every row of the page,
     * remembered for the request (no query per row).
     */
    public function totalOf(int $planId): int
    {
        return $this->totals()[$planId] ?? 0;
    }

    /** Whether any plan has services on another inbound (the list shows its column only then). Same single query. */
    public function anyPlan(): bool
    {
        return [] !== $this->totals();
    }

    /**
     * @return array<int, int>
     */
    private function totals(): array
    {
        if (null === $this->totals) {
            $this->totals = [];
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT o.plan_id, COUNT(*) AS n
                 FROM plan p LEFT JOIN trial_plan tp ON tp.backing_plan_id = p.id
                 JOIN `order` o ON o.plan_id = p.id JOIN vpn_service s ON s.order_id = o.id
                 WHERE COALESCE(tp.inbound_id, p.inbound_id) IS NOT NULL AND s.status <> :deleted
                   AND (s.inbound_id IS NULL OR s.inbound_id <> COALESCE(tp.inbound_id, p.inbound_id))
                 GROUP BY o.plan_id',
                ['deleted' => VpnServiceStatus::DELETED],
            );
            foreach ($rows as $row) {
                $this->totals[(int) $row['plan_id']] = (int) $row['n'];
            }
        }

        return $this->totals;
    }

    /**
     * Sum over these plans (the panel setup wizard's changed plans), one query.
     *
     * @param list<int> $planIds
     */
    public function totalOfPlans(array $planIds): int
    {
        if ([] === $planIds) {
            return 0;
        }

        return (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*)
             FROM plan p LEFT JOIN trial_plan tp ON tp.backing_plan_id = p.id
             JOIN `order` o ON o.plan_id = p.id JOIN vpn_service s ON s.order_id = o.id
             WHERE p.id IN (?) AND COALESCE(tp.inbound_id, p.inbound_id) IS NOT NULL AND s.status <> ?
               AND (s.inbound_id IS NULL OR s.inbound_id <> COALESCE(tp.inbound_id, p.inbound_id))',
            [$planIds, VpnServiceStatus::DELETED],
            [ArrayParameterType::INTEGER, ParameterType::STRING],
        )->fetchOne();
    }

    /**
     * The services among these that are not on the inbound their plan now sells, one query: service id => the
     * inbound it is on (null: deleted locally) and the plan's. Deleted services and services without a plan are left
     * out.
     *
     * @param list<int> $serviceIds
     *
     * @return array<int, array{current: string|null, planInbound: string}>
     */
    public function ofServices(array $serviceIds): array
    {
        if ([] === $serviceIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT s.id, ci.title AS current_title, pi.title AS plan_title
             FROM vpn_service s JOIN `order` o ON o.id = s.order_id JOIN plan p ON p.id = o.plan_id
             LEFT JOIN trial_plan tp ON tp.backing_plan_id = p.id
             JOIN vpn_inbound pi ON pi.id = COALESCE(tp.inbound_id, p.inbound_id)
             LEFT JOIN vpn_inbound ci ON ci.id = s.inbound_id
             WHERE s.id IN (?) AND s.status <> ? AND (s.inbound_id IS NULL OR s.inbound_id <> pi.id)',
            [$serviceIds, VpnServiceStatus::DELETED],
            [ArrayParameterType::INTEGER, ParameterType::STRING],
        )->fetchAllAssociative();
        $notices = [];
        foreach ($rows as $row) {
            $notices[(int) $row['id']] = ['current' => null === $row['current_title'] ? null : (string) $row['current_title'], 'planInbound' => (string) $row['plan_title']];
        }

        return $notices;
    }

    /**
     * @return array{current: string|null, planInbound: string}|null
     */
    public function ofService(VpnService $service): ?array
    {
        return null === $service->getId() ? null : ($this->ofServices([(int) $service->getId()])[(int) $service->getId()] ?? null);
    }

    /**
     * '12 on "DE-1", 3 on "NL-2"': at most BREAKDOWN_LIMIT inbounds, then "…". Not escaped (the caller escapes for
     * HTML; the titles are admin-entered).
     */
    public function breakdown(PlanInboundDriftCount $drift, ?string $locale = null): string
    {
        $items = array_map(fn (array $i): string => $this->translator->trans('plan_inbound_drift.breakdown_item', [
            '%count%' => $i['count'],
            '%inbound%' => $this->inboundTitle($i['title'], $locale),
        ], 'admin', $locale), array_slice($drift->byInbound, 0, self::BREAKDOWN_LIMIT));

        // ", " in English, "، " in Persian.
        $separator = $this->translator->trans('plan_inbound_drift.breakdown_separator', [], 'admin', $locale);

        return implode($separator, $items).(count($drift->byInbound) > self::BREAKDOWN_LIMIT ? $separator.'…' : '');
    }

    /**
     * Text (d) "plan_inbound_drift.service" or (e) "plan_inbound_drift.regenerate" for a service of ofServices(), not
     * escaped.
     *
     * @param array{current: string|null, planInbound: string} $notice
     */
    public function serviceText(string $key, array $notice, ?string $locale = null): string
    {
        return $this->translator->trans($key, ['%current%' => $this->inboundTitle($notice['current'], $locale), '%planInbound%' => $notice['planInbound']], 'admin', $locale);
    }

    /** An inbound's title, or the "deleted inbound" label for a service whose inbound was deleted locally. */
    public function inboundTitle(?string $title, ?string $locale = null): string
    {
        return $title ?? $this->translator->trans('plan_inbound_drift.no_inbound', [], 'admin', $locale);
    }

    /**
     * @return array{int, int} services missing one of the plan's additional inbounds; services still holding an
     *                         inbound that is no longer one of the plan's additional inbounds
     */
    private function additionalDrift(int $planId): array
    {
        $row = $this->entityManager->getConnection()->executeQuery(
            'SELECT
               (SELECT COUNT(DISTINCT s.id) FROM vpn_service s JOIN `order` o ON o.id = s.order_id
                  JOIN plan_additional_inbound a ON a.plan_id = o.plan_id
                 WHERE o.plan_id = :plan AND s.status <> :deleted
                   AND NOT EXISTS (SELECT 1 FROM vpn_service_inbound r WHERE r.service_id = s.id AND r.inbound_id = a.inbound_id AND r.status IN (:counted))) AS missing,
               (SELECT COUNT(DISTINCT s.id) FROM vpn_service s JOIN `order` o ON o.id = s.order_id
                  JOIN vpn_service_inbound r ON r.service_id = s.id AND r.status IN (:counted)
                 WHERE o.plan_id = :plan AND s.status <> :deleted
                   AND NOT EXISTS (SELECT 1 FROM plan_additional_inbound a WHERE a.plan_id = o.plan_id AND a.inbound_id = r.inbound_id)) AS extra',
            ['plan' => $planId, 'deleted' => VpnServiceStatus::DELETED, 'counted' => self::COUNTED_ROWS],
            ['counted' => ArrayParameterType::STRING],
        )->fetchAssociative();

        return [(int) ($row['missing'] ?? 0), (int) ($row['extra'] ?? 0)];
    }
}
