<?php

declare(strict_types=1);

namespace App\Admin\UI\Dashboard;

use App\Admin\Application\CardReceiptQueue;
use App\Admin\Application\PaymentTabs;
use App\Admin\Application\ServiceTabs;
use App\Admin\UI\Support\PanelHealth;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\VpnPanel;
use App\Entity\WalletTransaction;
use App\Payment\Application\Repair\StuckPaymentFinder;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceInboundCounts;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminPermission;
use App\Shop\Domain\OrderType;
use App\Wallet\Application\WalletQueries;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What the dashboard shows an admin, section by section, each only when their role may open the page it links to
 * (AdminAccessPolicy). A handful of aggregate queries whatever the data size (no query per row): payment tab counts,
 * today's orders / confirmed payments and revenue / new customers, service tab counts, and the panel list.
 *
 * @phpstan-type Card array{key: string, label: string, count: int, route: string, parameters: array<string, mixed>}
 */
final class DashboardOverview
{
    /** Attention queue: payment tabs an admin has to act on. */
    private const ATTENTION = ['needs_review', 'provisioning_failed', 'pending'];
    private const SERVICES = ['active', 'expiring', 'expired', 'suspended'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAccessPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly WalletQueries $walletQueries,
        private readonly AdminCalendar $calendar,
        private readonly CacheInterface $cache,
        private readonly CardReceiptQueue $receipts,
        private readonly StuckPaymentFinder $stuck,
        private readonly ServiceInboundCounts $inbounds,
    ) {
    }

    /** Modern page composition; query methods themselves have no layout dependency.
     * @return array<string, mixed>
     */
    public function dashboard(AdminActor $actor): array
    {
        $can = fn (AdminPermission $p): bool => $this->policy->can($actor, $p);
        $now = $this->clock->now();
        $day = $this->calendar->midnight($now);
        $permissions = array_map(static fn (AdminPermission $p): string => $p->value, array_filter(AdminPermission::cases(), $can));
        sort($permissions);
        $key = 'admin_dashboard_'.$day->format('Ymd').'_'.hash('sha256', implode(',', $permissions));
        $tabs = $can(AdminPermission::PAYMENTS_VIEW) ? PaymentTabs::countByTab($this->entityManager) : null;
        $services = $can(AdminPermission::SERVICES_VIEW) ? ServiceTabs::countByTab($this->entityManager, $now, $day) : null;
        $stats = $this->cache->get($key, function (ItemInterface $item) use ($can, $day): array {
            $item->expiresAfter(60);
            $sales = $can(AdminPermission::PAYMENTS_VIEW) ? $this->salesSeries($day, $can(AdminPermission::CUSTOMERS_VIEW)) : null;
            $today = null === $sales ? null : ['payments' => (int) $sales['payments'], 'revenue' => (int) $sales['day6']];
            $customers = null;
            if ($can(AdminPermission::CUSTOMERS_VIEW)) {
                $customers = null !== $sales ? (int) $sales['customers'] : (int) $this->entityManager->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')
                    ->where('u.createdAt >= :from AND u.createdAt < :to')->setParameter('from', $day)->setParameter('to', $day->modify('+1 day'))->getQuery()->getSingleScalarResult();
            }
            return ['today' => $today, 'change' => null !== $today && (int) $sales['day5'] !== 0 ? round(100 * ($today['revenue'] - (int) $sales['day5']) / (int) $sales['day5'], 1) : null,
                'days' => null !== $sales ? $this->seriesDays($sales, $day) : null, 'customers' => $customers];
        });
        return $stats + ['worklist' => $this->worklist($actor, $tabs, $services, $now), 'payments' => $tabs, 'services' => $services,
            'latest' => $can(AdminPermission::PAYMENTS_VIEW) ? $this->latestPayments() : null];
    }

    /** Money actually received: wallet spending is excluded, top-ups use their ledger credit.
     * @return array{payments: int, revenue: int}
     */
    public function range(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->revenueQuery()->select('COUNT(p.id) AS total', 'COALESCE(SUM('.$this->receivedExpression().'), 0) AS revenue')
            ->andWhere('p.confirmedAt >= :from AND p.confirmedAt < :to')->setParameter('from', $from)->setParameter('to', $to)->getQuery()->getSingleResult();
        return ['payments' => (int) $row['total'], 'revenue' => (int) $row['revenue']];
    }

    private function receivedExpression(): string
    {
        return 'CASE WHEN p.method = :wallet THEN 0 WHEN credit.id IS NOT NULL THEN credit.amount ELSE p.amount END';
    }

    private function revenueQuery(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->from(Payment::class, 'p')->join('p.order', 'o')
            ->leftJoin(WalletTransaction::class, 'credit', 'WITH', "o.type = :topup AND credit.idempotencyKey = CONCAT('topup:payment:', p.id)")
            ->where('p.status = :confirmed')->setParameter('confirmed', PaymentStatus::CONFIRMED)
            ->setParameter('wallet', Payment::METHOD_WALLET)->setParameter('topup', OrderType::WALLET_TOPUP);
    }

    /** @return list<array{date: \DateTimeImmutable, revenue: int}> */
    public function sevenDays(\DateTimeImmutable $day): array
    {
        return $this->seriesDays($this->salesSeries($day), $day);
    }

    /** One aggregate for all revenue days, today's payment count and (when permitted) customers.
     * @return array<string, mixed>
     */
    private function salesSeries(\DateTimeImmutable $day, bool $customers = false): array
    {
        $qb = $this->revenueQuery();
        for ($i = 0; $i < 7; ++$i) {
            $from = $day->modify('-'.(6 - $i).' days');
            $qb->addSelect('COALESCE(SUM(CASE WHEN p.confirmedAt >= :from'.$i.' AND p.confirmedAt < :to'.$i.' THEN '.$this->receivedExpression().' ELSE 0 END), 0) AS day'.$i)
                ->setParameter('from'.$i, $from)->setParameter('to'.$i, $from->modify('+1 day'));
        }
        $qb->addSelect('COALESCE(SUM(CASE WHEN p.confirmedAt >= :from6 AND p.confirmedAt < :to6 THEN 1 ELSE 0 END), 0) AS payments');
        if ($customers) {
            $qb->addSelect('(SELECT COUNT(dashboardUser.id) FROM '.User::class.' dashboardUser WHERE dashboardUser.createdAt >= :from6 AND dashboardUser.createdAt < :to6) AS customers');
        }
        return $qb->andWhere('p.confirmedAt >= :start AND p.confirmedAt < :end')->setParameter('start', $day->modify('-6 days'))->setParameter('end', $day->modify('+1 day'))->getQuery()->getSingleResult();
    }

    /** @param array<string, mixed> $row
     * @return list<array{date: \DateTimeImmutable, revenue: int}>
     */
    private function seriesDays(array $row, \DateTimeImmutable $day): array
    {
        return array_map(static fn (int $i): array => ['date' => $day->modify('-'.(6 - $i).' days'), 'revenue' => (int) $row['day'.$i]], range(0, 6));
    }

    /** Scalar results prevent lazy queries and keep secrets out of the view.
     * @return list<array<string, mixed>>
     */
    public function latestPayments(): array
    {
        return $this->entityManager->createQueryBuilder()->select('p.id, p.amount, p.method, p.status, p.createdAt, o.id AS orderId, u.name AS customer')
            ->from(Payment::class, 'p')->join('p.order', 'o')->join('o.user', 'u')->orderBy('p.id', 'DESC')->setMaxResults(8)->getQuery()->getArrayResult();
    }

    /** @param array<string, int>|null $payments
     * @param array<string, int>|null $services
     * @return list<Card>
     */
    public function worklist(AdminActor $actor, ?array $payments = null, ?array $services = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->clock->now();
        $rows = [];
        $add = static function (string $key, int $count, string $route, array $parameters) use (&$rows): void {
            if ($count > 0) {
                $rows[] = ['key' => $key, 'label' => 'ad2.work.'.$key, 'count' => $count, 'route' => $route, 'parameters' => $parameters];
            }
        };
        if ($this->policy->can($actor, AdminPermission::PAYMENTS_VIEW)) {
            $payments ??= PaymentTabs::countByTab($this->entityManager);
            $add('receipts', $this->receipts->count(), 'admin_payment_index', ['tab' => 'receipts']);
            $add('needs_review', $payments['needs_review'], 'admin_payment_index', ['tab' => 'needs_review']);
            $add('undelivered', $payments['provisioning_failed'] + $this->stuck->count($now->modify('-30 minutes')), 'admin_payment_worklist', []);
        }
        if ($this->policy->can($actor, AdminPermission::SERVICES_VIEW)) {
            $services ??= ServiceTabs::countByTab($this->entityManager, $now, $this->calendar->midnight($now));
            $add('expiring_today', $services['expiring_today'], 'admin_vpn_service_index', ['dashboard_day' => $now->setTimezone(new \DateTimeZone(AdminCalendar::TIMEZONE))->format('Y-m-d')]);
            $add('inbounds', count($this->inbounds->notConnectedByService()), 'admin_vpn_service_index', ['inbounds' => 'not_connected']);
            $add('moves', $services['failed_moves'], 'admin_vpn_service_index', ['moves' => 'failed']);
        }
        return $rows;
    }

    /**
     * @return array{
     *     attention: list<Card>|null,
     *     today: array{orders: int|null, payments: int|null, revenue: int|null, walletTopUps: int|null, customers: int|null}|null,
     *     services: list<Card>|null,
     *     panels: list<array{id: int, title: string, active: bool, state: string, badge: string, checkedAt: ?\DateTimeImmutable}>|null,
     *     wallets: array{outstanding: int, creditUsed: int, creditLimit: int, wallets: int}|null,
     *     shortcuts: list<array{key: string, label: string, icon: string, route: string}>,
     * }
     */
    public function for(AdminActor $actor): array
    {
        $can = fn (AdminPermission $permission): bool => $this->policy->can($actor, $permission);

        return [
            'attention' => $can(AdminPermission::PAYMENTS_VIEW) ? $this->attention() : null,
            'today' => $can(AdminPermission::ORDERS_VIEW) || $can(AdminPermission::PAYMENTS_VIEW) || $can(AdminPermission::CUSTOMERS_VIEW)
                ? $this->today($can(AdminPermission::ORDERS_VIEW), $can(AdminPermission::PAYMENTS_VIEW), $can(AdminPermission::CUSTOMERS_VIEW))
                : null,
            'services' => $can(AdminPermission::SERVICES_VIEW) ? $this->services() : null,
            'panels' => $can(AdminPermission::PANELS_MANAGE) ? $this->panels() : null,
            // One aggregate query: what customers hold and the credit exposure.
            'wallets' => $can(AdminPermission::WALLETS_VIEW) ? $this->walletQueries->totals() : null,
            'shortcuts' => array_values(array_filter([
                $can(AdminPermission::PLANS_MANAGE) ? ['key' => 'add_plan', 'label' => 'dashboard.shortcuts.add_plan', 'icon' => 'fa fa-plus', 'route' => 'admin_plan_new'] : null,
                $can(AdminPermission::BROADCAST_SEND) ? ['key' => 'broadcast', 'label' => 'dashboard.shortcuts.broadcast', 'icon' => 'fa fa-bullhorn', 'route' => 'admin_broadcast_message_new'] : null,
                $can(AdminPermission::LOGS_VIEW) ? ['key' => 'bot_logs', 'label' => 'dashboard.shortcuts.bot_logs', 'icon' => 'fa fa-file-text', 'route' => 'admin_logs_bot_messages_index'] : null,
            ])),
        ];
    }

    /**
     * @return list<Card>
     */
    private function attention(): array
    {
        $counts = PaymentTabs::countByTab($this->entityManager);

        return array_map(static fn (string $tab): array => [
            'key' => $tab,
            'label' => 'dashboard.attention.'.$tab,
            'count' => $counts[$tab],
            'route' => 'admin_payment_index',
            'parameters' => ['tab' => $tab],
        ], self::ATTENTION);
    }

    /**
     * Since midnight (the app's time zone). Revenue: the amounts of the payments confirmed today, in the store
     * currency, as stored, except payments of method "wallet" (money already received as a top-up); a wallet top-up
     * counts what was credited to the wallet (its ledger credit: what was received, less than ordered for a tolerated
     * underpayment, TopUpCredit), not the ordered amount. walletTopUps: the part of that revenue that went into wallets.
     *
     * @return array{orders: int|null, payments: int|null, revenue: int|null, walletTopUps: int|null, customers: int|null}
     */
    private function today(bool $orders, bool $payments, bool $customers): array
    {
        $midnight = $this->clock->now()->setTime(0, 0);
        $count = fn (string $entity, string $field): int => (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(x.id)')->from($entity, 'x')->andWhere(sprintf('x.%s >= :since', $field))->setParameter('since', $midnight)
            ->getQuery()->getSingleScalarResult();

        $confirmed = ['count' => null, 'revenue' => null, 'walletTopUps' => null];
        if ($payments) {
            // Revenue is money received: a payment of method "wallet" spends money already counted as its top-up, and a
            // top-up counts its ledger credit (the key WalletKeys::topUp() writes), not the ordered amount.
            $received = 'CASE WHEN credit.id IS NOT NULL THEN credit.amount ELSE p.amount END';
            $row = $this->entityManager->createQueryBuilder()
                ->select(
                    'COUNT(p.id) AS total',
                    "COALESCE(SUM(CASE WHEN p.method <> :wallet THEN $received ELSE 0 END), 0) AS revenue",
                    "COALESCE(SUM(CASE WHEN p.method <> :wallet AND o.type = :walletTopUp THEN $received ELSE 0 END), 0) AS wallet_top_ups",
                )
                ->from(Payment::class, 'p')->join('p.order', 'o')
                ->leftJoin(WalletTransaction::class, 'credit', 'WITH', "o.type = :walletTopUp AND credit.idempotencyKey = CONCAT('topup:payment:', p.id)")
                ->andWhere('p.status = :confirmed')->andWhere('p.confirmedAt >= :since')
                ->setParameter('confirmed', PaymentStatus::CONFIRMED)->setParameter('since', $midnight)
                ->setParameter('wallet', Payment::METHOD_WALLET)->setParameter('walletTopUp', OrderType::WALLET_TOPUP)
                ->getQuery()->getSingleResult();
            $confirmed = ['count' => (int) $row['total'], 'revenue' => (int) $row['revenue'], 'walletTopUps' => (int) $row['wallet_top_ups']];
        }

        return [
            'orders' => $orders ? $count(Order::class, 'createdAt') : null,
            'payments' => $confirmed['count'],
            'revenue' => $confirmed['revenue'],
            'walletTopUps' => $confirmed['walletTopUps'],
            'customers' => $customers ? $count(User::class, 'createdAt') : null,
        ];
    }

    /**
     * @return list<Card>
     */
    private function services(): array
    {
        $counts = ServiceTabs::countByTab($this->entityManager, $this->clock->now());

        return array_map(static fn (string $tab): array => [
            'key' => $tab,
            'label' => 'service.tabs.'.$tab,
            'count' => $counts[$tab],
            'route' => 'admin_vpn_service_index',
            'parameters' => ['tab' => $tab],
        ], self::SERVICES);
    }

    /**
     * From what the panels recorded (last connection test, degraded flag); nothing is contacted.
     *
     * @return list<array{id: int, title: string, active: bool, state: string, badge: string, checkedAt: ?\DateTimeImmutable}>
     */
    private function panels(): array
    {
        $panels = [];
        foreach ($this->entityManager->getRepository(VpnPanel::class)->findBy([], ['title' => 'ASC']) as $panel) {
            $state = PanelHealth::of($panel);
            $panels[] = [
                'id' => (int) $panel->getId(),
                'title' => $panel->getTitle(),
                'active' => $panel->isActive(),
                'state' => $state,
                'badge' => PanelHealth::badge($state),
                'checkedAt' => $panel->getProbedAt(),
            ];
        }

        return $panels;
    }
}
