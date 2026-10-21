<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: today's summary ("admin.dash").
 */
final class AdminDashboardHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'dash';
    }

    public function permissions(string $verb): array
    {
        return '' === $verb ? [AdminPermission::ORDERS_VIEW, AdminPermission::PAYMENTS_VIEW, AdminPermission::SERVICES_VIEW] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $now = new \DateTimeImmutable();
        $count = function (string $entity, string $where, array $parameters): int {
            $query = $this->entityManager->createQueryBuilder()->select('COUNT(e.id)')->from($entity, 'e')->where($where);
            foreach ($parameters as $name => $value) {
                $query->setParameter($name, $value);
            }

            return (int) $query->getQuery()->getSingleScalarResult();
        };

        $this->screen->send($request, $this->screen->text('admin.dashboard', [
            'orders_today' => $count(Order::class, 'e.createdAt >= :today', ['today' => new \DateTimeImmutable('today')]),
            'pending' => $count(Payment::class, 'e.status IN (:statuses)', ['statuses' => [PaymentStatus::PENDING, PaymentStatus::SUBMITTED]]),
            'needs_review' => $count(Payment::class, 'e.status = :status', ['status' => PaymentStatus::NEEDS_REVIEW]),
            'provisioning_failed' => $count(Payment::class, 'e.status = :status', ['status' => PaymentStatus::PROVISIONING_FAILED]),
            'active_services' => $count(VpnService::class, 'e.status = :status', ['status' => VpnServiceStatus::ACTIVE]),
            'expiring' => $count(VpnService::class, 'e.status = :status AND e.expiresAt BETWEEN :now AND :soon', ['status' => VpnServiceStatus::ACTIVE, 'now' => $now, 'soon' => $now->modify('+3 days')]),
        ]), $this->screen->backKeyboard());
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
