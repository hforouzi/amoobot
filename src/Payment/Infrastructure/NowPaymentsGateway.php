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
    public const DEFAULT_API_BASE = 'https://api.nowpayments.io/v1';
    public const BELOW_MINIMUM_USER_MESSAGE = 'مبلغ این سفارش برای پرداخت با این ارز کمتر از حداقل مجاز درگاه است. لطفاً روش پرداخت دیگری انتخاب کنید یا مبلغ سفارش را افزایش دهید.';

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

        $apiKey = $this->resolveApiKey($config);
        $callbackBaseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');
        $priceCurrency = strtolower(trim((string) ($config['price_currency'] ?? 'usd')));
        $payCurrency = strtolower(trim((string) ($config['pay_currency'] ?? '')));
        $sandbox = true === ($config['sandbox'] ?? false);
        $baseUrl = $this->resolveApiBaseUrl($config);
        $endpoint = $baseUrl.'/payment';

        if ('' === $apiKey) {
            return new PaymentRequestResult(success: false, message: 'NOWPayments api_key is not configured.');
        }
        if ('' === $callbackBaseUrl) {
            return new PaymentRequestResult(success: false, message: 'NOWPayments callback_base_url is not configured.');
        }
        if ('' === $payCurrency) {
            return new PaymentRequestResult(success: false, message: 'NOWPayments pay_currency is not configured.');
        }

        $quote = $this->buildPaymentQuote($gateway, $payment);
        $priceAmount = $quote['priceAmount'];
        if (!is_float($priceAmount)) {
            return new PaymentRequestResult(
                success: false,
                message: (string) ($quote['message'] ?? 'NOWPayments price amount could not be resolved.'),
                rawResponse: $this->buildRequestPayloadSnapshot([], $quote)
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

        $requestPayload = $this->buildRequestPayloadSnapshot($requestBody, $quote);
        $payment->setRequestPayload($requestPayload);

        if (!$quote['canCreate']) {
            if (true === ($quote['belowMinimum'] ?? false)) {
                $this->safeLog('nowpayments_below_minimum', $this->buildBelowMinimumLogContext($order, $gateway, $quote));
            }

            return new PaymentRequestResult(
                success: false,
                message: (string) ($quote['message'] ?? self::BELOW_MINIMUM_USER_MESSAGE),
                rawResponse: $requestPayload
            );
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => $this->buildPostHeaders($apiKey),
                'json' => $requestBody,
                'timeout' => 20,
            ]);
            $statusCode = $response->getStatusCode();
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_create_exception', array_merge(
                ['message' => $e->getMessage(), 'paymentId' => $payment->getId()],
                $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox)
            ));

            return new PaymentRequestResult(success: false, message: 'در ارتباط با درگاه NOWPayments مشکل رخ داد: '.$e->getMessage(), rawResponse: $requestPayload);
        }

        $rawMessage = trim((string) ($raw['message'] ?? ''));
        $requestPayload = $this->buildRequestPayloadSnapshot($requestBody, $quote, $raw, $statusCode);
        $payment->setRequestPayload($requestPayload);

        if ($statusCode >= 400 || (isset($raw['statusCode']) && !in_array((int) ($raw['statusCode'] ?? 0), [200, 201], true))) {
            $logEvent = $this->isInvalidApiKeyMessage($rawMessage)
                ? 'nowpayments_invalid_api_key'
                : ($this->isBelowMinimumMessage($rawMessage) ? 'nowpayments_below_minimum' : 'nowpayments_create_failed');
            $logContext = array_merge(
                ['paymentId' => $payment->getId(), 'statusCode' => $statusCode, 'raw' => $this->sanitizeResponse($raw)],
                $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox)
            );
            if ($this->isBelowMinimumMessage($rawMessage)) {
                $logContext = array_merge($logContext, $this->buildBelowMinimumLogContext($order, $gateway, $quote));
            }
            $this->safeLog($logEvent, $logContext);

            return new PaymentRequestResult(
                success: false,
                message: $this->buildUserFacingErrorMessage($rawMessage, 'خطا در اتصال به درگاه پرداخت ارز دیجیتال. لطفاً بعداً تلاش کنید.'),
                rawResponse: $requestPayload
            );
        }

        $paymentId = trim((string) ($raw['payment_id'] ?? ''));
        $payAddress = trim((string) ($raw['pay_address'] ?? ''));
        $payAmount = trim((string) ($raw['pay_amount'] ?? ''));
        $payCurrencyActual = trim((string) ($raw['pay_currency'] ?? $payCurrency));
        $paymentStatus = trim((string) ($raw['payment_status'] ?? 'waiting'));
        $purchaseId = trim((string) ($raw['purchase_id'] ?? ''));
        $paymentUrlFromApi = trim((string) ($raw['invoice_url'] ?? $raw['payment_url'] ?? ''));
        $network = trim((string) ($raw['network'] ?? ''));
        $expirationEstimate = trim((string) ($raw['expiration_estimate_date'] ?? ''));

        if ('' === $paymentId) {
            $this->safeLog('nowpayments_create_no_payment_id', [
                'paymentId' => $payment->getId(),
                'raw' => $this->sanitizeResponse($raw),
            ]);

            return new PaymentRequestResult(
                success: false,
                message: 'NOWPayments did not return a payment_id.',
                rawResponse: $requestPayload
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
            rawResponse: $requestPayload
        );
    }

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult
    {
        $gateway = $payment->getGateway();
        $config = $this->resolveConfig($gateway);

        $apiKey = $this->resolveApiKey($config);
        $sandbox = true === ($config['sandbox'] ?? false);
        $baseUrl = $this->resolveApiBaseUrl($config);

        if ('' === $apiKey) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'NOWPayments api_key is not configured.');
        }

        $paymentId = trim((string) ($payload['payment_id'] ?? $payment->getCryptoPaymentId() ?? $payment->getGatewayTransactionId() ?? ''));
        if ('' === $paymentId) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'NOWPayments payment_id not found.');
        }

        $endpoint = $baseUrl.'/payment/'.$paymentId;

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => $this->buildGetHeaders($apiKey),
                'timeout' => 20,
            ]);
            $statusCode = $response->getStatusCode();
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_verify_exception', array_merge(
                ['message' => $e->getMessage(), 'paymentId' => $payment->getId()],
                $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox)
            ));

            return new PaymentVerificationResult(success: false, paid: false, message: 'خطا در بررسی وضعیت پرداخت NOWPayments: '.$e->getMessage());
        }

        $rawMessage = trim((string) ($raw['message'] ?? ''));
        if ($statusCode >= 400) {
            $logEvent = $this->isInvalidApiKeyMessage($rawMessage) ? 'nowpayments_invalid_api_key' : 'nowpayments_verify_failed';
            $this->safeLog($logEvent, array_merge(
                ['paymentId' => $payment->getId(), 'statusCode' => $statusCode, 'raw' => $this->sanitizeResponse($raw)],
                $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox)
            ));

            return new PaymentVerificationResult(
                success: false,
                paid: false,
                message: $this->buildUserFacingErrorMessage($rawMessage, 'خطا در اتصال به درگاه پرداخت ارز دیجیتال. لطفاً بعداً تلاش کنید.'),
                rawResponse: is_array($raw) ? $raw : null
            );
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
     * @return array{success: bool, statusCode: int, endpoint: string, api_key_configured: string, api_key_length: int, api_key_prefix: string, sandbox: string, response: array<string, mixed>|list<mixed>|null, message: string}
     */
    public function testAuthentication(PaymentGateway $gateway): array
    {
        $config = $this->resolveConfig($gateway);
        $apiKey = $this->resolveApiKey($config);
        $sandbox = true === ($config['sandbox'] ?? false);
        $endpoint = $this->resolveApiBaseUrl($config).'/currencies';
        $diagnostics = $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox);

        if ('' === $apiKey) {
            return array_merge($diagnostics, [
                'success' => false,
                'statusCode' => 0,
                'response' => null,
                'message' => 'NOWPayments api_key is not configured.',
            ]);
        }

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => $this->buildGetHeaders($apiKey),
                'timeout' => 20,
            ]);
            $statusCode = $response->getStatusCode();
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_auth_test_exception', array_merge(['message' => $e->getMessage()], $diagnostics));

            return array_merge($diagnostics, [
                'success' => false,
                'statusCode' => 0,
                'response' => null,
                'message' => $e->getMessage(),
            ]);
        }

        $rawMessage = trim((string) ($raw['message'] ?? ''));
        if ($statusCode >= 400) {
            $logEvent = $this->isInvalidApiKeyMessage($rawMessage) ? 'nowpayments_invalid_api_key' : 'nowpayments_auth_test_failed';
            $this->safeLog($logEvent, array_merge(['statusCode' => $statusCode, 'raw' => $this->sanitizeResponse($raw)], $diagnostics));
        }

        return array_merge($diagnostics, [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'statusCode' => $statusCode,
            'response' => is_array($raw) ? $this->sanitizeResponse($raw) : null,
            'message' => $rawMessage,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function debugAmount(PaymentGateway $gateway, int $amount): array
    {
        $payment = (new Payment())
            ->setGateway($gateway)
            ->setGatewayType($gateway->getType())
            ->setMethod($gateway->getType())
            ->setCurrency($gateway->getCurrency())
            ->setAmount(max(1, $amount))
            ->setPayableAmount(max(1, $amount));

        return $this->buildPaymentQuote($gateway, $payment);
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
     *
     * @param array<string, mixed> $config
     */
    public function resolvePriceAmount(Payment $payment, array $config): ?float
    {
        $snapshot = $this->resolveConversionSnapshot($payment, $config);

        return is_float($snapshot['priceAmount']) ? $snapshot['priceAmount'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentQuote(?PaymentGateway $gateway, Payment $payment): array
    {
        $config = $this->resolveConfig($gateway);
        $apiKey = $this->resolveApiKey($config);
        $priceCurrency = strtolower(trim((string) ($config['price_currency'] ?? 'usd')));
        $payCurrency = strtolower(trim((string) ($config['pay_currency'] ?? '')));
        $sandbox = true === ($config['sandbox'] ?? false);
        $baseUrl = $this->resolveApiBaseUrl($config);
        $conversionSnapshot = $this->resolveConversionSnapshot($payment, $config);
        $priceAmount = $conversionSnapshot['priceAmount'];
        $estimatedPayAmount = null;
        $minAmount = null;
        $minAmountCurrency = null;
        $minPriceAmount = null;
        $comparisonBasis = null;
        $estimateStatusCode = 0;
        $estimateResponse = null;
        $estimateMessage = '';
        $estimateEndpoint = $baseUrl.'/estimate';
        $minStatusCode = 0;
        $minResponse = null;
        $minMessage = '';
        $minEndpoint = $baseUrl.'/min-amount';
        $canCreate = true;
        $belowMinimum = false;
        $message = '';

        if ('' === $apiKey) {
            $canCreate = false;
            $message = 'NOWPayments api_key is not configured.';
        } elseif ('' === $payCurrency) {
            $canCreate = false;
            $message = 'NOWPayments pay_currency is not configured.';
        } elseif (!is_float($priceAmount)) {
            $canCreate = false;
            $message = $this->buildMissingRateMessage($conversionSnapshot);
        }

        if ($canCreate) {
            [$estimateStatusCode, $estimateResponse, $estimateMessage] = $this->fetchEstimate(
                $apiKey,
                $estimateEndpoint,
                (float) $priceAmount,
                $priceCurrency,
                $payCurrency,
                $sandbox
            );
            $estimatedPayAmount = $this->extractDecimalString($estimateResponse, ['estimated_amount', 'estimatedAmount', 'pay_amount']);

            [$minStatusCode, $minResponse, $minMessage] = $this->fetchMinAmount(
                $apiKey,
                $minEndpoint,
                $priceCurrency,
                $payCurrency,
                $sandbox
            );

            $minAnalysis = $this->analyzeMinimumAmount(
                $minResponse,
                (float) $priceAmount,
                $estimatedPayAmount,
                $priceCurrency,
                $payCurrency
            );
            $minAmount = $minAnalysis['minAmount'];
            $minAmountCurrency = $minAnalysis['minAmountCurrency'];
            $minPriceAmount = $minAnalysis['minPriceAmount'];
            $comparisonBasis = $minAnalysis['comparisonBasis'];
            $belowMinimum = $minAnalysis['belowMinimum'];

            $override = $this->resolveMinPriceAmountOverride($config);
            if (null !== $override && (float) $priceAmount < $override) {
                $belowMinimum = true;
                if (null === $minPriceAmount || $override > $minPriceAmount) {
                    $minPriceAmount = $override;
                    $minAmount = $this->formatDecimal($override);
                    $minAmountCurrency = $priceCurrency;
                    $comparisonBasis = 'price';
                }
            }

            if ($belowMinimum) {
                $canCreate = false;
                $message = self::BELOW_MINIMUM_USER_MESSAGE;
            }
        }

        return [
            'canCreate' => $canCreate,
            'belowMinimum' => $belowMinimum,
            'message' => $message,
            'api_key_configured' => '' !== $apiKey ? 'yes' : 'no',
            'api_key_length' => strlen($apiKey),
            'api_key_prefix' => '' !== $apiKey ? substr($apiKey, 0, 4) : '',
            'endpoint' => $baseUrl,
            'sandbox' => $sandbox ? 'yes' : 'no',
            'originalAmount' => $conversionSnapshot['originalAmount'],
            'originalCurrency' => $conversionSnapshot['originalCurrency'],
            'priceAmount' => $priceAmount,
            'priceCurrency' => $priceCurrency,
            'payCurrency' => $payCurrency,
            'estimatedPayAmount' => $estimatedPayAmount,
            'minAmount' => $minAmount,
            'minAmountCurrency' => $minAmountCurrency,
            'minPriceAmount' => $minPriceAmount,
            'amountUnit' => $conversionSnapshot['amountUnit'],
            'rateSnapshot' => $conversionSnapshot['rateSnapshot'],
            'estimate' => [
                'endpoint' => $estimateEndpoint,
                'statusCode' => $estimateStatusCode,
                'message' => $estimateMessage,
                'response' => $estimateResponse,
            ],
            'minAmountCheck' => [
                'endpoint' => $minEndpoint,
                'statusCode' => $minStatusCode,
                'message' => $minMessage,
                'response' => $minResponse,
                'comparisonBasis' => $comparisonBasis,
            ],
        ];
    }

    /**
     * @return array{originalAmount: int|float, originalCurrency: string, amountUnit: string, priceAmount: ?float, rateSnapshot: array<string, mixed>}
     */
    private function resolveConversionSnapshot(Payment $payment, array $config): array
    {
        $priceCurrency = strtolower(trim((string) ($config['price_currency'] ?? 'usd')));
        $originalAmount = $payment->getPayableAmount() ?? $payment->getAmount();
        $originalCurrency = strtoupper(trim((string) $payment->getCurrency()));
        $amountUnit = $this->resolveAmountUnit($config);
        $rateField = null;
        $rateUsed = null;

        if ('IRR' === $originalCurrency && 'usd' === $priceCurrency) {
            $rateField = 'rial' === $amountUnit ? 'irr_to_usd_rate' : 'toman_per_usd';
            $rateUsed = $this->resolveUsdRate($config, $amountUnit);
            if (null === $rateUsed || $rateUsed <= 0) {
                return [
                    'originalAmount' => $originalAmount,
                    'originalCurrency' => $originalCurrency,
                    'amountUnit' => $amountUnit,
                    'priceAmount' => null,
                    'rateSnapshot' => [
                        'amountUnit' => $amountUnit,
                        'rateField' => $rateField,
                        'rateUsed' => null,
                    ],
                ];
            }

            return [
                'originalAmount' => $originalAmount,
                'originalCurrency' => $originalCurrency,
                'amountUnit' => $amountUnit,
                'priceAmount' => round((float) $originalAmount / $rateUsed, 2),
                'rateSnapshot' => [
                    'amountUnit' => $amountUnit,
                    'rateField' => $rateField,
                    'rateUsed' => $rateUsed,
                ],
            ];
        }

        return [
            'originalAmount' => $originalAmount,
            'originalCurrency' => $originalCurrency,
            'amountUnit' => $amountUnit,
            'priceAmount' => round((float) $originalAmount, 2),
            'rateSnapshot' => [
                'amountUnit' => $amountUnit,
                'rateField' => null,
                'rateUsed' => null,
            ],
        ];
    }

    /**
     * @return array{minAmount: ?string, minAmountCurrency: ?string, minPriceAmount: ?float, comparisonBasis: ?string, belowMinimum: bool}
     */
    private function analyzeMinimumAmount(?array $response, float $priceAmount, ?string $estimatedPayAmount, string $priceCurrency, string $payCurrency): array
    {
        if (!is_array($response)) {
            return [
                'minAmount' => null,
                'minAmountCurrency' => null,
                'minPriceAmount' => null,
                'comparisonBasis' => null,
                'belowMinimum' => false,
            ];
        }

        $minAmount = $this->extractFloat($response, ['min_amount', 'minAmount', 'minimum_amount']);
        if (null === $minAmount || $minAmount <= 0) {
            return [
                'minAmount' => null,
                'minAmountCurrency' => null,
                'minPriceAmount' => null,
                'comparisonBasis' => null,
                'belowMinimum' => false,
            ];
        }

        $currencyFrom = strtolower((string) ($this->extractString($response, ['currency_from', 'currencyFrom']) ?? ''));
        $currencyTo = strtolower((string) ($this->extractString($response, ['currency_to', 'currencyTo']) ?? ''));
        $fiatEquivalent = $this->extractFloat($response, ['fiat_equivalent', 'fiatEquivalent']);
        $estimatedPayAmountFloat = null !== $estimatedPayAmount && '' !== $estimatedPayAmount ? (float) $estimatedPayAmount : null;

        if (null !== $fiatEquivalent && $fiatEquivalent > 0 && 'usd' === $priceCurrency) {
            return [
                'minAmount' => $this->formatDecimal($fiatEquivalent),
                'minAmountCurrency' => $priceCurrency,
                'minPriceAmount' => $fiatEquivalent,
                'comparisonBasis' => 'price',
                'belowMinimum' => $priceAmount < $fiatEquivalent,
            ];
        }

        if ('' !== $currencyTo && $currencyTo === $payCurrency && null !== $estimatedPayAmountFloat) {
            return [
                'minAmount' => $this->formatDecimal($minAmount),
                'minAmountCurrency' => $payCurrency,
                'minPriceAmount' => null,
                'comparisonBasis' => 'pay',
                'belowMinimum' => $estimatedPayAmountFloat < $minAmount,
            ];
        }

        $comparisonCurrency = '' !== $currencyFrom ? $currencyFrom : $priceCurrency;

        return [
            'minAmount' => $this->formatDecimal($minAmount),
            'minAmountCurrency' => $comparisonCurrency,
            'minPriceAmount' => $minAmount,
            'comparisonBasis' => 'price',
            'belowMinimum' => $priceAmount < $minAmount,
        ];
    }

    /**
     * @return array{0: int, 1: ?array, 2: string}
     */
    private function fetchEstimate(string $apiKey, string $endpoint, float $priceAmount, string $priceCurrency, string $payCurrency, bool $sandbox): array
    {
        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => $this->buildGetHeaders($apiKey),
                'query' => [
                    'amount' => $this->formatDecimal($priceAmount),
                    'currency_from' => $priceCurrency,
                    'currency_to' => $payCurrency,
                ],
                'timeout' => 20,
            ]);
            $statusCode = $response->getStatusCode();
            $raw = $response->toArray(false);

            return [$statusCode, is_array($raw) ? $this->sanitizeResponse($raw) : null, trim((string) ($raw['message'] ?? ''))];
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_estimate_exception', array_merge(
                ['message' => $e->getMessage()],
                $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox)
            ));

            return [0, null, $e->getMessage()];
        }
    }

    /**
     * @return array{0: int, 1: ?array, 2: string}
     */
    private function fetchMinAmount(string $apiKey, string $endpoint, string $priceCurrency, string $payCurrency, bool $sandbox): array
    {
        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => $this->buildGetHeaders($apiKey),
                'query' => [
                    'currency_from' => $priceCurrency,
                    'currency_to' => $payCurrency,
                ],
                'timeout' => 20,
            ]);
            $statusCode = $response->getStatusCode();
            $raw = $response->toArray(false);

            return [$statusCode, is_array($raw) ? $this->sanitizeResponse($raw) : null, trim((string) ($raw['message'] ?? ''))];
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('nowpayments_min_amount_exception', array_merge(
                ['message' => $e->getMessage()],
                $this->buildApiDiagnostics($apiKey, $endpoint, $sandbox)
            ));

            return [0, null, $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function resolveConfig(?PaymentGateway $gateway): array
    {
        $config = $gateway?->getConfig();

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveApiKey(array $config): string
    {
        return trim((string) ($config['api_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveApiBaseUrl(array $config): string
    {
        $value = trim((string) ($config['api_base_url'] ?? self::DEFAULT_API_BASE));

        return '' === $value ? self::DEFAULT_API_BASE : rtrim($value, '/');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveAmountUnit(array $config): string
    {
        $value = strtolower(trim((string) ($config['amount_unit'] ?? 'toman')));

        return in_array($value, ['toman', 'rial'], true) ? $value : 'toman';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveUsdRate(array $config, string $amountUnit): ?int
    {
        $key = 'rial' === $amountUnit ? 'irr_to_usd_rate' : 'toman_per_usd';
        $value = (int) ($config[$key] ?? 0);

        return $value > 0 ? $value : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveMinPriceAmountOverride(array $config): ?float
    {
        $value = trim((string) ($config['min_price_amount_override'] ?? ''));
        if ('' === $value) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? round($number, 8) : null;
    }

    /**
     * @return array<string, string>
     */
    private function buildPostHeaders(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildGetHeaders(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array{api_key_configured: string, api_key_length: int, api_key_prefix: string, endpoint: string, sandbox: string}
     */
    private function buildApiDiagnostics(string $apiKey, string $endpoint, bool $sandbox): array
    {
        return [
            'api_key_configured' => '' !== $apiKey ? 'yes' : 'no',
            'api_key_length' => strlen($apiKey),
            'api_key_prefix' => '' !== $apiKey ? substr($apiKey, 0, 4) : '',
            'endpoint' => $endpoint,
            'sandbox' => $sandbox ? 'yes' : 'no',
        ];
    }

    /**
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $createResponse
     *
     * @return array<string, mixed>
     */
    private function buildRequestPayloadSnapshot(array $requestBody, array $quote, ?array $createResponse = null, int $createStatusCode = 0): array
    {
        $payload = [
            'priceAmount' => $quote['priceAmount'] ?? null,
            'priceCurrency' => $quote['priceCurrency'] ?? null,
            'payCurrency' => $quote['payCurrency'] ?? null,
            'estimatedPayAmount' => $quote['estimatedPayAmount'] ?? null,
            'minAmount' => $quote['minAmount'] ?? null,
            'minAmountCurrency' => $quote['minAmountCurrency'] ?? null,
            'eligible' => true === ($quote['canCreate'] ?? false) ? 'yes' : 'no',
            'rateSnapshot' => $quote['rateSnapshot'] ?? null,
        ];

        if ([] !== $requestBody) {
            $payload['_request'] = $requestBody;
        }

        $estimate = $quote['estimate'] ?? null;
        if (is_array($estimate)) {
            $payload['_estimate'] = $estimate;
        }

        $minAmountCheck = $quote['minAmountCheck'] ?? null;
        if (is_array($minAmountCheck)) {
            $payload['_minAmountCheck'] = $minAmountCheck;
        }

        if (null !== $createResponse) {
            $payload['_createResponse'] = [
                'statusCode' => $createStatusCode,
                'response' => $this->sanitizeResponse($createResponse),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBelowMinimumLogContext(Order $order, ?PaymentGateway $gateway, array $quote): array
    {
        $rateSnapshot = is_array($quote['rateSnapshot'] ?? null) ? $quote['rateSnapshot'] : [];

        return [
            'order_id' => (int) ($order->getId() ?? 0),
            'gateway_id' => (int) ($gateway?->getId() ?? 0),
            'priceAmount' => $quote['priceAmount'] ?? null,
            'priceCurrency' => $quote['priceCurrency'] ?? null,
            'payCurrency' => $quote['payCurrency'] ?? null,
            'estimatedPayAmount' => $quote['estimatedPayAmount'] ?? null,
            'minAmount' => $quote['minAmount'] ?? null,
            'amountUnit' => $quote['amountUnit'] ?? null,
            'rate_used' => $rateSnapshot['rateUsed'] ?? null,
            'rate_field' => $rateSnapshot['rateField'] ?? null,
            'estimate_response' => is_array($quote['estimate'] ?? null) ? ($quote['estimate']['response'] ?? null) : null,
            'min_amount_response' => is_array($quote['minAmountCheck'] ?? null) ? ($quote['minAmountCheck']['response'] ?? null) : null,
        ];
    }

    private function buildMissingRateMessage(array $conversionSnapshot): string
    {
        return match ((string) ($conversionSnapshot['amountUnit'] ?? 'toman')) {
            'rial' => 'NOWPayments requires irr_to_usd_rate for IRR orders when price_currency is usd.',
            default => 'NOWPayments requires toman_per_usd for IRR orders when price_currency is usd and amount_unit is toman.',
        };
    }

    private function isInvalidApiKeyMessage(string $message): bool
    {
        return str_contains(strtolower($message), 'invalid api key');
    }

    private function isBelowMinimumMessage(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'less than minimal')
            || str_contains($normalized, 'below minimal')
            || str_contains($normalized, 'minimum amount');
    }

    private function buildUserFacingErrorMessage(string $rawMessage, string $defaultMessage): string
    {
        if ($this->isInvalidApiKeyMessage($rawMessage)) {
            return 'خطا در اتصال به درگاه پرداخت ارز دیجیتال. لطفاً بعداً تلاش کنید.';
        }

        if ($this->isBelowMinimumMessage($rawMessage)) {
            return self::BELOW_MINIMUM_USER_MESSAGE;
        }

        return '' !== $rawMessage ? $rawMessage : $defaultMessage;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return array<string, mixed>|list<mixed>
     */
    private function sanitizeResponse(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeResponse($value) : $value, $payload);
        }

        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, ['api_key', 'ipn_secret'], true)) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizeResponse($value) : $value;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function extractDecimalString(?array $payload, array $keys): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ('' !== $text) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractFloat(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ('' === $text || !is_numeric($text)) {
                continue;
            }

            return (float) $text;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ('' !== $text) {
                return $text;
            }
        }

        return null;
    }

    private function formatDecimal(float $value): string
    {
        $formatted = number_format($value, 8, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, array $context): void
    {
        unset($context['api_key'], $context['ipn_secret']);
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log(sprintf('[NowPaymentsGateway] %s %s', $event, false === $encoded ? '{}' : $encoded));
    }
}
