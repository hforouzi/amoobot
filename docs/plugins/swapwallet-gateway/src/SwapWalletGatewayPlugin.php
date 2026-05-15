<?php

declare(strict_types=1);

namespace Amoobot\Plugin\SwapWallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class SwapWalletGatewayPlugin implements PaymentGatewayPluginInterface
{
    // TODO: SwapWallet's current OpenAPI document publishes these invoice
    // request/response schemas but omits their path entries. Keep the inferred
    // paths isolated here so they can be corrected without changing flow code.
    private const CREATE_INVOICE_PATH = '/v2/application/invoice';
    private const CREATE_TEMPORARY_WALLET_PATH = '/v2/application/invoice/temporary-wallet';
    private const INVOICE_STATUS_PATH = '/v2/application/invoice/%s';

    public function getType(): string
    {
        return 'swapwallet';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        $configError = $this->validateConfig($config, true);
        if (null !== $configError) {
            return new PaymentRequestResult(success: false, message: $configError);
        }

        $conversion = $this->conversionSnapshot($payment, $order, $config);
        if (isset($conversion['error'])) {
            return new PaymentRequestResult(success: false, message: (string) $conversion['error']);
        }

        $paymentMode = $this->paymentMode($config);
        $payload = 'direct' === $paymentMode
            ? $this->buildDirectPayload($payment, $order, $config, $conversion)
            : $this->buildInvoicePayload($payment, $order, $config, $conversion);

        $path = 'direct' === $paymentMode ? self::CREATE_TEMPORARY_WALLET_PATH : self::CREATE_INVOICE_PATH;

        try {
            $response = $this->requestJson('POST', $this->endpoint($config, $path), $config, ['json' => $payload]);
        } catch (\Throwable $exception) {
            return new PaymentRequestResult(
                success: false,
                message: 'SwapWallet payment request failed.',
                rawResponse: ['requestPayload' => $this->sanitize($payload), 'error' => $exception->getMessage()],
            );
        }

        if (!$this->isOkResponse($response)) {
            return new PaymentRequestResult(
                success: false,
                message: $this->responseMessage($response, 'SwapWallet did not create the payment.'),
                rawResponse: $this->withConversion($response, $conversion),
            );
        }

        $result = $this->responseResult($response);
        $transactionId = $this->stringOrNull($result['id'] ?? $response['id'] ?? null);
        $paymentUrl = $this->findPaymentUrl($result);

        if ('invoice' === $paymentMode && null === $paymentUrl) {
            return new PaymentRequestResult(
                success: false,
                message: 'SwapWallet response did not include a payment URL.',
                rawResponse: $this->withConversion($response, $conversion),
            );
        }

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $paymentUrl,
            transactionId: $transactionId,
            authority: $transactionId,
            message: 'SwapWallet payment created.',
            rawResponse: $this->withConversion($this->augmentDirectResponse($result, $response), $conversion),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $configError = $this->validateConfig($config, false);
        if (null !== $configError) {
            return new PaymentVerificationResult(success: false, paid: false, message: $configError);
        }

        $invoiceId = $this->findPaymentIdentifier($payment, $payload);
        if (null === $invoiceId) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                message: 'SwapWallet payment identifier was not found.',
                rawResponse: $this->sanitize($payload),
            );
        }

        try {
            $response = $this->requestJson('GET', $this->endpoint($config, sprintf(self::INVOICE_STATUS_PATH, rawurlencode($invoiceId))), $config);
        } catch (\Throwable $exception) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                transactionId: $invoiceId,
                message: 'SwapWallet verification failed.',
                rawResponse: ['payload' => $this->sanitize($payload), 'error' => $exception->getMessage()],
            );
        }

        if (!$this->isOkResponse($response)) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                transactionId: $invoiceId,
                message: $this->responseMessage($response, 'SwapWallet verification returned an error.'),
                rawResponse: $this->sanitize($response),
            );
        }

        $result = $this->responseResult($response);
        $status = $this->normalizedStatus($this->stringOrNull($result['status'] ?? $response['status'] ?? null));
        $transactionId = $this->stringOrNull($result['id'] ?? null) ?? $invoiceId;
        $refId = $this->stringOrNull($result['supportCode'] ?? $result['support_code'] ?? $result['transactionId'] ?? null);

        if ($this->isPaidStatus($status)) {
            return new PaymentVerificationResult(
                success: true,
                paid: true,
                transactionId: $transactionId,
                refId: $refId,
                message: 'SwapWallet payment verified.',
                rawResponse: $this->sanitize($response),
            );
        }

        return new PaymentVerificationResult(
            success: true,
            paid: false,
            transactionId: $transactionId,
            refId: $refId,
            message: $this->statusMessage($status),
            rawResponse: $this->sanitize($response),
        );
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        $webhookSecret = trim((string) ($config['webhook_secret'] ?? ''));
        if ('' !== $webhookSecret && !$this->isValidWebhookSignature($request, $webhookSecret)) {
            return new PaymentWebhookResult(
                handled: false,
                message: 'SwapWallet webhook signature is invalid.',
                payload: $this->sanitize($payload),
            );
        }

        $invoiceId = $this->stringOrNull($payload['id'] ?? $payload['invoiceId'] ?? $payload['invoice_id'] ?? $payload['payment_id'] ?? null);
        $orderId = $this->stringOrNull($payload['orderId'] ?? $payload['order_id'] ?? null);
        $status = $this->normalizedStatus($this->stringOrNull($payload['status'] ?? null));

        return new PaymentWebhookResult(
            handled: null !== $invoiceId || null !== $orderId,
            message: null !== $status ? $this->statusMessage($status) : 'SwapWallet webhook received.',
            payload: array_filter([
                'gateway' => 'swapwallet',
                'payment_id' => $invoiceId,
                'invoice_id' => $invoiceId,
                'order_id' => $orderId,
                'status' => $status,
                'paid' => $this->isPaidStatus($status),
                'raw' => $this->sanitize($payload),
            ], static fn (mixed $value): bool => null !== $value),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateConfig(array $config, bool $forCreate): ?string
    {
        foreach (['api_key', 'api_base_url'] as $key) {
            if ('' === trim((string) ($config[$key] ?? ''))) {
                return sprintf('SwapWallet %s is not configured.', $key);
            }
        }

        if ($forCreate) {
            foreach (['callback_base_url', 'payment_mode', 'price_currency', 'amount_unit', 'toman_per_usd'] as $key) {
                if ('' === trim((string) ($config[$key] ?? ''))) {
                    return sprintf('SwapWallet %s is not configured.', $key);
                }
            }
        }

        if (!in_array($this->paymentMode($config), ['invoice', 'direct'], true)) {
            return 'SwapWallet payment_mode must be invoice or direct.';
        }

        if ('direct' === $this->paymentMode($config)) {
            if ('' === trim((string) ($config['pay_currency'] ?? ''))) {
                return 'SwapWallet direct mode requires pay_currency.';
            }

            if ('' === trim((string) ($config['network'] ?? ''))) {
                return 'SwapWallet direct mode requires network.';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function endpoint(array $config, string $path): string
    {
        $baseUrl = rtrim(trim((string) ($config['api_base_url'] ?? '')), '/');
        if ('' === $baseUrl) {
            $baseUrl = 'https://swapwallet.app/api';
        }

        return $baseUrl.$path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMode(array $config): string
    {
        return strtolower(trim((string) ($config['payment_mode'] ?? 'invoice')));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function conversionSnapshot(Payment $payment, Order $order, array $config): array
    {
        $payableAmount = max(0, (int) ($payment->getPayableAmount() ?? $payment->getAmount() ?: $order->getAmount()));
        $amountUnit = strtolower(trim((string) ($config['amount_unit'] ?? 'toman')));
        if (!in_array($amountUnit, ['toman', 'rial'], true)) {
            return ['error' => 'SwapWallet amount_unit must be toman or rial.'];
        }

        $tomanPerUsd = (float) ($config['toman_per_usd'] ?? 0);
        if ($tomanPerUsd <= 0) {
            return ['error' => 'SwapWallet requires toman_per_usd for IRR orders.'];
        }

        $amountToman = 'rial' === $amountUnit ? $payableAmount / 10 : $payableAmount;
        $marginPercent = (float) ($config['rate_margin_percent'] ?? 0);
        $finalRate = $tomanPerUsd * (1 + ($marginPercent / 100));
        if ($finalRate <= 0) {
            return ['error' => 'SwapWallet final USD rate must be greater than zero.'];
        }

        $priceAmount = $amountToman / $finalRate;
        $priceCurrency = strtoupper(trim((string) ($config['price_currency'] ?? 'USD')));
        $payCurrency = strtoupper(trim((string) ($config['pay_currency'] ?? 'USDT')));
        $network = $this->normalizeNetwork(trim((string) ($config['network'] ?? 'TRON')));

        return [
            'originalAmount' => $payableAmount,
            'originalCurrency' => 'IRR',
            'amountUnit' => $amountUnit,
            'amountToman' => $this->formatNumber($amountToman),
            'tomanPerUsd' => $this->formatNumber($tomanPerUsd),
            'rateMarginPercent' => $this->formatNumber($marginPercent),
            'finalTomanPerUsd' => $this->formatNumber($finalRate),
            'priceAmount' => $this->formatNumber($priceAmount),
            'priceCurrency' => $priceCurrency,
            'payCurrency' => $payCurrency,
            'network' => $network,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $conversion
     *
     * @return array<string, mixed>
     */
    private function buildInvoicePayload(Payment $payment, Order $order, array $config, array $conversion): array
    {
        return $this->filterPayload([
            'amount' => [
                'number' => (string) $conversion['priceAmount'],
                'unit' => (string) $conversion['priceCurrency'],
            ],
            'allowedTokens' => [[
                'token' => (string) $conversion['payCurrency'],
                'network' => (string) $conversion['network'],
            ]],
            'feePayer' => 'APPLICATION',
            'userLanguage' => 'FA',
            'ttl' => (int) ($config['ttl'] ?? 3600),
            'underPaidCoveragePercent' => '0',
            'returnUrl' => $this->successUrl($config),
            'webhookUrl' => $this->buildWebhookUrl($payment, $order, $config),
            'orderId' => $this->orderId($payment, $order),
            'description' => $this->description($config),
            'customData' => json_encode([
                'payment_id' => $payment->getId(),
                'order_id' => $order->getId(),
                'tracking_code' => $payment->getTrackingCode() ?? $order->getTrackingCode(),
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $conversion
     *
     * @return array<string, mixed>
     */
    private function buildDirectPayload(Payment $payment, Order $order, array $config, array $conversion): array
    {
        return $this->filterPayload([
            'amount' => [
                'number' => (string) $conversion['priceAmount'],
                'unit' => (string) $conversion['priceCurrency'],
            ],
            'allowedToken' => (string) $conversion['payCurrency'],
            'network' => (string) $conversion['network'],
            'ttl' => (int) ($config['ttl'] ?? 3600),
            'underPaidCoveragePercent' => '0',
            'orderId' => $this->orderId($payment, $order),
            'webhookUrl' => $this->buildWebhookUrl($payment, $order, $config),
            'description' => $this->description($config),
            'customData' => json_encode([
                'payment_id' => $payment->getId(),
                'order_id' => $order->getId(),
                'tracking_code' => $payment->getTrackingCode() ?? $order->getTrackingCode(),
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function successUrl(array $config): ?string
    {
        $successUrl = $this->stringOrNull($config['success_url'] ?? null);
        if (null !== $successUrl) {
            return $successUrl;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function description(array $config): string
    {
        return trim((string) ($config['description'] ?? '')) ?: 'Amoobot crypto payment';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildWebhookUrl(Payment $payment, Order $order, array $config): string
    {
        $baseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');
        $query = http_build_query(array_filter([
            'payment_id' => $payment->getId(),
            'order_id' => $order->getId(),
            'tracking_code' => $payment->getTrackingCode() ?? $order->getTrackingCode(),
        ], static fn (mixed $value): bool => null !== $value && '' !== (string) $value));

        return $baseUrl.'/payment/webhook/plugin/swapwallet'.('' !== $query ? '?'.$query : '');
    }

    private function orderId(Payment $payment, Order $order): string
    {
        $trackingCode = $payment->getTrackingCode() ?? $order->getTrackingCode();
        if (null !== $trackingCode && '' !== trim($trackingCode)) {
            return $trackingCode;
        }

        if (null !== $payment->getId()) {
            return 'payment_'.$payment->getId();
        }

        if (null !== $order->getId()) {
            return 'order_'.$order->getId();
        }

        return 'amoobot_'.bin2hex(random_bytes(6));
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $config, array $options = []): array
    {
        $client = HttpClient::create(['timeout' => 20]);
        $headers = array_merge([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.trim((string) $config['api_key']),
        ], (array) ($options['headers'] ?? []));

        if ('GET' !== strtoupper($method)) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $client->request($method, $url, array_merge($options, ['headers' => $headers]));

        try {
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException($exception->getMessage(), previous: $exception);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('SwapWallet returned an invalid JSON response.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function isOkResponse(array $response): bool
    {
        $status = strtoupper(trim((string) ($response['status'] ?? '')));

        return '' === $status || 'OK' === $status || 'SUCCESS' === $status;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function responseResult(array $response): array
    {
        return is_array($response['result'] ?? null) ? $response['result'] : (is_array($response['data'] ?? null) ? $response['data'] : $response);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseMessage(array $response, string $fallback): string
    {
        $message = trim((string) ($response['message'] ?? $response['error'] ?? ''));

        return '' !== $message ? $message : $fallback;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function findPaymentUrl(array $result): ?string
    {
        foreach (['payment_url', 'paymentUrl', 'invoice_url', 'invoiceUrl', 'checkout_url', 'checkoutUrl', 'url'] as $key) {
            $url = $this->stringOrNull($result[$key] ?? null);
            if (null !== $url) {
                return $url;
            }
        }

        $links = $result['paymentLinks'] ?? $result['links'] ?? [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }

                $url = $this->stringOrNull($link['url'] ?? null);
                if (null !== $url) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function augmentDirectResponse(array $result, array $response): array
    {
        $wallet = is_array($result['wallet'] ?? null) ? $result['wallet'] : [];
        $amount = is_array($result['amount']['amount'] ?? null) ? $result['amount']['amount'] : (is_array($result['amount'] ?? null) ? $result['amount'] : []);

        return array_merge($response, [
            'cryptoAddress' => $this->stringOrNull($result['walletAddress'] ?? $wallet['address'] ?? null),
            'cryptoAmount' => $this->stringOrNull($amount['number'] ?? null),
            'cryptoCurrency' => $this->stringOrNull($amount['unit'] ?? $wallet['token'] ?? null),
            'network' => $this->stringOrNull($wallet['network'] ?? null),
            'paymentStatus' => $this->stringOrNull($result['status'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findPaymentIdentifier(Payment $payment, array $payload): ?string
    {
        foreach (['id', 'invoiceId', 'invoice_id', 'payment_id', 'paymentId', 'authority', 'transaction_id', 'transactionId'] as $key) {
            $value = $this->stringOrNull($payload[$key] ?? null);
            if (null !== $value) {
                return $value;
            }
        }

        return $payment->getGatewayTransactionId() ?? $payment->getAuthority();
    }

    private function normalizedStatus(?string $status): ?string
    {
        if (null === $status) {
            return null;
        }

        return strtolower(str_replace(['-', ' '], '_', trim($status)));
    }

    private function isPaidStatus(?string $status): bool
    {
        return in_array($status, ['paid', 'completed', 'complete', 'confirmed', 'success', 'succeed', 'succeeded', 'finished'], true);
    }

    private function statusMessage(?string $status): string
    {
        if (null === $status) {
            return 'SwapWallet payment status is unknown.';
        }

        if (in_array($status, ['active', 'pending', 'waiting', 'confirming', 'processing', 'created'], true)) {
            return 'SwapWallet payment is pending.';
        }

        if (in_array($status, ['failed', 'expired', 'cancelled', 'canceled', 'canceled', 'rejected'], true)) {
            return 'SwapWallet payment is not paid.';
        }

        if (in_array($status, ['partial', 'partially_paid', 'underpaid', 'wrong_amount'], true)) {
            return 'SwapWallet payment is partial or underpaid.';
        }

        return 'SwapWallet payment status is not final: '.$status;
    }

    private function isValidWebhookSignature(Request $request, string $webhookSecret): bool
    {
        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        foreach (['X-SwapWallet-Signature', 'X-Signature', 'Signature'] as $header) {
            $signature = $request->headers->get($header);
            if (null !== $signature && hash_equals($expected, $this->normalizeSignature($signature))) {
                return true;
            }
        }

        $payloadSignature = $request->request->get('signature') ?? $request->query->get('signature');
        if (is_scalar($payloadSignature) && hash_equals($expected, $this->normalizeSignature((string) $payloadSignature))) {
            return true;
        }

        return false;
    }

    private function normalizeSignature(string $signature): string
    {
        $signature = trim($signature);
        if (str_starts_with($signature, 'sha256=')) {
            return substr($signature, 7);
        }

        return $signature;
    }

    private function normalizeNetwork(string $network): string
    {
        $network = strtoupper($network);

        return match ($network) {
            'TRC20', 'TRON' => 'TRON',
            'BEP20', 'BSC' => 'BSC',
            default => $network,
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function filterPayload(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return '' === $formatted ? '0' : $formatted;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $conversion
     *
     * @return array<string, mixed>
     */
    private function withConversion(array $response, array $conversion): array
    {
        $sanitized = $this->sanitize($response);
        $sanitized['conversion'] = $conversion;

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitize(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $sensitiveKeys = ['api_key', 'api_secret', 'webhook_secret', 'authorization', 'signature', 'token', 'password', 'secret'];
        $sanitized = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower(is_string($key) ? $key : (string) $key);
            if (in_array($keyText, $sensitiveKeys, true)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $sanitized[$key] = is_array($item) ? $this->sanitize($item) : $item;
        }

        return $sanitized;
    }
}
