<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\{Order, Payment, VpnService};
use App\Security\{AdminAccessPolicy, AdminActor, AdminPermission};
use Doctrine\ORM\EntityManagerInterface;

/** Read-only GET search, bounded per group and checked before any query. */
final class GlobalAdminSearch
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AdminAccessPolicy $policy, private readonly CustomerSearch $customers) {}

    /** @return list<array{label: string, id: int, route: string}> */
    public function search(AdminActor $actor, string $query): array
    {
        $query = trim($query);
        if ('' === $query || mb_strlen($query) > CustomerSearch::MAX_LENGTH) { return []; }
        $results = [];
        $numeric = 1 === preg_match('/^[0-9]{1,18}$/D', $query);
        foreach ([['services', VpnService::class, AdminPermission::SERVICES_VIEW, 'admin_vpn_service_detail'],
            ['payments', Payment::class, AdminPermission::PAYMENTS_VIEW, 'admin_payment_detail'],
            ['orders', Order::class, AdminPermission::ORDERS_VIEW, 'admin_order_detail']] as [$label, $class, $permission, $route]) {
            if (!$this->policy->can($actor, $permission) || (!$numeric && VpnService::class !== $class)) { continue; }
            $qb = $this->em->createQueryBuilder()->select('x.id')->from($class, 'x');
            if ($numeric) { $qb->where('x.id = :id')->setParameter('id', (int) $query); }
            else { $qb->where('x.username LIKE :term')->setParameter('term', '%'.addcslashes(ltrim($query, '@'), '%_\\').'%'); }
            if (VpnService::class === $class) { $qb->andWhere('x.status <> :deleted')->setParameter('deleted', 'deleted'); }
            foreach ($qb->orderBy('x.id', 'DESC')->setMaxResults(20)->getQuery()->getArrayResult() as $row) {
                $results[] = ['label' => 'ad2.search.'.$label, 'id' => (int) $row['id'], 'route' => $route];
            }
        }
        if (!$numeric && $this->policy->can($actor, AdminPermission::CUSTOMERS_VIEW) && CustomerSearch::isValidQuery($query)) {
            foreach ($this->customers->search($query, 20) as $account) {
                $results[] = ['label' => 'ad2.search.customers', 'id' => (int) $account->getUser()->getId(), 'route' => 'admin_customer_detail'];
            }
        }
        return $results;
    }
}
