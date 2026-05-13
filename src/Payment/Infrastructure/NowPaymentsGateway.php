<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NOWPayments crypto payment gateway.
 *
 * NOWPayments IPN signature header: x-nowpayments-sig
 * Signature algorithm: HMAC-SHA512 over the sorted JSON payload using ipn_secret.
 *
 * Status mapping:
 *   PAID (trigger PaymentApprovalService): finished, confirmed
 *   PENDING (do not provision): waiting, confirming, partially_paid
 *   FAILED (do not provision): failed, expired, refunded
 */
final class NowPaymentsGateway implements PaymentGatewayInterface
{
    private const API_BASE = 'https://api.nowpayments.io/v1';
    private const SANDBOX_API_BASE = 'https://api-sandbox.nowpayments.io/v1';

    /** Statuses that are considered fully paid and trigger provisioning */
    public const PAID_STATUSES = ['finished', 'confirmed'];

    /** Statuses that are considered pending (no provisioning) */
    public const PENDING_STATUSES = ['waiting', 'confirming', 'partially_paid'];

    /** Statuses that are considered failed */
    public const FAILED_STATUSES = ['failed', 'expired', 'refunded'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getType(): string
    {
        return PaymentGatewayType::NOWPAYMENTS;
    }

    public function createPayment(Payment $payment, Order $order): PaymentRequestResult
    {
        $gateway = $payment->getGateway();
        $config = $this->resolveConfig($gateway);

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $callbackBaseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');
        $priceCurrency = strtolower(trim((string) ($config['price_currency'] ?? 'usd')));
        $payCurrency = strtolower(trim((string) ($config['pay_currency'] ?? '')));
        $sandbox = true === ($config['sandbox'] ?? false);

        if ('' === $apiKey) {
            return new PaymentRequestResult(success: false, message: 'NOWPayments api_key is not configured.');
        }
        if ('' === $callbackBaseUrl) {
            return new PaymentRequestResult(success: false, message: 'NOWPayments callback_base_url is not configured.');
        }
        if ('' === $payCurrency) {
            return new PaymentRequestResult(success: false, message: 'NOWPayments pay_currency is not configured.');
        }

        $priceAmount = $this->resolvePriceAmount($payment, $config);
        if (null === $priceAmount) {
            return new PaymentRequestResult(
                success: false,
                message: 'NOWPayments requires irr_to_usd_rate for IRR orders when price_currency is usd.'
            );
        }

        $orderId = (string) ($order->getTrackingCode() ?? $order->getId() ?? $payment->getId() ?? '');
        $description = trim((string) ($config['order_description'] ?? 'Amoobot VPN order'));
        $ipnCallbackUrl = $callbackBaseUrl.'/payment/webhook/nowpayments';
        $successUrl = trim((string) ($config['success_url'] ?? '')) ?: null;
        $cancelUrl = trim((string) ($config['cancel_url'] ?? '')) ?: null;

        $requestBody = [
            'price_amount' => $priceAmount,
            'price_currency' => $priceCurrency,
            'pay_currency' => $payCurrency,
            'order_id' => $orderId,
            'order_description' => $description,
            'ipn_callback_url' => $ipnCallbackUrl,
        ];
        if (null !== $successUrl) {
            $requestBody['success_url'] = $successUrl;
        }
        if (null !== $cancelUrl) {
            $requestBody['cancel_url'] = $cancelUrl;
        }

        // Store conversion snapshot
        $originalAmount = $payment->getPayableAmount() ?? $payment->getAmount();
        $originalCurrency = strtoupper($payment->getCurrency());
        $conversionSnapshot = [
            'originalAmount' => $originalAmount,
            'originalCurrency' => $originalCurrency,
            'priceAmount' => $priceAmount,
            'priceCurrency' => $priceCurrency,
        ];
        if ('IRR' === $originalCurrency && 'usd' === $priceCurrency) {
            $conversionSnapshot['rate'] = (int) ($config['irr_to_usd_rate'] ?? 0);
        }
        $payment->setRequestPayload(array_merge(['_conversion' => $conversionSnapshot], $requestBody));

        $baseUrl = $sandbox ? self::SANDBOX_API_BASE : self::API_BASE;

        try {
            $response = $this->httpClient->request('POST', $baseUrl.'/payment', [
                'headers' => ['x-api-key' => $apiKey],
                'json' => $requestBody,
                'timeout' => 20,
            ]);
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_create_exception', ['message' => $e->getMessage(), 'paymentId' => $payment->getId()]);

            return new PaymentRequestResult(success: false, message: 'در ارتباط با درگاه NOWPayments مشکل رخ داد.');
        }

        if (isset($raw['message']) && isset($raw['statusCode']) && !in_array((int) ($raw['statusCode'] ?? 0), [200, 201], true)) {
            $this->safeLog('nowpayments_create_failed', ['paymentId' => $payment->getId(), 'raw' => $raw]);

            return new PaymentRequestResult(
                success: false,
                message: (string) ($raw['message'] ?? 'NOWPayments payment creation failed.'),
                rawResponse: is_array($raw) ? $raw : null
            );
        }

        $paymentId = (string) ($raw['payment_id'] ?? '');
        $payAddress = (string) ($raw['pay_address'] ?? '');
        $payAmount = (string) ($raw['pay_amount'] ?? '');
        $payCurrencyActual = (string) ($raw['pay_currency'] ?? $payCurrency);
        $paymentStatus = (string) ($raw['payment_status'] ?? 'waiting');
        $purchaseId = (string) ($raw['purchase_id'] ?? '');
        $paymentUrlFromApi = (string) ($raw['invoice_url'] ?? $raw['payment_url'] ?? '');
        $network = (string) ($raw['network'] ?? '');
        $expirationEstimate = trim((string) ($raw['expiration_estimate_date'] ?? ''));

        if ('' === $paymentId) {
            $this->safeLog('nowpayments_create_no_payment_id', ['paymentId' => $payment->getId(), 'raw' => $raw]);

            return new PaymentRequestResult(
                success: false,
                message: 'NOWPayments did not return a payment_id.',
                rawResponse: is_array($raw) ? $raw : null
            );
        }

        $payment
            ->setCryptoPaymentId($paymentId)
            ->setCryptoPaymentStatus($paymentStatus)
            ->setCryptoAddress('' !== $payAddress ? $payAddress : null)
            ->setCryptoPayAmount('' !== $payAmount ? $payAmount : null)
            ->setCryptoPayCurrency('' !== $payCurrencyActual ? strtolower($payCurrencyActual) : null)
            ->setCryptoPriceCurrency($priceCurrency)
            ->setCryptoPurchaseId('' !== $purchaseId ? $purchaseId : null)
            ->setCryptoNetwork('' !== $network ? $network : null)
            ->setGatewayTransactionId($paymentId);

        if ('' !== $paymentUrlFromApi) {
            $payment->setPaymentUrl($paymentUrlFromApi);
        }

        if ('' !== $expirationEstimate) {
            try {
                $payment->setCryptoExpiresAt(new \DateTimeImmutable($expirationEstimate));
            } catch (\Throwable) {
                // ignore parse failure
            }
        }

        return new PaymentRequestResult(
            success: true,
            paymentUrl: '' !== $paymentUrlFromApi ? $paymentUrlFromApi : null,
            transactionId: $paymentId,
            message: 'nowpayments_created',
            rawResponse: is_array($raw) ? $raw : null
        );
    }

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult
    {
        $gateway = $payment->getGateway();
        $config = $this->resolveConfig($gateway);

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $sandbox = true === ($config['sandbox'] ?? false);

        if ('' === $apiKey) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'NOWPayments api_key is not configured.');
        }

        $paymentId = trim((string) ($payload['payment_id'] ?? $payment->getCryptoPaymentId() ?? $payment->getGatewayTransactionId() ?? ''));
        if ('' === $paymentId) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'NOWPayments payment_id not found.');
        }

        $baseUrl = $sandbox ? self::SANDBOX_API_BASE : self::API_BASE;

        try {
            $response = $this->httpClient->request('GET', $baseUrl.'/payment/'.$paymentId, [
                'headers' => ['x-api-key' => $apiKey],
                'timeout' => 20,
            ]);
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_verify_exception', ['message' => $e->getMessage(), 'paymentId' => $payment->getId()]);

            return new PaymentVerificationResult(success: false, paid: false, message: 'خطا در بررسی وضعیت پرداخت NOWPayments.');
        }

        $status = strtolower(trim((string) ($raw['payment_status'] ?? '')));
        $actuallyPaid = (string) ($raw['actually_paid'] ?? '');
        $outcomeAmount = (string) ($raw['outcome_amount'] ?? '');

        $payment
            ->setCryptoPaymentStatus('' !== $status ? $status : $payment->getCryptoPaymentStatus())
            ->setVerifyPayload(is_array($raw) ? $raw : null);

        if ('' !== $actuallyPaid) {
            $payment->setCryptoActuallyPaid($actuallyPaid);
        }
        if ('' !== $outcomeAmount) {
            $payment->setCryptoOutcomeAmount($outcomeAmount);
        }

        $paid = in_array($status, self::PAID_STATUSES, true);
        $failed = in_array($status, self::FAILED_STATUSES, true);

        if ($paid) {
            $payment
                ->setVerifiedAt($payment->getVerifiedAt() ?? new \DateTimeImmutable())
                ->setFailedAt(null);
        } elseif ($failed) {
            $payment->setFailedAt($payment->getFailedAt() ?? new \DateTimeImmutable());
        }

        return new PaymentVerificationResult(
            success: true,
            paid: $paid,
            transactionId: $paymentId,
            message: '' !== $status ? $status : 'unknown',
            rawResponse: is_array($raw) ? $raw : null
        );
    }

    /**
     * Validate NOWPayments IPN signature.
     *
     * NOWPayments sends the signature in HTTP header: x-nowpayments-sig
     * Algorithm: HMAC-SHA512 over the JSON-encoded sorted payload using ipn_secret as key.
     */
    public function validateIpnSignature(string $rawBody, string $receivedSignature, string $ipnSecret): bool
    {
        if ('' === $ipnSecret) {
            return true;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return false;
        }

        ksort($payload);
        $sortedJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $sortedJson) {
            return false;
        }

        $expected = hash_hmac('sha512', $sortedJson, $ipnSecret);

        return hash_equals(strtolower($expected), strtolower(trim($receivedSignature)));
    }

    /**
     * Resolve the price amount for the payment.
     * If order currency is IRR and price_currency is USD, convert using irr_to_usd_rate.
     *
     * @param array<string, mixed> $config
     */
    public function resolvePriceAmount(Payment $payment, array $config): ?float
    {
        $priceCurrency = strtolower(trim((string) ($config['price_currency'] ?? 'usd')));
        $originalAmount = $payment->getPayableAmount() ?? $payment->getAmount();
        $originalCurrency = strtoupper(trim((string) $payment->getCurrency()));

        if ('IRR' === $originalCurrency && 'usd' === $priceCurrency) {
            $rate = (int) ($config['irr_to_usd_rate'] ?? 0);
            if ($rate <= 0) {
                return null;
            }

            return round($originalAmount / $rate, 2);
        }

        // If currencies match or no conversion needed, use amount as-is
        return round((float) $originalAmount, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfig(?PaymentGateway $gateway): array
    {
        $config = $gateway?->getConfig();

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, array $context): void
    {
        // Mask api_key in logs
        unset($context['api_key'], $context['ipn_secret']);
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log(sprintf('[NowPaymentsGateway] %s %s', $event, false === $encoded ? '{}' : $encoded));
    }
}
