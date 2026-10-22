<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * An admin's changes to discount codes, the same from the web panel (DiscountCodeCrudController), the bot
 * (AdminDiscountsHandler), the CLI (app:discount:create) and the Mini App: validate (the entity's rules), save,
 * activate / deactivate, delete. Codes are stored trimmed and upper case, the form customers' input is matched in
 * (DiscountCodeService). Callers check the permission (DISCOUNTS_MANAGE) and audit.
 */
final class DiscountAdminService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return array<string, list<string>> field => messages; empty when the code keeps the rules
     */
    public function violations(DiscountCode $code): array
    {
        $code->setCode(self::normalize($code->getCode()));

        return InvalidCatalogEntry::byField($this->validator->validate($code));
    }

    /**
     * @throws InvalidCatalogEntry nothing saved
     */
    public function save(DiscountCode $code): void
    {
        $violations = $this->violations($code);
        if ([] !== $violations) {
            throw new InvalidCatalogEntry($violations);
        }
        if (null === $code->getId()) {
            $this->entityManager->persist($code);
        }
        $code->setUpdatedAt(new \DateTimeImmutable());
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
        $existing = $this->entityManager->getRepository(DiscountCode::class)->findOneBy(['code' => self::normalize($code)]);

        return $existing instanceof DiscountCode && $existing->getId() !== $exceptId;
    }

    /** Recorded uses of the code (its usage history). */
    public function usageCount(DiscountCode $code): int
    {
        return null === $code->getId() ? 0 : $this->entityManager->getRepository(DiscountUsage::class)->count(['discountCode' => $code]);
    }

    /** An unused random code (no 0/O/1/I to avoid misreading). */
    public function generateCode(int $length = 8): string
    {
        do {
            $code = '';
            for ($i = 0; $i < $length; ++$i) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while ($this->codeExists($code));

        return $code;
    }
}
