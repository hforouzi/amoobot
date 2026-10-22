<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A discount code on an order (a new service's, which exists before payment) or on a draft (renewal / add traffic,
 * before its order exists). The code is checked by DiscountCodeService against the amount after the global
 * discount; an invalid code leaves that amount. The price snapshot records the result. Everything is flushed.
 */
final class DiscountApplication
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiscountCodeService $discountCodeService,
    ) {
    }

    public function applyToOrder(Order $order, string $code): DiscountOutcome
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $amountBeforeCode = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $order->getAmount());
        $result = $this->discountCodeService->validateCode($code, $order->getUser(), $order->getType(), $order->getPlan(), $amountBeforeCode);
        if (!$result->valid || !$result->discountCode instanceof DiscountCode) {
            $this->clearOnOrder($order);

            return DiscountOutcome::invalid((string) $result->message, $amountBeforeCode);
        }

        $this->recordOnOrder($order, $result->discountCode->getCode(), $result->discountAmount, $result->finalAmount);

        return DiscountOutcome::applied($result->discountCode->getCode(), $result->discountAmount, $result->finalAmount);
    }

    /** No discount code on the order (the customer skipped it, or replaced a code with none). */
    public function clearOnOrder(Order $order): void
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $this->recordOnOrder($order, null, 0, (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $order->getAmount()));
    }

    /**
     * @param string $orderType the order the draft will become (OrderType::*)
     */
    public function applyToDraft(OrderDraft $draft, string $code, string $orderType = OrderType::NEW_SERVICE): DiscountOutcome
    {
        $amountBeforeCode = (int) ($draft->getCalculatedAmount() ?? 0);
        $result = $this->discountCodeService->validateCode($code, $draft->getUser(), $orderType, $draft->getPlan(), $amountBeforeCode);
        if (!$result->valid || !$result->discountCode instanceof DiscountCode) {
            $this->clearOnDraft($draft);

            return DiscountOutcome::invalid((string) $result->message, $amountBeforeCode);
        }

        $snapshot = is_array($draft->getPriceSnapshot()) ? $draft->getPriceSnapshot() : [];
        $snapshot['discountCode'] = $result->discountCode->getCode();
        $snapshot['discountCodeAmount'] = $result->discountAmount;
        $snapshot['finalAmount'] = $result->finalAmount;

        $draft
            ->setDiscountCode($result->discountCode->getCode())
            ->setDiscountAmount($result->discountAmount)
            ->setFinalAmount($result->finalAmount)
            ->setPriceSnapshot($snapshot)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return DiscountOutcome::applied($result->discountCode->getCode(), $result->discountAmount, $result->finalAmount);
    }

    public function clearOnDraft(OrderDraft $draft): void
    {
        $draft
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount((int) ($draft->getCalculatedAmount() ?? 0))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private function recordOnOrder(Order $order, ?string $code, int $discountAmount, int $finalAmount): void
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $priceSnapshot['discountCode'] = $code;
        $priceSnapshot['discountCodeAmount'] = $discountAmount;
        $priceSnapshot['finalAmount'] = $finalAmount;
        $metadata['priceSnapshot'] = $priceSnapshot;
        $metadata['discountCode'] = $code;
        $metadata['discountAmount'] = $discountAmount;
        unset($metadata['inputState']);

        $order
            ->setMetadata($metadata)
            ->setAmount($finalAmount);

        $this->entityManager->flush();
    }
}
