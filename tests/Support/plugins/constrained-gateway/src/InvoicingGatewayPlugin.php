<?php

declare(strict_types=1);

namespace Amoobot\Plugin\ConstrainedGateway;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;

/**
 * Test only: a provider with invoices that last $lifetime seconds, or that never end when it is null. A live invoice of
 * the row is handed out again with no provider call, as a real plugin must. Every provider call is counted in $calls and,
 * for a test across processes, as a line in the file named by TEST_INVOICE_CALLS_FILE; it takes TEST_INVOICE_DELAY_MS.
 * $answer makes the provider refuse ('fail'; 'unauthorized' and 'store_disabled' as rawResponse.error), blow up
 * ('throw') or answer without a reference ('no_reference'), and makes the plugin's lifetime blow up ('lifetime_throws').
 * $stamp leaves the expiry to the core when false. $expiryAtCall is what the row said when the provider was called.
 * $onCall runs at each provider call (e.g. to see the open transactions), $onLifetime when the core asks the lifetime:
 * after the caller checked the order, before the claim locks it.
 */
final class InvoicingGatewayPlugin extends UnconstrainedGatewayPlugin implements PaymentGatewayConstraintsInterface
{
    public static ?int $lifetime = 3600;
    public static int $calls = 0;
    public static ?string $answer = null;
    public static bool $stamp = true;
    public static ?\DateTimeImmutable $expiryAtCall = null;
    public static ?\Closure $onCall = null;
    public static ?\Closure $onLifetime = null;

    public static function reset(): void
    {
        self::$lifetime = 3600;
        self::$calls = 0;
        self::$answer = null;
        self::$stamp = true;
        self::$expiryAtCall = null;
        self::$onCall = null;
        self::$onLifetime = null;
    }

    public function isUsable(array $config): bool
    {
        return true;
    }

    public function acceptsAmount(int $toman): bool
    {
        return true;
    }

    public function invoiceLifetimeSeconds(): ?int
    {
        if (null !== self::$onLifetime) {
            (self::$onLifetime)();
        }
        if ('lifetime_throws' === self::$answer) {
            throw new \RuntimeException('The lifetime blew up.');
        }

        return self::$lifetime;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        $expiresAt = $payment->getExpiresAt();
        if (null !== $payment->getGatewayTransactionId() && null !== $expiresAt && $expiresAt > new \DateTimeImmutable()) {
            return new PaymentRequestResult(success: true, paymentUrl: $payment->getPaymentUrl(), transactionId: $payment->getGatewayTransactionId(), authority: $payment->getAuthority());
        }

        ++self::$calls;
        self::$expiryAtCall = $expiresAt;
        if (null !== self::$onCall) {
            (self::$onCall)();
        }
        $file = getenv('TEST_INVOICE_CALLS_FILE');
        if (is_string($file) && '' !== $file) {
            file_put_contents($file, sprintf("%d\n", $payment->getId() ?? 0), FILE_APPEND | LOCK_EX);
        }
        usleep(1000 * (int) getenv('TEST_INVOICE_DELAY_MS'));

        if ('throw' === self::$answer) {
            throw new \RuntimeException('The provider blew up.');
        }
        if ('fail' === self::$answer) {
            return new PaymentRequestResult(success: false, message: 'The provider refused.');
        }

        if ('unauthorized' === self::$answer || 'store_disabled' === self::$answer) {
            // The provider's own text may quote the key: the core's alert must not.
            return new PaymentRequestResult(success: false, message: 'Refused for key '.($config['api_key'] ?? ''), rawResponse: ['error' => self::$answer]);
        }
        if ('no_reference' === self::$answer) {
            return new PaymentRequestResult(success: true, paymentUrl: 'https://provider.test/no-reference');
        }

        $id = 'inv-'.bin2hex(random_bytes(6));
        if (self::$stamp && null !== self::$lifetime) {
            $payment->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d seconds', self::$lifetime)));
        }

        return new PaymentRequestResult(success: true, paymentUrl: 'https://provider.test/'.$id, transactionId: $id, authority: 'hash-'.$id);
    }
}
