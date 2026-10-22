<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\DiscountCode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's changes to discount codes, the same from the web panel (DiscountCodeCrudController), the bot
 * (AdminDiscountsHandler), the CLI (app:discount:create) and the Mini App: save, activate / deactivate, delete.
 * Callers check the permission (DISCOUNTS_MANAGE) and audit.
 */
final class DiscountAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DiscountCode $code): void
    {
        if (null === $code->getId()) {
            $this->entityManager->persist($code);
        }
        $this->entityManager->flush();
    }

    /** @return bool false when the code already was in that state (nothing written) */
    public function setActive(DiscountCode $code, bool $active): bool
    {
        if ($code->isActive() === $active) {
            return false;
        }
        $code->setIsActive($active);
        $this->entityManager->flush();

        return true;
    }

    public function delete(DiscountCode $code): void
    {
        $this->entityManager->remove($code);
        $this->entityManager->flush();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $existing = $this->entityManager->getRepository(DiscountCode::class)->findOneBy(['code' => $code]);

        return $existing instanceof DiscountCode && $existing->getId() !== $exceptId;
    }
}
