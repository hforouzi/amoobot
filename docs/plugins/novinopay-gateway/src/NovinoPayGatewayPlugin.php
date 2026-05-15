<?php

declare(strict_types=1);

namespace Amoobot\Plugin\NovinoPay;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class NovinoPayGatewayPlugin implements PaymentGatewayPluginInterface
{
    private const REQUEST_PATH = '/payment/ipg/v2/request';
    private const VERIFY_PATH = '/payment/ipg/v2/verification';

    public function getType(): string
    {
        return 'novinopay';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        $configError = $this->validateConfig($config);
        if (null !== $configError) {
            return new PaymentRequestResult(success: false, message: $configError);
        }

        $amount = $this->paymentAmount($payment, $order);
        if ($amount < 10000) {
            return new PaymentRequestResult(success: false, message: 'NovinoPay amount must be at least 10000 IRR.');
        }

        $callbackUrl = $this->buildCallbackUrl($payment, $order, $config);
        $invoiceId = $this->invoiceId($payment, $order);
        $payload = [
            'merchant_id' => $this->merchantId($config),
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'callback_method' => 'GET',
            'invoice_id' => $invoiceId,
            'description' => trim((string) ($config['description'] ?? '')) ?: 'Amoobot order payment',
        ];

        try {
            $response = $this->postJson($this->endpoint($config, self::REQUEST_PATH), $payload);
        } catch (\Throwable $exception) {
            return new PaymentRequestResult(
                success: false,
                message: 'NovinoPay request failed.',
                rawResponse: ['error' => $exception->getMessage()],
            );
        }

        $data = $this->responseData($response);
        $status = $this->stringOrNull($response['status'] ?? null);
        if ('100' !== $status) {
            return new PaymentRequestResult(
                success: false,
                message: $this->responseMessage($response, 'NovinoPay did not create the payment session.'),
                rawResponse: $this->sanitize($response),
            );
        }

        $authority = $this->stringOrNull($data['authority'] ?? null);
        $transactionId = $this->stringOrNull($data['trans_id'] ?? null);
        $paymentUrl = $this->stringOrNull($data['payment_url'] ?? null);
        if (null === $authority || null === $paymentUrl) {
            return new PaymentRequestResult(
                success: false,
                message: 'NovinoPay response did not include payment authority or URL.',
                rawResponse: $this->sanitize($response),
            );
        }

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $paymentUrl,
            transactionId: $transactionId,
            authority: $authority,
            message: $this->responseMessage($response, 'NovinoPay payment created.'),
            rawResponse: $this->sanitize($response),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $configError = $this->validateConfig($config);
        if (null !== $configError) {
            return new PaymentVerificationResult(success: false, paid: false, message: $configError);
        }

        $callbackStatus = strtoupper(trim((string) ($payload['PaymentStatus'] ?? $payload['payment_status'] ?? '')));
        if ('NOK' === $callbackStatus) {
            return new PaymentVerificationResult(
                success: true,
                paid: false,
                message: 'NovinoPay callback reported an unsuccessful payment.',
                rawResponse: $this->sanitize($payload),
            );
        }

        $authority = $this->findAuthority($payment, $payload);
        if (null === $authority) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                message: 'NovinoPay authority was not found.',
                rawResponse: $this->sanitize($payload),
            );
        }

        $requestPayload = [
            'merchant_id' => $this->merchantId($config),
            'amount' => $this->paymentAmount($payment, $payment->getOrder()),
            'authority' => $authority,
        ];

        try {
            $response = $this->postJson($this->endpoint($config, self::VERIFY_PATH), $requestPayload);
        } catch (\Throwable $exception) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                message: 'NovinoPay verification failed.',
                rawResponse: ['error' => $exception->getMessage()],
            );
        }

        $data = $this->responseData($response);
        $status = $this->stringOrNull($response['status'] ?? null);
        $paid = in_array($status, ['100', '101'], true);
        if (!$paid) {
            return new PaymentVerificationResult(
                success: true,
                paid: false,
                transactionId: $this->stringOrNull($data['trans_id'] ?? null) ?? $payment->getGatewayTransactionId(),
                message: $this->responseMessage($response, 'NovinoPay payment is not verified.'),
                rawResponse: $this->sanitize($response),
            );
        }

        return new PaymentVerificationResult(
            success: true,
            paid: true,
            transactionId: $this->stringOrNull($data['trans_id'] ?? null) ?? $payment->getGatewayTransactionId(),
            refId: $this->stringOrNull($data['ref_id'] ?? null),
            message: $this->responseMessage($response, 'NovinoPay payment verified.'),
            rawResponse: $this->sanitize($response),
        );
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateConfig(array $config): ?string
    {
        if ('' === $this->merchantId($config)) {
            return 'NovinoPay merchant_id or api_key is not configured.';
        }

        if ('' === trim((string) ($config['api_base_url'] ?? ''))) {
            return 'NovinoPay api_base_url is not configured.';
        }

        if ('' === trim((string) ($config['callback_base_url'] ?? ''))) {
            return 'NovinoPay callback_base_url is not configured.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function merchantId(array $config): string
    {
        $merchantId = trim((string) ($config['merchant_id'] ?? ''));
        if ('' !== $merchantId) {
            return $merchantId;
        }

        return trim((string) ($config['api_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function endpoint(array $config, string $path): string
    {
        $baseUrl = rtrim(trim((string) ($config['api_base_url'] ?? '')), '/');
        if ('' === $baseUrl) {
            $baseUrl = 'https://api.novinopay.com';
        }

        return $baseUrl.$path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildCallbackUrl(Payment $payment, Order $order, array $config): string
    {
        $baseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');
        $query = http_build_query(array_filter([
            'payment_id' => $payment->getId(),
            'order_id' => $order->getId(),
            'tracking_code' => $payment->getTrackingCode() ?? $order->getTrackingCode(),
        ], static fn (mixed $value): bool => null !== $value && '' !== (string) $value));

        return $baseUrl.'/payment/callback/plugin/novinopay'.('' !== $query ? '?'.$query : '');
    }

    private function paymentAmount(Payment $payment, Order $order): int
    {
        return max(0, (int) ($payment->getPayableAmount() ?? $payment->getAmount() ?: $order->getAmount()));
    }

    private function invoiceId(Payment $payment, Order $order): string
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
     * @param array<string, mixed> $payload
     */
    private function findAuthority(Payment $payment, array $payload): ?string
    {
        foreach (['Authority', 'authority', 'transaction_id', 'transactionId'] as $key) {
            $value = $this->stringOrNull($payload[$key] ?? null);
            if (null !== $value) {
                return $value;
            }
        }

        return $payment->getAuthority() ?? $payment->getGatewayTransactionId();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        $client = HttpClient::create(['timeout' => 20]);
        $response = $client->request('POST', $url, [
            'json' => $payload,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        try {
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException($exception->getMessage(), previous: $exception);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('NovinoPay returned an invalid JSON response.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function responseData(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseMessage(array $response, string $fallback): string
    {
        $message = trim((string) ($response['message'] ?? ''));

        return '' !== $message ? $message : $fallback;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitize(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $sensitiveKeys = ['api_key', 'token', 'authorization', 'password'];
        $sanitized = [];
        foreach ($value as $key => $item) {
            $keyText = is_string($key) ? $key : (string) $key;
            if (in_array(strtolower($keyText), $sensitiveKeys, true)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $sanitized[$key] = is_array($item) ? $this->sanitize($item) : $item;
        }

        return $sanitized;
    }
}
