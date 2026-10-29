<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Winapay;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Winapay (Zarinpal-style) Toman gateway.
 *
 *  - Amoobot and Winapay both count in Toman: the payable amount is sent as is.
 *  - createPayment() stores Winapay's Authority as transactionId and authority; verifyPayment()
 *    only ever verifies that stored Authority with our own expected amount.
 *  - The browser callback (Authority + PaymentStatus) is unsigned and never decides "paid":
 *    only PaymentVerification status 100 with a RefID and a numeric Amount does.
 */
final class WinapayGatewayPlugin implements PaymentGatewayPluginInterface
{
    public const CODE = 'winapay';
    public const MIN_AMOUNT_TOMAN = 100;

    private const CALLBACK_PATH = '/payment/callback/plugin/'.self::CODE;
    private const DESCRIPTION_MAX_LENGTH = 255;
    private const REDACTED = '[redacted]';

    private readonly WinapayApi $api;

    /**
     * Amoobot instantiates plugins without arguments; the client is injectable for tests.
     */
    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->api = new WinapayApi($httpClient);
    }

    public function getType(): string
    {
        return self::CODE;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        $merchantId = $this->merchantId($config);
        if ('' === $merchantId) {
            return new PaymentRequestResult(success: false, message: 'Winapay merchant_id is not configured.');
        }
        $callbackBaseUrl = $this->callbackBaseUrl($config);
        if (null === $callbackBaseUrl) {
            return new PaymentRequestResult(success: false, message: 'Winapay callback_base_url must be an absolute http(s) URL.');
        }

        $amount = $this->expectedAmount($payment);
        if ($amount < self::MIN_AMOUNT_TOMAN) {
            return new PaymentRequestResult(success: false, message: sprintf('Winapay amount must be at least %d Toman.', self::MIN_AMOUNT_TOMAN));
        }

        $params = [
            'MerchantID' => $merchantId,
            'Amount' => $amount,
            // ?rt= sends the customer on to the core return page once the callback was processed.
            'CallbackURL' => PaymentReturnUrl::withToken($callbackBaseUrl.self::CALLBACK_PATH, $payment),
            'Description' => $this->description($payment, $order, $config),
        ];
        $invoiceId = $this->invoiceId($payment, $order);
        if (null !== $invoiceId) {
            $params['InvoiceID'] = $invoiceId;
        }
        $mobile = $this->mobile($order);
        if (null !== $mobile) {
            $params['Mobile'] = $mobile;
        }

        try {
            $response = $this->api->post(WinapayApi::REQUEST_PATH, $params, $this->requestFormat($config));
        } catch (WinapayException $exception) {
            return new PaymentRequestResult(
                success: false,
                message: $this->mask($exception->getMessage(), $merchantId),
                rawResponse: ['error' => $this->mask($exception->getMessage(), $merchantId)],
            );
        }

        $rawResponse = $this->sanitize($response, $merchantId);
        $status = WinapayStatus::parse($this->field($response, 'Status'));
        if (WinapayStatus::OK !== $status) {
            return new PaymentRequestResult(
                success: false,
                message: null === $status ? 'Winapay response has no status.' : WinapayStatus::message($status),
                rawResponse: $rawResponse,
            );
        }

        $authority = $this->scalar($this->field($response, 'Authority'));
        if (null === $authority || 1 !== preg_match('/^[A-Za-z0-9_-]{8,64}$/', $authority)) {
            return new PaymentRequestResult(
                success: false,
                message: 'Winapay response has no valid Authority.',
                rawResponse: $rawResponse,
            );
        }

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $this->paymentUrl($this->scalar($this->field($response, 'PaymentUrl')), $authority),
            transactionId: $authority,
            authority: $authority,
            message: WinapayStatus::message(WinapayStatus::OK),
            rawResponse: $rawResponse,
        );
    }

    /**
     * @param array<string, mixed> $payload callback data; only PaymentStatus=NOK is honoured (to skip verification)
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $merchantId = $this->merchantId($config);
        if ('' === $merchantId) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'Winapay merchant_id is not configured.');
        }

        // The callback is unsigned: NOK only means "do not bother asking Winapay now"; the payment
        // stays pending (never failed) and can still be verified later.
        if ('NOK' === strtoupper(trim((string) $this->scalar($payload['PaymentStatus'] ?? null)))) {
            return new PaymentVerificationResult(
                success: true,
                paid: false,
                message: 'Winapay: the customer cancelled or the payment failed (callback NOK); not verified.',
            );
        }

        // Only the Authority stored by createPayment(), never one from the request.
        $authority = $payment->getAuthority() ?? $payment->getGatewayTransactionId();
        if (null === $authority || '' === trim($authority)) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'Winapay Authority is not stored for this payment.');
        }

        try {
            $response = $this->api->post(WinapayApi::VERIFY_PATH, [
                'MerchantID' => $merchantId,
                'Authority' => $authority,
                'Amount' => $this->expectedAmount($payment),
            ], $this->requestFormat($config));
        } catch (WinapayException $exception) {
            return new PaymentVerificationResult(
                success: false,
                paid: false,
                transactionId: $authority,
                message: $this->mask($exception->getMessage(), $merchantId),
                rawResponse: ['error' => $this->mask($exception->getMessage(), $merchantId)],
            );
        }

        $rawResponse = $this->sanitize($response, $merchantId);
        $status = WinapayStatus::parse($this->field($response, 'Status'));
        $refId = $this->scalar($this->field($response, 'RefID'));

        if (WinapayStatus::OK === $status) {
            $verified = $this->verifiedAmount($this->field($response, 'Amount'));
            if (null === $refId || null === $verified) {
                // Winapay says paid but the proof is incomplete: report no amount, so the core
                // sends the payment to manual review instead of trusting our own record.
                return new PaymentVerificationResult(
                    success: true,
                    paid: true,
                    transactionId: $authority,
                    refId: $refId,
                    message: 'Winapay confirmed the payment without a valid RefID/Amount; manual review required.',
                    rawResponse: $rawResponse,
                );
            }

            return new PaymentVerificationResult(
                success: true,
                paid: true,
                transactionId: $authority,
                refId: $refId,
                message: sprintf('Winapay verified the payment (RefID %s).', $refId),
                rawResponse: $rawResponse,
                amount: $verified['amount'],
                currency: $verified['currency'],
            );
        }

        if (WinapayStatus::ALREADY_VERIFIED === $status) {
            // Verified earlier (e.g. a second verify after a crash). Not a fresh confirmation: no amount
            // is reported, so the core puts it into manual review if it is not settled yet.
            return new PaymentVerificationResult(
                success: true,
                paid: true,
                transactionId: $authority,
                refId: $refId,
                message: WinapayStatus::message(WinapayStatus::ALREADY_VERIFIED).' Manual review required.',
                rawResponse: $rawResponse,
            );
        }

        if (null !== $status && WinapayStatus::isNotPaid($status)) {
            return new PaymentVerificationResult(
                success: true,
                paid: false,
                transactionId: $authority,
                message: WinapayStatus::message($status),
                rawResponse: $rawResponse,
            );
        }

        // Configuration, provider or transient error: nothing is decided, verification may be retried.
        return new PaymentVerificationResult(
            success: false,
            paid: false,
            transactionId: $authority,
            message: null === $status ? 'Winapay response has no status.' : WinapayStatus::message($status),
            rawResponse: $rawResponse,
        );
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    /**
     * Winapay only sends the unsigned browser callback, handled by /payment/callback/plugin/winapay.
     *
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
    private function merchantId(array $config): string
    {
        return trim((string) $this->scalar($config['merchant_id'] ?? null));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function callbackBaseUrl(array $config): ?string
    {
        $url = rtrim(trim((string) $this->scalar($config['callback_base_url'] ?? null)), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || '' === ($parts['host'] ?? '')) {
            return null;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requestFormat(array $config): string
    {
        return WinapayApi::FORMAT_JSON === $this->scalar($config['request_format'] ?? null) ? WinapayApi::FORMAT_JSON : WinapayApi::FORMAT_FORM;
    }

    /**
     * Toman, the same unit Winapay uses.
     */
    private function expectedAmount(Payment $payment): int
    {
        return max(0, $payment->getPayableAmount() ?? $payment->getAmount());
    }

    /**
     * @return array{amount: string, currency: string}|null
     */
    private function verifiedAmount(mixed $amount): ?array
    {
        if (is_int($amount) || (is_float($amount) && is_finite($amount))) {
            $amount = (string) $amount;
        }
        if (!is_string($amount) || 1 !== preg_match('/^\d+(\.\d+)?$/', trim($amount))) {
            return null;
        }

        return AmountNormalizer::normalize(trim($amount), AmountNormalizer::SHOP_CURRENCY);
    }

    private function paymentUrl(?string $providerUrl, string $authority): string
    {
        if (null !== $providerUrl) {
            $parts = parse_url($providerUrl);
            if (is_array($parts) && 'https' === strtolower($parts['scheme'] ?? '') && in_array(strtolower($parts['host'] ?? ''), WinapayApi::HOSTS, true)
                && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])) {
                return $providerUrl;
            }
        }

        return WinapayApi::START_PAY_URL.rawurlencode($authority);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function description(Payment $payment, Order $order, array $config): string
    {
        $prefix = trim((string) $this->scalar($config['description_prefix'] ?? null));
        $text = trim(('' !== $prefix ? $prefix : 'Order').' '.($this->invoiceId($payment, $order) ?? ''));
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH);
    }

    private function invoiceId(Payment $payment, Order $order): ?string
    {
        $trackingCode = trim((string) ($payment->getTrackingCode() ?? $order->getTrackingCode()));
        if ('' !== $trackingCode) {
            return $trackingCode;
        }

        return null !== $payment->getId() ? 'payment_'.$payment->getId() : null;
    }

    /**
     * The customer's mobile as 09xxxxxxxxx, or null when it is missing or not an Iranian mobile.
     */
    private function mobile(Order $order): ?string
    {
        $mobile = (string) preg_replace('/[\s()-]+/', '', (string) $order->getUser()->getMobile());
        if (1 !== preg_match('/^(?:\+98|0098|98|0)?(9\d{9})$/', $mobile, $matches)) {
            return null;
        }

        return '0'.$matches[1];
    }

    /**
     * Case-insensitive top-level field (Winapay samples mix "Status" and "status").
     *
     * @param array<mixed> $response decoded JSON object (keys may be numeric)
     */
    private function field(array $response, string $name): mixed
    {
        foreach ($response as $key => $value) {
            if (is_string($key) && 0 === strcasecmp($key, $name)) {
                return $value;
            }
        }

        return null;
    }

    private function scalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private function mask(string $text, string $merchantId): string
    {
        return '' === $merchantId ? $text : str_replace($merchantId, self::REDACTED, $text);
    }

    /**
     * Provider response as stored on the payment: merchant/secret keys removed, MerchantID masked anywhere.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function sanitize(array $value, string $merchantId): array
    {
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && 1 === preg_match('/merchant|password|secret|token|api_?key|authorization/i', $key)) {
                $sanitized[$key] = self::REDACTED;
            } elseif (is_array($item)) {
                $sanitized[$key] = $this->sanitize($item, $merchantId);
            } else {
                $sanitized[$key] = is_string($item) ? $this->mask($item, $merchantId) : $item;
            }
        }

        return $sanitized;
    }
}
