<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;

final class ManualCardGateway implements PaymentGatewayInterface
{
    public function getType(): string
    {
        return PaymentGatewayType::MANUAL_CARD;
    }

    public function createPayment(Payment $payment, Order $order): PaymentRequestResult
    {
        return new PaymentRequestResult(
            success: true,
            message: 'manual_card'
        );
    }

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult
    {
        return new PaymentVerificationResult(
            success: true,
            paid: false,
            message: 'Manual verification required.'
        );
    }
}

