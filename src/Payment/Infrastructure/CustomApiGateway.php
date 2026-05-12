<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CustomApiGateway implements PaymentGatewayInterface
{
    private const REQUEST_TIMEOUT = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ArrayPathReader $arrayPathReader,
    ) {
    }

    public function getType(): string
    {
        return PaymentGatewayType::CUSTOM_API;
    }

    public function createPayment(Payment $payment, Order $order): PaymentRequestResult
    {
        $config = $this->gatewayConfig($payment);
        $create = is_array($config['create'] ?? null) ? $config['create'] : [];
        $url = trim((string) ($create['url'] ?? ''));
        if ('' === $url) {
            return new PaymentRequestResult(success: false, message: 'Custom API create config is incomplete.');
        }

        $context = $this->templateContext($payment, $order);
        $method = $this->httpMethod($create['method'] ?? null);
        $renderedUrl = $this->renderTemplate($url, $context);
        $headers = $this->renderHeaders($create['headers'] ?? [], $context);
        $body = $this->renderBody($create['body'] ?? [], $context);

        try {
            $response = $this->sendRequest($method, $renderedUrl, $headers, $body);
        } catch (TransportExceptionInterface|\Throwable $e) {
            error_log(sprintf('[CustomApiGateway] create_exception payment_id=%d message="%s"', $payment->getId() ?? 0, $e->getMessage()));

            return new PaymentRequestResult(success: false, message: 'در ارتباط با درگاه مشکل رخ داد.');
        }

        $responseMapping = is_array($create['response_mapping'] ?? null) ? $create['response_mapping'] : [];
        $success = $this->isTruthy($this->pathValue($response['data'], $responseMapping['success'] ?? null));
        $paymentUrl = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['payment_url'] ?? null));
        $transactionId = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['transaction_id'] ?? null));
        $authority = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['authority'] ?? null));
        $message = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['message'] ?? null));

        $sanitizedPayload = [
            'request' => [
                'method' => $method,
                'url' => $renderedUrl,
                'headers' => $this->sanitize($headers),
                'body' => $this->sanitize($body),
            ],
            'response' => $this->sanitize($response['data']),
        ];
        $payment->setRequestPayload($sanitizedPayload);

        if (!$success) {
            return new PaymentRequestResult(
                success: false,
                message: $message ?? 'ایجاد درخواست پرداخت ناموفق بود.',
                rawResponse: $sanitizedPayload,
            );
        }

        if (null === $paymentUrl) {
            return new PaymentRequestResult(
                success: false,
                message: 'آدرس پرداخت در پاسخ درگاه یافت نشد.',
                rawResponse: $sanitizedPayload,
            );
        }

        $payment
            ->setPaymentUrl($paymentUrl)
            ->setGatewayTransactionId($transactionId)
            ->setAuthority($authority);

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $paymentUrl,
            transactionId: $transactionId,
            authority: $authority,
            message: $message ?? 'success',
            rawResponse: $sanitizedPayload,
        );
    }

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult
    {
        $config = $this->gatewayConfig($payment);
        $verify = is_array($config['verify'] ?? null) ? $config['verify'] : [];
        $url = trim((string) ($verify['url'] ?? ''));
        if ('' === $url) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'Custom API verify config is incomplete.');
        }

        $context = $this->templateContext($payment, $payment->getOrder(), $payload);
        $method = $this->httpMethod($verify['method'] ?? null);
        $renderedUrl = $this->renderTemplate($url, $context);
        $headers = $this->renderHeaders($verify['headers'] ?? [], $context);
        $body = $this->renderBody($verify['body'] ?? [], $context);

        $payment->setCallbackPayload($this->sanitize($payload));

        try {
            $response = $this->sendRequest($method, $renderedUrl, $headers, $body);
        } catch (TransportExceptionInterface|\Throwable $e) {
            error_log(sprintf('[CustomApiGateway] verify_exception payment_id=%d message="%s"', $payment->getId() ?? 0, $e->getMessage()));

            return new PaymentVerificationResult(success: false, paid: false, message: 'خطا در بررسی تراکنش.');
        }

        $responseMapping = is_array($verify['response_mapping'] ?? null) ? $verify['response_mapping'] : [];
        $isSuccess = $this->isTruthy($this->pathValue($response['data'], $responseMapping['success'] ?? null));
        $paid = $this->isTruthy(
            $this->pathValue($response['data'], $responseMapping['paid'] ?? null),
            $this->stringList($responseMapping['paid_values'] ?? []),
        );
        $refId = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['ref_id'] ?? null));
        $transactionId = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['transaction_id'] ?? null));
        $message = $this->stringOrNull($this->pathValue($response['data'], $responseMapping['message'] ?? null));

        $sanitizedPayload = [
            'request' => [
                'method' => $method,
                'url' => $renderedUrl,
                'headers' => $this->sanitize($headers),
                'body' => $this->sanitize($body),
            ],
            'response' => $this->sanitize($response['data']),
        ];
        $payment->setVerifyPayload($sanitizedPayload);

        if (!$isSuccess) {
            $payment->setFailedAt(new \DateTimeImmutable());

            return new PaymentVerificationResult(
                success: false,
                paid: false,
                transactionId: $transactionId,
                refId: $refId,
                message: $message ?? 'بررسی پرداخت ناموفق بود.',
                rawResponse: $sanitizedPayload,
            );
        }

        if ($paid) {
            $payment
                ->setGatewayTransactionId($transactionId ?? $payment->getGatewayTransactionId())
                ->setAuthority($payment->getAuthority() ?? $transactionId)
                ->setTrackingCode($refId ?: $transactionId)
                ->setVerifiedAt($payment->getVerifiedAt() ?? new \DateTimeImmutable())
                ->setFailedAt(null);
        } else {
            $payment->setFailedAt(new \DateTimeImmutable());
        }

        return new PaymentVerificationResult(
            success: true,
            paid: $paid,
            transactionId: $transactionId,
            refId: $refId,
            message: $message ?? ($paid ? 'paid' : 'not_paid'),
            rawResponse: $sanitizedPayload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayConfig(Payment $payment): array
    {
        $config = $payment->getGateway()?->getConfig();

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, scalar|null> $headers
     * @param array<string, mixed>        $body
     *
     * @return array{data: array<string, mixed>}
     */
    private function sendRequest(string $method, string $url, array $headers, array $body): array
    {
        $options = [
            'headers' => $headers,
            'timeout' => self::REQUEST_TIMEOUT,
        ];
        if ('GET' === $method) {
            $options['query'] = $body;
        } else {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, $url, $options);
        $content = trim($response->getContent(false));
        $decoded = '' !== $content ? json_decode($content, true) : [];
        if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
            return ['data' => $decoded];
        }

        return ['data' => ['raw' => $content]];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar|null>
     */
    private function renderHeaders(mixed $headers, array $context): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $result = [];
        foreach ($headers as $key => $value) {
            $name = trim((string) $key);
            if ('' === $name) {
                continue;
            }

            if (!is_scalar($value) && null !== $value) {
                continue;
            }
            if (null === $value) {
                continue;
            }
            $result[$name] = $this->renderTemplate((string) $value, $context);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function renderBody(mixed $body, array $context): array
    {
        if (!is_array($body)) {
            return [];
        }

        return $this->renderRecursive($body, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTemplate(string $value, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $matches) use ($context): string {
            $key = (string) ($matches[1] ?? '');
            $replacement = $context[$key] ?? '';
            if (is_bool($replacement)) {
                return $replacement ? 'true' : 'false';
            }
            if (!is_scalar($replacement)) {
                return '';
            }

            return (string) $replacement;
        }, $value);
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function renderRecursive(array $value, array $context): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $result[$key] = $this->renderTemplate($item, $context);
            } elseif (is_array($item)) {
                $result[$key] = $this->renderRecursive($item, $context);
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function pathValue(array $payload, mixed $path): mixed
    {
        if (!is_scalar($path)) {
            return null;
        }

        return $this->arrayPathReader->get($payload, (string) $path);
    }

    /**
     * @param array<string> $customTruthyValues
     */
    private function isTruthy(mixed $value, array $customTruthyValues = []): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return 1 === $value;
        }
        if (is_float($value)) {
            return 1.0 === $value;
        }

        $normalized = strtolower(trim((string) $value));
        if ('' === $normalized) {
            return false;
        }

        $truthyValues = array_values(array_unique(array_merge(['1', 'true', 'yes', 'success', 'paid', 'ok', 'confirmed'], array_map(
            static fn (string $item): string => strtolower(trim($item)),
            $customTruthyValues
        ))));

        return in_array($normalized, $truthyValues, true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        $sensitiveTokens = ['authorization', 'api_key', 'apikey', 'secret', 'token', 'password', 'signature', 'key'];
        $sanitizer = function (mixed $value, ?string $key = null) use (&$sanitizer, $sensitiveTokens): mixed {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $nestedKey => $nestedValue) {
                    $keyText = is_string($nestedKey) ? strtolower($nestedKey) : null;
                    $result[$nestedKey] = $sanitizer($nestedValue, $keyText);
                }

                return $result;
            }

            if (null !== $key) {
                foreach ($sensitiveTokens as $token) {
                    if (str_contains($key, $token)) {
                        return '***';
                    }
                }
            }

            return $value;
        };

        return $sanitizer($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringOrNull(mixed $payload): ?string
    {
        if (!is_scalar($payload)) {
            return null;
        }

        $value = trim((string) $payload);

        return '' === $value ? null : $value;
    }

    private function httpMethod(mixed $value): string
    {
        $method = strtoupper(trim((string) $value));

        return 'GET' === $method ? 'GET' : 'POST';
    }

    /**
     * @param array<string, mixed> $callbackPayload
     *
     * @return array<string, mixed>
     */
    private function templateContext(Payment $payment, Order $order, array $callbackPayload = []): array
    {
        $gateway = $payment->getGateway();
        $gatewayId = (int) ($gateway?->getId() ?? 0);
        $config = $this->gatewayConfig($payment);
        $variables = is_array($config['variables'] ?? null) ? $config['variables'] : [];
        $description = sprintf('order_%d_payment_%d', $order->getId() ?? 0, $payment->getId() ?? 0);
        $transactionId = $this->stringOrNull($callbackPayload['transaction_id'] ?? null)
            ?? $this->stringOrNull($callbackPayload['transactionId'] ?? null)
            ?? $payment->getGatewayTransactionId();
        $authority = $this->stringOrNull($callbackPayload['authority'] ?? null) ?? $payment->getAuthority();

        $context = [
            'amount' => (string) $payment->getAmount(),
            'payable_amount' => (string) ((int) ($payment->getPayableAmount() ?? $payment->getAmount())),
            'order_id' => (string) ($order->getId() ?? 0),
            'payment_id' => (string) ($payment->getId() ?? 0),
            'callback_url' => $gatewayId > 0 ? $this->urlGenerator->generate('payment_callback_custom_api', ['gatewayId' => $gatewayId], UrlGeneratorInterface::ABSOLUTE_URL) : '',
            'webhook_url' => $gatewayId > 0 ? $this->urlGenerator->generate('payment_webhook_custom_api', ['gatewayId' => $gatewayId], UrlGeneratorInterface::ABSOLUTE_URL) : '',
            'description' => $description,
            'transaction_id' => (string) ($transactionId ?? ''),
            'authority' => (string) ($authority ?? ''),
            'currency' => $payment->getCurrency(),
        ];

        foreach ($variables as $key => $value) {
            if (!is_string($key) || '' === trim($key)) {
                continue;
            }
            if (!is_scalar($value) && null !== $value) {
                continue;
            }
            $context[$key] = $value;
        }

        return $context;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = strtolower(trim((string) $item));
            if ('' !== $text) {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }
}
