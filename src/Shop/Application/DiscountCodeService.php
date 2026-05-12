<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Plan;
use App\Entity\User;
use App\Shop\Application\Discount\DiscountResult;
use App\Shop\Application\Discount\ValidationResult;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class DiscountCodeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Validate discount code against an order context where $amount is the amount after global discount.
     */
    public function validateCode(string $code, User $user, string $orderType, ?Plan $plan, int $amount): ValidationResult
    {
        $normalizedCode = strtoupper(trim($code));
        if ('' === $normalizedCode) {
            return ValidationResult::invalid('کد تخفیف معتبر نیست.');
        }

        $discountCode = $this->entityManager->getRepository(DiscountCode::class)->findOneBy(['code' => $normalizedCode]);
        if (!$discountCode instanceof DiscountCode) {
            return ValidationResult::invalid('کد تخفیف یافت نشد.');
        }

        if (!$discountCode->isActive()) {
            return ValidationResult::invalid('این کد تخفیف غیرفعال است.');
        }

        if (!in_array($discountCode->getType(), DiscountCode::allowedTypes(), true)) {
            return ValidationResult::invalid('نوع کد تخفیف نامعتبر است.');
        }

        if (!in_array($discountCode->getAppliesTo(), DiscountCode::allowedAppliesTo(), true)) {
            return ValidationResult::invalid('محدوده اعمال کد تخفیف نامعتبر است.');
        }

        if (DiscountCode::TYPE_PERCENT === $discountCode->getType() && ($discountCode->getValue() < 1 || $discountCode->getValue() > 100)) {
            return ValidationResult::invalid('درصد کد تخفیف نامعتبر است.');
        }

        if (DiscountCode::TYPE_FIXED === $discountCode->getType() && $discountCode->getValue() <= 0) {
            return ValidationResult::invalid('مبلغ ثابت کد تخفیف نامعتبر است.');
        }

        $now = new \DateTimeImmutable();
        if ($discountCode->getStartsAt() instanceof \DateTimeImmutable && $discountCode->getStartsAt() > $now) {
            return ValidationResult::invalid('این کد تخفیف هنوز فعال نشده است.');
        }
        if ($discountCode->getEndsAt() instanceof \DateTimeImmutable && $discountCode->getEndsAt() < $now) {
            return ValidationResult::invalid('مهلت استفاده از این کد به پایان رسیده است.');
        }

        if (DiscountCode::APPLIES_ALL !== $discountCode->getAppliesTo() && $discountCode->getAppliesTo() !== $orderType) {
            return ValidationResult::invalid('این کد برای این نوع سفارش قابل استفاده نیست.');
        }

        if ($discountCode->getPlan() instanceof Plan && ($plan?->getId() !== $discountCode->getPlan()?->getId())) {
            return ValidationResult::invalid('این کد برای این پلن قابل استفاده نیست.');
        }

        if (null !== $discountCode->getMinAmount() && $amount < $discountCode->getMinAmount()) {
            return ValidationResult::invalid('مبلغ سفارش برای استفاده از این کد کافی نیست.');
        }

        if (null !== $discountCode->getMaxUses() && $discountCode->getUsedCount() >= $discountCode->getMaxUses()) {
            return ValidationResult::invalid('سقف استفاده از این کد تکمیل شده است.');
        }

        if (null !== $discountCode->getMaxUsesPerUser()) {
            $usageCountForUser = $this->entityManager->getRepository(DiscountUsage::class)->count([
                'discountCode' => $discountCode,
                'user' => $user,
            ]);
            if ($usageCountForUser >= $discountCode->getMaxUsesPerUser()) {
                return ValidationResult::invalid('سقف استفاده شما از این کد تکمیل شده است.');
            }
        }

        if ($discountCode->isFirstPurchaseOnly()) {
            $paidOrders = (int) $this->entityManager->getRepository(Order::class)
                ->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.user = :user')
                ->andWhere('o.status IN (:statuses)')
                ->setParameter('user', $user)
                ->setParameter('statuses', [OrderStatus::PAID, OrderStatus::PROVISIONED])
                ->getQuery()
                ->getSingleScalarResult();
            if ($paidOrders > 0) {
                return ValidationResult::invalid('این کد فقط برای خرید اول قابل استفاده است.');
            }
        }

        $discountAmount = $this->calculateDiscountAmount($discountCode, $amount);
        $finalAmount = max(0, $amount - $discountAmount);

        return ValidationResult::valid($discountCode, $discountAmount, $finalAmount);
    }

    /**
     * Validate and apply discount code for either draft or persisted order context.
     */
    public function applyCode(string $code, OrderDraft|Order $context): DiscountResult
    {
        if ($context instanceof OrderDraft) {
            $data = is_array($context->getData()) ? $context->getData() : [];
            $orderType = (string) ($data['orderType'] ?? 'new_service');
            $priceSnapshot = is_array($context->getPriceSnapshot()) ? $context->getPriceSnapshot() : [];
            $amount = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $context->getCalculatedAmount() ?? 0);
            $validation = $this->validateCode($code, $context->getUser(), $orderType, $context->getPlan(), $amount);
            if (!$validation->valid || !$validation->discountCode instanceof DiscountCode) {
                return DiscountResult::failed($validation->message, $amount);
            }

            return DiscountResult::applied($validation->discountCode, $amount, $validation->discountAmount, $validation->finalAmount);
        }

        $metadata = is_array($context->getMetadata()) ? $context->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $amount = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $context->getAmount());
        $validation = $this->validateCode($code, $context->getUser(), $context->getType(), $context->getPlan(), $amount);
        if (!$validation->valid || !$validation->discountCode instanceof DiscountCode) {
            return DiscountResult::failed($validation->message, $amount);
        }

        return DiscountResult::applied($validation->discountCode, $amount, $validation->discountAmount, $validation->finalAmount);
    }

    public function markUsed(DiscountCode $code, User $user, Order $order, int $amountBeforeDiscount, int $discountAmount, int $amountAfterDiscount): void
    {
        $existing = $this->entityManager->getRepository(DiscountUsage::class)->findOneBy([
            'discountCode' => $code,
            'user' => $user,
            'order' => $order,
        ]);

        if ($existing instanceof DiscountUsage) {
            return;
        }

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $usage = (new DiscountUsage())
            ->setDiscountCode($code)
            ->setUser($user)
            ->setOrder($order)
            ->setAmountBefore($amountBeforeDiscount)
            ->setDiscountAmount($discountAmount)
            ->setAmountAfter($amountAfterDiscount)
            ->setUsedAt(new \DateTimeImmutable())
            ->setMetadata([
                'orderType' => $order->getType(),
                'orderId' => $order->getId(),
                'priceSnapshot' => $metadata['priceSnapshot'] ?? null,
            ]);

        $code->incrementUsedCount()->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($usage);
    }

    public function calculateDiscountAmount(DiscountCode $code, int $amount): int
    {
        $safeAmount = max(0, $amount);
        if ($safeAmount <= 0) {
            return 0;
        }

        if (DiscountCode::TYPE_PERCENT === $code->getType()) {
            return (int) floor(($safeAmount * max(0, min(100, $code->getValue()))) / 100);
        }

        return min($safeAmount, max(0, $code->getValue()));
    }
}
