<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Tetraminator;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tetraminator Toman gateway (docs/plugins/tetraminator-gateway/design.md, PR 3).
 *
 *  - Amoobot and Tetraminator both count in Toman: the payable amount is sent as is.
 *  - One Payment row is one invoice: createPayment() hands a live invoice of this row out again without an API call.
 *  - verifyPayment() asks GET /payment/inquiry/{pay_id} with the stored pay_id only. A payment is paid with an amount
 *    only when the answer is strictly right (decision 1); a paid claim that is not reports no amount, so the core
 *    sends it to review, and the evidence goes into the payment's verify payload.
 *  - "failed" is never reported: an invoice stays payable after Amoobot gave up on it, and a rejection would lose
 *    that money.
 *  - The API key, the callback token and the callback URL never reach a message or a stored response.
 */
final class TetraminatorGatewayPlugin implements PaymentGatewayPluginInterface, PaymentGatewayConstraintsInterface
{
    public const CODE = 'tetraminator';
    public const MIN_AMOUNT_TOMAN = 50_000;
    public const MAX_AMOUNT_TOMAN = 10_000_000;
    public const INVOICE_LIFETIME_SECONDS = 3600;
    public const CURRENCY = 'IRT';
    /** The core's notify endpoint (PR 4): {callback_base_url}/payment/notify/tetraminator/{paymentId}/{token}. */
    public const NOTIFY_PATH = '/payment/notify/'.self::CODE.'/';
    public const PAY_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /** rawResponse.error codes the core reads (PaymentGatewayCredentialAlert): the admin must act. */
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_STORE_DISABLED = 'store_disabled';

    /** The verify payload key holding the evidence of a paid claim that failed a rule. */
    public const REVIEW_EVIDENCE_KEY = 'tetraminator_review';

    /**
     * What a failed createPayment() says: the bot and the Mini App show it to the customer, and PaymentStarter keeps it as
     * the admin note. Generic on purpose; the admin's detail is in the payment's request payload (rawResponse).
     */
    public const CUSTOMER_UNAVAILABLE = 'درگاه پرداخت در حال حاضر در دسترس نیست. لطفاً روش دیگری را انتخاب کنید.';
    public const CUSTOMER_AMOUNT_OUT_OF_RANGE = 'پرداخت با این درگاه فقط برای مبلغ ۵۰٬۰۰۰ تا ۱۰٬۰۰۰٬۰۰۰ تومان ممکن است. لطفاً روش دیگری را انتخاب کنید.';

    private const TOKEN_BYTES = 32;
    private const PAID = 'paid';
    private const REDACTED = '[redacted]';
    private const MAX_TEXT_LENGTH = 200;

    private readonly TetraminatorApi $api;

    /**
     * Amoobot instantiates plugins without arguments; the client is injectable for tests.
     */
    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->api = new TetraminatorApi($httpClient);
    }

    public function getType(): string
    {
        return self::CODE;
    }

    /**
     * The switch is on, the key is set and the callback base URL is https with a host and no user info. Missing, empty
     * or unknown values count as off. No network call.
     *
     * @param array<string, mixed> $config
     */
    public function isUsable(array $config): bool
    {
        return true === ($config['enabled'] ?? null) && '' !== $this->apiKey($config) && null !== $this->callbackBaseUrl($config);
    }

    public function acceptsAmount(int $toman): bool
    {
        return $toman >= self::MIN_AMOUNT_TOMAN && $toman <= self::MAX_AMOUNT_TOMAN;
    }

    public function invoiceLifetimeSeconds(): int
    {
        return self::INVOICE_LIFETIME_SECONDS;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        $amount = $this->payableAmount($payment);
        if (!$this->acceptsAmount($amount)) {
            return $this->createFailed(['error' => 'amount_out_of_range', 'amount' => $amount], self::CUSTOMER_AMOUNT_OUT_OF_RANGE);
        }
        if (!$this->isUsable($config)) {
            return $this->createFailed(['error' => 'not_usable', 'detail' => 'switched off or not configured (enabled, api_key, https callback_base_url)']);
        }
        $apiKey = $this->apiKey($config);
        $callbackBaseUrl = (string) $this->callbackBaseUrl($config);

        $live = $this->liveInvoice($payment, $amount);
        if (null !== $live) {
            return $live;
        }

        $paymentId = $payment->getId();
        if (null === $paymentId) {
            return $this->createFailed(['error' => 'payment_not_saved']);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        $callbackUrl = $callbackBaseUrl.self::NOTIFY_PATH.$paymentId.'/'.$token;
        $secrets = [$apiKey, $token, $callbackUrl];

        $params = ['price' => $amount, 'callback_url' => $callbackUrl];
        $chatId = $this->chatId($order, $config);
        if (null !== $chatId) {
            $params['chat_id'] = $chatId;
        }

        try {
            $response = $this->api->post(TetraminatorApi::CREATE_PATH, $apiKey, $params);
        } catch (TetraminatorException $exception) {
            return $this->createFailed(['error' => 'transport', 'detail' => $this->mask($exception->getMessage(), $secrets)]);
        }

        $httpStatus = $response['status'];
        $body = $response['body'];
        $failure = $this->credentialFailure($httpStatus);
        if (null !== $failure) {
            return $this->createFailed(['error' => $failure['error'], 'http_status' => $httpStatus, 'detail' => $failure['message']]);
        }
        if (!in_array($httpStatus, [200, 201], true)) {
            return $this->createFailed(['error' => 'http_'.$httpStatus, 'http_status' => $httpStatus, 'message' => $this->text($body['message'] ?? null, $secrets)]);
        }
        if (true !== ($body['status'] ?? null)) {
            return $this->createFailed(['error' => 'status_not_true', 'http_status' => $httpStatus, 'message' => $this->text($body['message'] ?? null, $secrets)]);
        }

        $payId = $this->payId($body['pay_id'] ?? null);
        $paymentUrl = $this->firstLink($body, ['url', 'payment_link', 'gateway_link']);
        if (null === $payId || null === $paymentUrl) {
            return $this->createFailed(['error' => null === $payId ? 'invalid_pay_id' : 'invalid_link', 'http_status' => $httpStatus]);
        }

        // Frozen at invoice time: AmountMatcher::expectationFor() and verifyPayment() compare with it.
        $payment
            ->setExpectation((string) $amount, self::CURRENCY)
            ->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d seconds', self::INVOICE_LIFETIME_SECONDS)));

        // An allowlist: anything else the provider echoes back (a callback URL, a chat id) is dropped.
        $stored = ['status' => true, 'message' => $this->text($body['message'] ?? null, $secrets), 'pay_id' => $payId];
        $miniAppLink = $this->link($body['mini_app_link'] ?? null);
        if (null !== $miniAppLink) {
            $stored['mini_app_link'] = $miniAppLink;
        }

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $paymentUrl,
            transactionId: $payId,
            // Only the hash: the notify endpoint compares sha256(token) with it (hash_equals).
            authority: hash('sha256', $token),
            message: 'Tetraminator invoice created.',
            rawResponse: $stored,
        );
    }

    /**
     * @param array<string, mixed> $payload ignored: the request proves nothing, only the inquiry does
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        // Not gated by "enabled": an invoice issued before the switch went off can still be paid (decision 8).
        $apiKey = $this->apiKey($config);
        if ('' === $apiKey) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'Tetraminator api_key is not configured.');
        }

        // Only the pay_id stored by createPayment(), never one from the request.
        $payId = $this->payId($payment->getGatewayTransactionId());
        if (null === $payId) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'Tetraminator pay_id is not stored for this payment.');
        }
        $secrets = [$apiKey];

        try {
            $response = $this->api->get(TetraminatorApi::INQUIRY_PATH.rawurlencode($payId), $apiKey);
        } catch (TetraminatorException $exception) {
            $message = $this->mask($exception->getMessage(), $secrets);

            return new PaymentVerificationResult(success: false, paid: false, transactionId: $payId, message: $message, rawResponse: ['error' => $message]);
        }

        $httpStatus = $response['status'];
        $body = $response['body'];
        $failure = $this->credentialFailure($httpStatus);
        if (null !== $failure) {
            return new PaymentVerificationResult(success: false, paid: false, transactionId: $payId, message: $failure['message'], rawResponse: ['error' => $failure['error'], 'http_status' => $httpStatus]);
        }
        $rawResponse = $this->sanitize($body, $secrets);
        if (404 === $httpStatus) {
            return new PaymentVerificationResult(success: true, paid: false, transactionId: $payId, message: 'Tetraminator does not know this invoice as paid (HTTP 404).', rawResponse: $rawResponse);
        }
        if (200 !== $httpStatus) {
            return new PaymentVerificationResult(success: false, paid: false, transactionId: $payId, message: sprintf('Tetraminator inquiry failed (HTTP %d).', $httpStatus), rawResponse: $rawResponse);
        }

        $status = $body['status'] ?? null;
        $paymentStatus = $body['payment_status'] ?? null;
        $claimsPaid = is_string($paymentStatus) && 0 === strcasecmp(trim($paymentStatus), self::PAID);

        if ($claimsPaid) {
            return $this->paidClaim($payment, $payId, $body, $rawResponse);
        }
        if (true === $status) {
            return new PaymentVerificationResult(
                success: true,
                paid: false,
                transactionId: $payId,
                message: sprintf('Tetraminator: payment_status %s; not paid.', $this->statusValue($paymentStatus)),
                rawResponse: $rawResponse,
            );
        }
        if (false === $status) {
            return new PaymentVerificationResult(success: true, paid: false, transactionId: $payId, message: 'Tetraminator: status false; not paid.', rawResponse: $rawResponse);
        }

        return new PaymentVerificationResult(success: false, paid: false, transactionId: $payId, message: 'Tetraminator inquiry has no usable status.', rawResponse: $rawResponse);
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    /**
     * The provider's GET is unsigned and carries nothing: the core's own notify endpoint (PR 4) handles it.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        return null;
    }

    /**
     * The inquiry says "paid" (in any case). Paid with an amount only when every rule of decision 1 holds; otherwise
     * paid with no amount (AmountMatcher: missing, never confirmed) and the reason in the verify payload, because the
     * review reason will only read "missing".
     *
     * @param array<mixed> $body
     * @param array<mixed> $rawResponse
     */
    private function paidClaim(Payment $payment, string $payId, array $body, array $rawResponse): PaymentVerificationResult
    {
        $expected = $this->expectedAmount($payment);
        $reportedPayId = $this->scalarString($body['pay_id'] ?? null);
        $amount = $body['amount'] ?? null;

        $reasons = [];
        if (true !== ($body['status'] ?? null)) {
            $reasons[] = 'status_not_true';
        }
        if (self::PAID !== ($body['payment_status'] ?? null)) {
            $reasons[] = 'payment_status_not_exact';
        }
        if (null === $reportedPayId) {
            $reasons[] = 'pay_id_missing';
        } elseif ($reportedPayId !== $payId) {
            $reasons[] = 'pay_id_mismatch';
        }
        $amountText = is_int($amount) ? (string) $amount : (is_string($amount) ? $amount : null);
        if (null === $amount) {
            $reasons[] = 'amount_missing';
        } elseif (null === $amountText || 1 !== preg_match('/^\d+$/', $amountText)) {
            $reasons[] = 'amount_not_integer';
        } elseif (null === $expected || $amountText !== $expected) {
            $reasons[] = 'amount_mismatch';
        }

        if ([] === $reasons && null !== $expected) {
            return new PaymentVerificationResult(
                success: true,
                paid: true,
                transactionId: $payId,
                refId: $payId,
                message: 'Tetraminator confirmed the payment.',
                rawResponse: $rawResponse,
                amount: $expected,
                currency: self::CURRENCY,
            );
        }

        $payment->setVerifyPayload([self::REVIEW_EVIDENCE_KEY => [
            'reason' => $reasons[0],
            'reasons' => $reasons,
            'reported_amount' => $this->reportedAmount($amount),
            'expected_amount' => $expected,
            'pay_id_match' => $reportedPayId === $payId ? 'yes' : 'no',
        ]]);

        return new PaymentVerificationResult(
            success: true,
            paid: true,
            transactionId: $payId,
            refId: $payId,
            message: sprintf('Tetraminator says paid, but the answer fails a rule (%s); manual review required.', implode(', ', $reasons)),
            rawResponse: $rawResponse,
        );
    }

    /**
     * The row already holds this gateway's invoice, unexpired, for this amount: the customer gets its link again and no
     * second invoice is created (one row, one invoice: design decision 3).
     */
    private function liveInvoice(Payment $payment, int $amount): ?PaymentRequestResult
    {
        $payId = $this->payId($payment->getGatewayTransactionId());
        $expiresAt = $payment->getExpiresAt();
        $paymentUrl = $this->link($payment->getPaymentUrl());
        if (null === $payId || null === $paymentUrl || null === $expiresAt || $expiresAt <= new \DateTimeImmutable()
            || self::CODE !== $payment->getGateway()?->getPluginCode()
            || (string) $amount !== $payment->getExpectedAmount() || self::CURRENCY !== $payment->getExpectedCurrency()) {
            return null;
        }

        return new PaymentRequestResult(success: true, paymentUrl: $paymentUrl, transactionId: $payId, message: 'Tetraminator invoice is still open.');
    }

    /**
     * A failed create: the customer's generic text as the message, the admin's detail only in rawResponse (stored as the
     * payment's request payload; rawResponse.error is what PaymentGatewayCredentialAlert reads).
     *
     * @param array<string, mixed> $rawResponse
     */
    private function createFailed(array $rawResponse, string $message = self::CUSTOMER_UNAVAILABLE): PaymentRequestResult
    {
        return new PaymentRequestResult(success: false, message: $message, rawResponse: $rawResponse);
    }

    /**
     * @return array{error: string, message: string}|null
     */
    private function credentialFailure(int $httpStatus): ?array
    {
        return match ($httpStatus) {
            401 => ['error' => self::ERROR_UNAUTHORIZED, 'message' => 'Tetraminator rejected the API key (HTTP 401).'],
            403 => ['error' => self::ERROR_STORE_DISABLED, 'message' => 'Tetraminator says the store is disabled (HTTP 403).'],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function apiKey(array $config): string
    {
        $key = $config['api_key'] ?? null;

        return is_string($key) ? trim($key) : '';
    }

    /**
     * https, a host, no user info, no query or fragment (the notify path is appended to it). Without a trailing slash.
     *
     * @param array<string, mixed> $config
     */
    private function callbackBaseUrl(array $config): ?string
    {
        $url = $config['callback_base_url'] ?? null;
        if (!is_string($url)) {
            return null;
        }
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $url;
    }

    /**
     * The customer's Telegram id, only when send_chat_id is on and the id exists (decision 7).
     *
     * @param array<string, mixed> $config
     */
    private function chatId(Order $order, array $config): int|string|null
    {
        if (true !== ($config['send_chat_id'] ?? null)) {
            return null;
        }
        $telegramId = trim((string) $order->getUser()->getTelegramAccount()?->getTelegramId());
        if ('' === $telegramId) {
            return null;
        }

        return 1 === preg_match('/^[1-9]\d{0,17}$/', $telegramId) ? (int) $telegramId : $telegramId;
    }

    /** Toman, the unit Tetraminator uses. */
    private function payableAmount(Payment $payment): int
    {
        return $payment->getPayableAmount() ?? $payment->getAmount();
    }

    /** The amount frozen at invoice time, else the payable amount; digits only, or null. */
    private function expectedAmount(Payment $payment): ?string
    {
        $expected = self::CURRENCY === $payment->getExpectedCurrency() ? $payment->getExpectedAmount() : null;
        $expected ??= (string) $this->payableAmount($payment);

        return 1 === preg_match('/^\d+$/', $expected) ? $expected : null;
    }

    private function payId(mixed $value): ?string
    {
        $payId = $this->scalarString($value);

        return null !== $payId && 1 === preg_match(self::PAY_ID_PATTERN, $payId) ? $payId : null;
    }

    /** An int or a string as a string; anything else (bool, float, array, null) is no value. */
    private function scalarString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $keys in order of preference
     */
    private function firstLink(array $body, array $keys): ?string
    {
        foreach ($keys as $key) {
            $link = $this->link($body[$key] ?? null);
            if (null !== $link) {
                return $link;
            }
        }

        return null;
    }

    /** An https URL with a host, no user info and no port; hosts are not pinned yet (open question 6). */
    private function link(mixed $value): ?string
    {
        if (!is_string($value) || '' === trim($value) || strlen($value) > 2048 || 1 === preg_match('/[\s\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        return $value;
    }

    /** payment_status for a message: a short plain word, else a placeholder (it may be anything the provider sends). */
    private function statusValue(mixed $value): string
    {
        if (is_string($value) && 1 === preg_match('/^[A-Za-z0-9_ -]{1,32}$/', $value)) {
            return '"'.$value.'"';
        }

        return null === $value ? 'missing' : '[unrecognised '.get_debug_type($value).']';
    }

    /** What the provider reported as the amount, as text: "50000.0" stays visible as a decimal. */
    private function reportedAmount(mixed $amount): ?string
    {
        if (null === $amount) {
            return null;
        }
        $text = is_string($amount) ? $amount : (string) json_encode($amount, JSON_PRESERVE_ZERO_FRACTION);

        return mb_substr($text, 0, 32);
    }

    /**
     * @param list<string> $secrets
     */
    private function text(mixed $value, array $secrets): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return mb_substr($this->mask(trim($value), $secrets), 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * @param list<string> $secrets
     */
    private function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ('' !== $secret) {
                $text = str_replace([$secret, rawurlencode($secret)], self::REDACTED, $text);
            }
        }

        return $text;
    }

    /**
     * Provider response as stored on the payment: secret-looking keys removed, the exact secrets masked anywhere.
     *
     * @param array<mixed> $value
     * @param list<string> $secrets
     *
     * @return array<mixed>
     */
    private function sanitize(array $value, array $secrets): array
    {
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && 1 === preg_match('/password|secret|token|api[_-]?key|authorization|callback/i', $key)) {
                $sanitized[$key] = self::REDACTED;
            } elseif (is_array($item)) {
                $sanitized[$key] = $this->sanitize($item, $secrets);
            } else {
                $sanitized[$key] = is_string($item) ? $this->mask($item, $secrets) : $item;
            }
        }

        return $sanitized;
    }
}
