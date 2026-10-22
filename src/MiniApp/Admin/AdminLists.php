<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\Admin\Application\CustomerSearch;
use App\Admin\Application\PaymentTabs;
use App\Admin\Application\ServiceTabs;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\Entity\VpnService;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;

/**
 * The Mini App admin lists, a page at a time (PAGE_SIZE, one extra row tells whether there is a next page), each in
 * one query with what its cards show joined in. Tabs and filters are the web panel's (PaymentTabs, ServiceTabs) and
 * the customer search is the bot's (CustomerSearch).
 *
 * @phpstan-type Page array{items: list<mixed>, page: int, hasNext: bool}
 */
final class AdminLists
{
    public const PAGE_SIZE = 15;
    public const SERVICE_FILTERS = [...ServiceTabs::TABS, ServiceTabs::LOW_TRAFFIC];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerSearch $customerSearch,
        private readonly ClockInterface $clock,
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
    public function services(string $filter, ?string $query, int $page): array
    {
        $qb = $this->entityManager->createQueryBuilder()->select('s', 'u', 't', 'o', 'pl')->from(VpnService::class, 's')
            ->join('s.user', 'u')->leftJoin('u.telegramAccount', 't')->leftJoin('s.order', 'o')->leftJoin('o.plan', 'pl')
            ->orderBy('expiring' === $filter ? 's.expiresAt' : 's.id', 'expiring' === $filter ? 'ASC' : 'DESC');
        ServiceTabs::apply($qb, 's', $filter, $this->clock->now());
        if (null !== $query) {
            $like = self::like(ltrim($query, '@'));
            $qb->andWhere('s.username LIKE :q OR s.clientEmail LIKE :q OR t.username LIKE :q OR t.telegramId = :exact OR s.id = :id')
                ->setParameter('q', $like)->setParameter('exact', $query)->setParameter('id', ctype_digit($query) ? (int) $query : 0);
        }

        return $this->page($qb, $page);
    }

    /**
     * Newest customers, or the bot's search (Telegram id, @username, name, mobile).
     *
     * @return Page
     */
    public function customers(?string $query, int $page): array
    {
        if (null === $query) {
            $qb = $this->entityManager->getRepository(TelegramAccount::class)->createQueryBuilder('t')->addSelect('u')->join('t.user', 'u')->orderBy('t.id', 'DESC');

            return $this->page($qb, $page);
        }
        $accounts = $this->customerSearch->search($query, self::PAGE_SIZE + 1, $page * self::PAGE_SIZE);

        return ['items' => array_slice($accounts, 0, self::PAGE_SIZE), 'page' => $page, 'hasNext' => count($accounts) > self::PAGE_SIZE];
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
