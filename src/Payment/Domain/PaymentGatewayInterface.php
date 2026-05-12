<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;

interface PaymentGatewayInterface
{
    public function getType(): string;

    public function createPayment(Payment $payment, Order $order): PaymentRequestResult;

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult;
}
