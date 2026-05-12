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

final class ZibalGateway implements PaymentGatewayInterface
{
    private const REQUEST_URL = 'https://gateway.zibal.ir/v1/request';
    private const VERIFY_URL = 'https://gateway.zibal.ir/v1/verify';
    private const START_URL = 'https://gateway.zibal.ir/start/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getType(): string
    {
        return PaymentGatewayType::ZIBAL;
    }

    public function createPayment(Payment $payment, Order $order): PaymentRequestResult
    {
        $gateway = $payment->getGateway();
        $config = $this->resolveConfig($gateway);
        $merchant = $this->resolveMerchant($config);
        $callbackUrl = $this->buildCallbackUrl($config);
        $amount = max(1, (int) ($payment->getPayableAmount() ?? $payment->getAmount()));

        if ('' === $merchant || '' === $callbackUrl) {
            return new PaymentRequestResult(
                success: false,
                message: 'Zibal gateway config is incomplete.',
            );
        }

        $requestBody = [
            'merchant' => $merchant,
            'amount' => $amount,
            'callbackUrl' => $callbackUrl,
            'orderId' => (string) ($order->getId() ?? $payment->getId() ?? ''),
            'description' => trim((string) ($config['description'] ?? sprintf('order_%d_payment_%d', $order->getId() ?? 0, $payment->getId() ?? 0))),
        ];
        $this->appendOptionalRequestFields($requestBody, $config);

        $payment->setRequestPayload($requestBody);

        try {
            $response = $this->httpClient->request('POST', self::REQUEST_URL, [
                'json' => $requestBody,
                'timeout' => 20,
            ]);
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('zibal_request_exception', ['message' => $e->getMessage(), 'paymentId' => $payment->getId()]);

            return new PaymentRequestResult(
                success: false,
                message: 'در ارتباط با درگاه مشکل رخ داد.'
            );
        }

        $resultCode = (int) ($raw['result'] ?? -1);
        $trackId = (string) ($raw['trackId'] ?? '');
        $message = (string) ($raw['message'] ?? '');

        if (100 !== $resultCode || '' === $trackId) {
            $this->safeLog('zibal_request_failed', ['paymentId' => $payment->getId(), 'raw' => $raw]);

            return new PaymentRequestResult(
                success: false,
                message: '' !== $message ? $message : 'ایجاد درخواست پرداخت ناموفق بود.',
                rawResponse: is_array($raw) ? $raw : null
            );
        }

        $paymentUrl = self::START_URL.$trackId;
        $payment
            ->setGatewayTransactionId($trackId)
            ->setAuthority($trackId)
            ->setTrackingCode($trackId)
            ->setPaymentUrl($paymentUrl);

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $paymentUrl,
            transactionId: $trackId,
            authority: $trackId,
            message: $message ?: 'success',
            rawResponse: is_array($raw) ? $raw : null
        );
    }

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult
    {
        $gateway = $payment->getGateway();
        $config = $this->resolveConfig($gateway);
        $merchant = $this->resolveMerchant($config);
        $trackId = (string) ($payload['trackId'] ?? $payload['track_id'] ?? $payload['authority'] ?? $payment->getGatewayTransactionId() ?? $payment->getAuthority() ?? '');

        if ('' === $merchant || '' === $trackId) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                message: 'شناسه پرداخت یا تنظیمات درگاه معتبر نیست.'
            );
        }

        $verifyBody = [
            'merchant' => $merchant,
            'trackId' => $trackId,
        ];

        $payment
            ->setCallbackPayload($payload)
            ->setVerifyPayload($verifyBody);

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'json' => $verifyBody,
                'timeout' => 20,
            ]);
            $raw = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->safeLog('zibal_verify_exception', ['message' => $e->getMessage(), 'paymentId' => $payment->getId()]);

            return new PaymentVerificationResult(
                success: false,
                paid: false,
                transactionId: $trackId,
                message: 'خطا در بررسی تراکنش.'
            );
        }

        $resultCode = (int) ($raw['result'] ?? -1);
        $message = (string) ($raw['message'] ?? '');
        $refId = null;
        if (isset($raw['refNumber'])) {
            $refId = (string) $raw['refNumber'];
        } elseif (isset($raw['refNumberWage'])) {
            $refId = (string) $raw['refNumberWage'];
        }
        $paid = in_array($resultCode, [100, 201], true);

        if ($paid) {
            $payment
                ->setGatewayTransactionId($trackId)
                ->setAuthority($payment->getAuthority() ?? $trackId)
                ->setTrackingCode($refId ?: $trackId)
                ->setVerifiedAt($payment->getVerifiedAt() ?? new \DateTimeImmutable())
                ->setFailedAt(null);
        } else {
            $payment->setFailedAt(new \DateTimeImmutable());
            $this->safeLog('zibal_verify_failed', ['paymentId' => $payment->getId(), 'raw' => $raw]);
        }

        return new PaymentVerificationResult(
            success: true,
            paid: $paid,
            transactionId: $trackId,
            refId: $refId,
            message: '' !== $message ? $message : ($paid ? 'paid' : 'not_paid'),
            rawResponse: is_array($raw) ? $raw : null
        );
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
     * @param array<string, mixed> $config
     */
    private function resolveMerchant(array $config): string
    {
        $sandbox = true === ($config['sandbox'] ?? false);
        $merchant = trim((string) ($config['merchant'] ?? ''));
        if ('' === $merchant && $sandbox) {
            $merchant = 'zibal';
        }

        return $merchant;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildCallbackUrl(array $config): string
    {
        $base = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');
        if ('' === $base) {
            return '';
        }

        return $base.'/payment/callback/zibal';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $event, array $context): void
    {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log(sprintf('[ZibalGateway] %s %s', $event, false === $encoded ? '{}' : $encoded));
    }

    /**
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed> $config
     */
    private function appendOptionalRequestFields(array &$requestBody, array $config): void
    {
        foreach (['mobile', 'allowedCards', 'percentMode', 'feeMode', 'multiplexingAccountNumber'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            if ($key === 'allowedCards' && is_string($value)) {
                $cards = explode(',', $value);
                $trimmedCards = array_map('trim', $cards);
                $value = array_values(array_filter($trimmedCards, static fn (string $card): bool => '' !== $card));
            }

            if ($value === null || $value === '' || (is_array($value) && [] === $value)) {
                continue;
            }

            $requestBody[$key] = $value;
        }
    }
}
