<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\Admin\Application\PaymentTabs;
use App\Entity\Order;
use App\Entity\Payment;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The Mini App admin lists, a page at a time (PAGE_SIZE, one extra row tells whether there is a next page), each in
 * one query with what its cards show joined in. Tabs and filters are the web panel's (PaymentTabs).
 *
 * @phpstan-type Page array{items: list<mixed>, page: int, hasNext: bool}
 */
final class AdminLists
{
    public const PAGE_SIZE = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Page
     */
    public function payments(string $tab, ?string $query, int $page): array
    {
        $qb = $this->entityManager->createQueryBuilder()->select('p', 'o', 'u', 't')->from(Payment::class, 'p')
            ->join('p.order', 'o')->join('o.user', 'u')->leftJoin('u.telegramAccount', 't');
        PaymentTabs::apply($qb, 'p', $tab);
        if (null !== $query) {
            $qb->andWhere('o.trackingCode LIKE :q OR p.trackingCode LIKE :q')->setParameter('q', self::like($query));
        }

        return $this->page($qb, $page);
    }

    /**
     * @return Page
     */
    public function orders(?string $status, ?string $query, int $page): array
    {
        $qb = $this->entityManager->createQueryBuilder()->select('o', 'u', 't', 'pl')->from(Order::class, 'o')
            ->join('o.user', 'u')->leftJoin('u.telegramAccount', 't')->join('o.plan', 'pl')->orderBy('o.id', 'DESC');
        if (null !== $status && in_array($status, OrderStatus::ALL, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if (null !== $query) {
            $qb->andWhere(ctype_digit($query) ? 'o.trackingCode LIKE :q OR o.id = :id' : 'o.trackingCode LIKE :q')->setParameter('q', self::like($query));
            if (ctype_digit($query)) {
                $qb->setParameter('id', (int) $query);
            }
        }

        return $this->page($qb, $page);
    }

    /**
     * @return Page
     */
    private function page(QueryBuilder $qb, int $page): array
    {
        $page = max(0, $page);
        $items = $qb->setFirstResult($page * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE + 1)->getQuery()->getResult();

        return ['items' => array_slice($items, 0, self::PAGE_SIZE), 'page' => $page, 'hasNext' => count($items) > self::PAGE_SIZE];
    }

    private static function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
