<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Reseller;
use App\Entity\ResellerCustomer;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ResellerCustomerInvalid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A reseller's own customer labels (docs/design/reseller-and-claim.md, S3) and which of his sold services each one
 * has. A label is only what the reseller typed: a name, an optional phone and a note. It never holds or shows anything
 * of a Telegram account, and a claimed service's new owner is never named by it.
 *
 * Every call takes the reseller and checks ownership on every id: a label is his (reseller_customer.reseller_id), a
 * service is one he sold and not deleted (vpn_service.sold_by_reseller_id). Anything else is "not found", the same
 * answer as an id that does not exist (no oracle).
 *
 * Values are checked exactly and stored as typed, nothing trimmed or rewritten: a space or an empty line at either end
 * is refused, as are control and invisible direction characters (the Persian zero-width non-joiner is allowed).
 *
 * Lists are one query each (aggregates joined), whatever the number of labels and services.
 */
class ResellerCustomers
{
    public const PER_PAGE = 20;
    /** Labels per reseller: a soft cap against filling the table from one account. */
    public const MAX_PER_RESELLER = 5000;

    /** Control characters (except the ones a note may have), bidi controls, BOM, private use, surrogates, unassigned. */
    private const FORBIDDEN = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\p{Co}\p{Cs}\p{Cn}]/u';
    private const PHONE = '/^\+?[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}]{4,15}$/uD';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function count(Reseller $reseller): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM reseller_customer WHERE reseller_id = ?', [$reseller->getId()]);
    }

    /** His label with this id, or null (another reseller's label is null too). */
    public function find(Reseller $reseller, int $customerId): ?ResellerCustomer
    {
        if ($customerId <= 0) {
            return null;
        }
        $customer = $this->entityManager->getRepository(ResellerCustomer::class)->findOneBy(['id' => $customerId, 'reseller' => $reseller]);

        return $customer instanceof ResellerCustomer ? $customer : null;
    }

    /**
     * His labels, newest first, each with its live services (not deleted), how many are active and the soonest expiry
     * among them. Optional search in the name / phone (as typed, matched literally).
     *
     * @return array{items: list<array{id: int, name: string, phone: string|null, services: int, active: int, soonestExpiry: \DateTimeImmutable|null}>, page: int, hasNext: bool}
     */
    public function list(Reseller $reseller, ?string $search, int $page): array
    {
        $page = max(1, min(10000, $page));
        $parameters = ['reseller' => $reseller->getId(), 'deleted' => VpnServiceStatus::DELETED, 'active' => VpnServiceStatus::ACTIVE];
        $where = '';
        if (null !== $search) {
            $where = ' AND (c.name LIKE :search OR c.phone LIKE :search)';
            $parameters['search'] = '%'.addcslashes($search, '%_\\').'%';
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT c.id, c.name, c.phone,
                    COUNT(s.id) AS services,
                    COALESCE(SUM(s.status = :active), 0) AS active,
                    MIN(CASE WHEN s.status = :active THEN s.expires_at END) AS soonest
             FROM reseller_customer c
             LEFT JOIN reseller_customer_service a ON a.customer_id = c.id
             LEFT JOIN vpn_service s ON s.id = a.service_id AND s.status <> :deleted AND s.sold_by_reseller_id = c.reseller_id
             WHERE c.reseller_id = :reseller'.$where.'
             GROUP BY c.id, c.name, c.phone
             ORDER BY c.id DESC
             LIMIT '.(self::PER_PAGE + 1).' OFFSET '.(($page - 1) * self::PER_PAGE),
            $parameters,
        );
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'phone' => null === $row['phone'] ? null : (string) $row['phone'],
            'services' => (int) $row['services'],
            'active' => (int) $row['active'],
            'soonestExpiry' => null === $row['soonest'] ? null : new \DateTimeImmutable((string) $row['soonest']),
        ], array_slice($rows, 0, self::PER_PAGE));

        return ['items' => $items, 'page' => $page, 'hasNext' => count($rows) > self::PER_PAGE];
    }

    /**
     * Every label of his, by name, for the assign picker (id and name only; at most MAX_PER_RESELLER).
     *
     * @return list<array{id: int, name: string}>
     */
    public function choices(Reseller $reseller): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, name FROM reseller_customer WHERE reseller_id = ? ORDER BY name, id LIMIT '.self::MAX_PER_RESELLER, [$reseller->getId()]);

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $rows);
    }

    /**
     * The live services of one of his labels (one query).
     *
     * @return list<VpnService>
     */
    public function servicesOf(Reseller $reseller, ResellerCustomer $customer): array
    {
        if ($customer->getReseller()->getId() !== $reseller->getId()) {
            return [];
        }

        /** @var list<VpnService> $services */
        $services = $this->entityManager->createQueryBuilder()
            ->select('s', 'o', 'p')
            ->from(VpnService::class, 's')
            ->join(\App\Entity\ResellerCustomerService::class, 'a', 'WITH', 'a.service = s')
            ->leftJoin('s.order', 'o')
            ->leftJoin('o.plan', 'p')
            ->where('a.customer = :customer')
            ->andWhere('s.soldByReseller = :reseller')
            ->andWhere('s.status <> :deleted')
            ->setParameter('customer', $customer)
            ->setParameter('reseller', $reseller)
            ->setParameter('deleted', VpnServiceStatus::DELETED)
            ->orderBy('s.id', 'DESC')
            ->getQuery()->getResult();

        return $services;
    }

    /**
     * The label each of these services is assigned to (only his services and labels; one query).
     *
     * @param list<int> $serviceIds
     *
     * @return array<int, array{id: int, name: string}> by service id
     */
    public function labelsOf(Reseller $reseller, array $serviceIds): array
    {
        if ([] === $serviceIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT a.service_id, c.id, c.name FROM reseller_customer_service a JOIN reseller_customer c ON c.id = a.customer_id WHERE c.reseller_id = ? AND a.service_id IN (?)',
            [$reseller->getId(), $serviceIds],
            [1 => ArrayParameterType::INTEGER],
        );
        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['service_id']] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $input name, phone, note (strings as typed)
     *
     * @throws ResellerCustomerInvalid
     */
    public function create(Reseller $reseller, array $input): ResellerCustomer
    {
        [$name, $phone, $note] = $this->validated($input);
        if ($this->count($reseller) >= self::MAX_PER_RESELLER) {
            throw new ResellerCustomerInvalid(['name' => ['limit_reached']]);
        }
        $customer = new ResellerCustomer($reseller, $name, $phone, $note, $this->clock->now());
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return ResellerCustomer|null null: not his (nothing changed)
     *
     * @throws ResellerCustomerInvalid
     */
    public function update(Reseller $reseller, int $customerId, array $input): ?ResellerCustomer
    {
        $customer = $this->find($reseller, $customerId);
        if (null === $customer) {
            return null;
        }
        [$name, $phone, $note] = $this->validated($input);
        $customer->change($name, $phone, $note, $this->clock->now());
        $this->entityManager->flush();

        return $customer;
    }

    /** Deletes his label (its service assignments go with it; the services are not touched). False when not his. */
    public function delete(Reseller $reseller, int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        $deleted = (int) $this->entityManager->getConnection()->executeStatement('DELETE FROM reseller_customer WHERE id = ? AND reseller_id = ?', [$customerId, $reseller->getId()]);
        $customer = $this->entityManager->getUnitOfWork()->tryGetById($customerId, ResellerCustomer::class);
        if ($customer instanceof ResellerCustomer) {
            $this->entityManager->detach($customer);
        }

        return $deleted > 0;
    }

    /**
     * Assigns one of his sold services to one of his labels (replacing its label), or unassigns it (null). False when
     * the service or the label is not his (nothing changed). One statement: two assignments at once end with one of
     * them, never two rows (unique service_id).
     */
    public function assign(Reseller $reseller, int $serviceId, ?int $customerId): bool
    {
        if (!$this->isSoldBy($reseller, $serviceId)) {
            return false;
        }
        $connection = $this->entityManager->getConnection();
        if (null === $customerId) {
            $connection->executeStatement(
                'DELETE a FROM reseller_customer_service a JOIN reseller_customer c ON c.id = a.customer_id WHERE a.service_id = ? AND c.reseller_id = ?',
                [$serviceId, $reseller->getId()],
            );

            return true;
        }
        if (null === $this->find($reseller, $customerId)) {
            return false;
        }
        try {
            $connection->executeStatement(
                'INSERT INTO reseller_customer_service (customer_id, service_id, assigned_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), assigned_at = VALUES(assigned_at)',
                [$customerId, $serviceId, $this->clock->now()->format('Y-m-d H:i:s')],
            );
        } catch (ForeignKeyConstraintViolationException) {
            // The label was deleted in between.
            return false;
        }

        return true;
    }

    /** A live service he sold (claimed or not). */
    public function isSoldBy(Reseller $reseller, int $serviceId): bool
    {
        return $serviceId > 0 && false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM vpn_service WHERE id = ? AND sold_by_reseller_id = ? AND status <> ?',
            [$serviceId, $reseller->getId(), VpnServiceStatus::DELETED],
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{string, string|null, string|null}
     *
     * @throws ResellerCustomerInvalid errors by field (codes: required, too_long, spaces, characters, format)
     */
    private function validated(array $input): array
    {
        $errors = [];
        $name = $this->text($input, 'name', ResellerCustomer::NAME_MAX, false, $errors);
        if (null === $name && !isset($errors['name'])) {
            $errors['name'] = ['required'];
        }
        $phone = $this->text($input, 'phone', ResellerCustomer::PHONE_MAX, false, $errors);
        if (null !== $phone && 1 !== preg_match(self::PHONE, $phone)) {
            $errors['phone'] = ['format'];
        }
        $note = $this->text($input, 'note', ResellerCustomer::NOTE_MAX, true, $errors);
        if ([] !== $errors) {
            throw new ResellerCustomerInvalid($errors);
        }

        return [(string) $name, $phone, $note];
    }

    /**
     * One text field exactly as sent: null when absent or empty; otherwise a string of at most $max characters, valid
     * UTF-8, nothing forbidden (line breaks only where $multiline), and no space or line break at either end.
     *
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $errors
     */
    private function text(array $input, string $field, int $max, bool $multiline, array &$errors): ?string
    {
        $value = $input[$field] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            $errors[$field] = ['characters'];

            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[$field] = ['too_long'];

            return null;
        }
        if (0 !== preg_match(self::FORBIDDEN, $value) || (!$multiline && 0 !== preg_match('/[\r\n\t]/', $value))) {
            $errors[$field] = ['characters'];

            return null;
        }
        if (1 !== preg_match('/^\S(?:.*\S)?$/suD', $value)) {
            $errors[$field] = ['spaces'];

            return null;
        }

        return $value;
    }
}
