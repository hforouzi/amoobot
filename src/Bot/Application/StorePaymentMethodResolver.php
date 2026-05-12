<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\Order;
use App\Entity\StorePaymentMethod;
use Doctrine\ORM\EntityManagerInterface;

final class StorePaymentMethodResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<StorePaymentMethod>
     */
    public function getAvailableMethods(Order $order): array
    {
        $currency = $this->resolveCurrency($order);
        $amount = $this->resolvePayableAmount($order);
        $methods = $this->activeMethodsForCurrency($currency);
        $available = [];

        foreach ($methods as $method) {
            if (!$method instanceof StorePaymentMethod) {
                continue;
            }

            if ($this->methodSkipReasons($method, $order, $amount, $currency) !== []) {
                continue;
            }

            $available[] = $method;
        }

        return $available;
    }

    /**
     * @return array{
     *     orderId: int,
     *     amount: int,
     *     payableAmount: int,
     *     currency: string,
     *     activeStorePaymentMethodCount: int,
     *     skippedReasons: list<string>
     * }
     */
    public function getDiagnostics(Order $order): array
    {
        $currency = $this->resolveCurrency($order);
        $amount = max(0, $order->getAmount());
        $payableAmount = $this->resolvePayableAmount($order);
        $methods = $this->activeMethodsForCurrency($currency);
        $skippedReasons = [];

        foreach ($methods as $method) {
            if (!$method instanceof StorePaymentMethod) {
                continue;
            }

            $methodId = (int) ($method->getId() ?? 0);
            foreach ($this->methodSkipReasons($method, $order, $payableAmount, $currency) as $reason) {
                $skippedReasons[] = sprintf('method_id=%d reason=%s', $methodId, $reason);
            }
        }

        return [
            'orderId' => (int) ($order->getId() ?? 0),
            'amount' => $amount,
            'payableAmount' => $payableAmount,
            'currency' => $currency,
            'activeStorePaymentMethodCount' => count($methods),
            'skippedReasons' => $skippedReasons,
        ];
    }

    /**
     * @return list<StorePaymentMethod>
     */
    private function activeMethodsForCurrency(string $currency): array
    {
        $qb = $this->entityManager->getRepository(StorePaymentMethod::class)->createQueryBuilder('m');
        $qb->join('m.gateway', 'g')
            ->where('m.isActive = :active')
            ->andWhere('g.isActive = :gatewayActive')
            ->andWhere('m.currency = :currency')
            ->setParameter('active', true)
            ->setParameter('gatewayActive', true)
            ->setParameter('currency', $currency)
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        $methods = $qb->getQuery()->getResult();

        return array_values(array_filter(
            is_array($methods) ? $methods : [],
            static fn (mixed $item): bool => $item instanceof StorePaymentMethod
        ));
    }

    /**
     * @return list<string>
     */
    private function methodSkipReasons(StorePaymentMethod $method, Order $order, int $payableAmount, string $currency): array
    {
        $reasons = [];
        $gateway = $method->getGateway();

        if (!$gateway->isConfigured()) {
            $reasons[] = 'gateway_not_configured';
        }
        if (strtoupper($gateway->getCurrency()) !== $currency) {
            $reasons[] = 'gateway_currency_mismatch';
        }
        if (!$method->isAmountAllowed($payableAmount)) {
            $reasons[] = 'amount_out_of_range';
        }
        if (
            (int) ($order->getId() ?? 0) > 0
            && $order->getStatus() !== \App\Shop\Domain\OrderStatus::WAITING_PAYMENT
        ) {
            $reasons[] = 'order_not_waiting_payment';
        }

        return $reasons;
    }

    private function resolveCurrency(Order $order): string
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $currency = strtoupper(trim((string) ($metadata['currency'] ?? 'IRR')));

        return '' === $currency ? 'IRR' : $currency;
    }

    private function resolvePayableAmount(Order $order): int
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $value = (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount());

        return max(0, $value);
    }
}

