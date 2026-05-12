<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayInterface;

final class PaymentGatewayRegistry
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $driversByType = [];

    /**
     * @param iterable<PaymentGatewayInterface> $drivers
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $type = trim($driver->getType());
            if ('' === $type) {
                continue;
            }
            $this->driversByType[$type] = $driver;
        }
    }

    public function resolveByType(string $type): PaymentGatewayInterface
    {
        $type = trim($type);
        if (isset($this->driversByType[$type])) {
            return $this->driversByType[$type];
        }

        throw new \RuntimeException(sprintf('No payment gateway driver registered for type "%s".', $type));
    }

    public function resolve(PaymentGateway $gateway): PaymentGatewayInterface
    {
        return $this->resolveByType($gateway->getType());
    }
}

