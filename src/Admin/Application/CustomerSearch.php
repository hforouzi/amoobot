<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds customers the way the bot's admin search always has: digits (5-20) match the Telegram id exactly or a mobile,
 * "@name" a username, anything else username, first/last name, name or mobile. Newest first. Used by the bot and the
 * Mini App.
 */
final class CustomerSearch
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 64;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function isValidQuery(string $query): bool
    {
        $length = mb_strlen(trim($query));

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }

    /**
     * @return list<TelegramAccount>
     */
    public function search(string $query, int $limit, int $offset = 0): array
    {
        $query = trim($query);
        $like = '%'.addcslashes(ltrim($query, '@'), '%_\\').'%';
        $builder = $this->entityManager->getRepository(TelegramAccount::class)->createQueryBuilder('t')->join('t.user', 'u');
        if (1 === preg_match('/^\d{5,20}$/', $query)) {
            $builder->where('t.telegramId = :exact OR u.mobile LIKE :like');
            $builder->setParameter('exact', $query);
        } elseif (str_starts_with($query, '@')) {
            $builder->where('t.username LIKE :like');
        } else {
            $builder->where('t.username LIKE :like OR t.firstName LIKE :like OR t.lastName LIKE :like OR u.name LIKE :like OR u.mobile LIKE :like');
        }

        /** @var list<TelegramAccount> $accounts */
        $accounts = $builder->setParameter('like', $like)->orderBy('t.id', 'DESC')->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return $accounts;
    }
}
