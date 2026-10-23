<?php

declare(strict_types=1);

namespace App\Admin\UI\Dashboard;

use App\Admin\Application\PaymentTabs;
use App\Admin\Application\ServiceTabs;
use App\Admin\UI\Support\PanelHealth;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\VpnPanel;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminPermission;
use App\Shop\Domain\OrderType;
use App\Wallet\Application\WalletQueries;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

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
    ) {
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
     * currency, as stored, except payments of method "wallet" (money already received as a top-up); walletTopUps: the
     * part of that revenue that went into wallets.
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
            // Revenue is money received: a payment of method "wallet" spends money already counted as its top-up.
            $row = $this->entityManager->createQueryBuilder()
                ->select(
                    'COUNT(p.id) AS total',
                    'COALESCE(SUM(CASE WHEN p.method <> :wallet THEN p.amount ELSE 0 END), 0) AS revenue',
                    'COALESCE(SUM(CASE WHEN p.method <> :wallet AND o.type = :walletTopUp THEN p.amount ELSE 0 END), 0) AS wallet_top_ups',
                )
                ->from(Payment::class, 'p')->join('p.order', 'o')
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
