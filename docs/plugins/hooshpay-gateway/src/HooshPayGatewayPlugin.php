<?php

declare(strict_types=1);

namespace Amoobot\Plugin\HooshPay;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\GatewayPayableAmount;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;
use App\Payment\Plugin\PaymentGatewayInvoiceCancellationInterface;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HooshPay card-to-card gateway (https://hooshpay.xyz/developers; docs/plugins/hooshpay-gateway/README.md).
 *
 *  - Amoobot and HooshPay both count in Toman: Payment::payableAmount is sent as is (hooshPayAmount()).
 *  - One Payment row is one invoice: createPayment() hands a live invoice of this row out again without an API call. A
 *    row the core superseded gets its invoice cancelled through cancelInvoice() (best effort).
 *  - The customer pays HooshPay's payable_amount (our amount plus a few Toman that make it unique, plus the buyer's
 *    share of the fee). It is shown to the customer (GatewayPayableAmount), never credited: verifyPayment() reports our
 *    own amount, and only after HooshPay's verify confirms paid, the invoice uid, our order_id and our amount.
 *  - The webhook (HooshPayWebhookController) is authenticated by handleWebhook(): HMAC-SHA256 of the ksort'ed payload
 *    with the Secret. Its body never decides anything.
 *  - The API key, the Secret and the create answer's card object (card number, holder name) never reach a message, a
 *    stored response or a log.
 */
final class HooshPayGatewayPlugin implements PaymentGatewayPluginInterface, PaymentGatewayConstraintsInterface, PaymentGatewayInvoiceCancellationInterface
{
    public const CODE = 'hooshpay';
    /** HooshPay's own minimum (docs: amount >= 1000 Toman); min_amount cannot go below it. */
    public const HARD_MIN_AMOUNT_TOMAN = 1000;
    public const CURRENCY = 'IRT';
    public const FEE_MODES = ['seller', 'buyer', 'split'];
    public const DEFAULT_FEE_MODE = 'seller';
    public const DEFAULT_DESCRIPTION = 'Order {order}';
    /** The lifetime the core assumes (PaymentStarter); createPayment() always sets the invoice's own expiry. */
    public const DEFAULT_LIFETIME_SECONDS = 1800;
    public const MIN_LIFETIME_SECONDS = 60;
    public const MAX_LIFETIME_SECONDS = 86400;
    /** The core's endpoint for HooshPay's webhook (HooshPayWebhookController), sent as callback_url on every invoice. */
    public const WEBHOOK_PATH = '/payment/webhook/'.self::CODE;
    public const SIGNATURE_HEADER = 'X-HooshPay-Signature';
    public const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';
    /** expires_at without an offset is read in this zone (HooshPay is an Iranian service; the docs give no zone). */
    public const PROVIDER_TIMEZONE = 'Asia/Tehran';

    /** rawResponse.error codes the core reads (PaymentGatewayCredentialAlert): the admin must act. */
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_STORE_DISABLED = 'store_disabled';
    /** rawResponse.error of a verification whose paid answer does not match the payment (HooshPayWebhookController logs it). */
    public const ERROR_VERIFY_MISMATCH = 'verify_mismatch';
    /** handleWebhook() refusals; "unauthorized" is answered 401 by the core, anything else 400. */
    public const WEBHOOK_UNAUTHORIZED = 'unauthorized';
    public const WEBHOOK_DISABLED = 'gateway_disabled';
    public const WEBHOOK_INVALID_PAYLOAD = 'invalid_payload';

    /** cancelInvoice() outcomes. */
    public const CANCELLED = 'cancelled';
    public const CANCEL_SKIPPED = 'skipped';

    /** The verify payload key holding the evidence of a paid answer that failed a rule. */
    public const REVIEW_EVIDENCE_KEY = 'hooshpay_mismatch';

    /**
     * What a failed createPayment() says: the bot and the Mini App show it to the customer, and PaymentStarter keeps it as
     * the admin note. Generic on purpose; the admin's detail is in the payment's request payload (rawResponse).
     */
    public const CUSTOMER_UNAVAILABLE = 'درگاه پرداخت موقتاً در دسترس نیست. لطفاً چند دقیقه دیگر دوباره تلاش کنید یا روش دیگری را انتخاب کنید.';
    public const CUSTOMER_FAILED = 'ایجاد لینک پرداخت انجام نشد. لطفاً روش دیگری را انتخاب کنید.';
    public const CUSTOMER_AMOUNT_OUT_OF_RANGE = 'پرداخت با این درگاه فقط برای مبلغ %s تا %s تومان ممکن است. لطفاً روش دیگری را انتخاب کنید.';

    private const PAID = 'paid';
    private const NOT_PAID_STATUSES = ['pending', 'expired', 'cancelled', 'failed'];
    private const REDACTED = '[redacted]';
    private const MAX_TEXT_LENGTH = 200;
    private const DESCRIPTION_MAX_LENGTH = 255;
    private const ORDER_ID_MAX_LENGTH = 64;
    private const TRACKING_CODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';
    /** Keys never stored from a provider answer: the card object (number, holder, bank) and anything secret-shaped. */
    private const DROPPED_KEYS = '/card|holder|bank|iban|sheba|password|secret|token|api[_-]?key|authorization|callback|return_url/i';

    private readonly HooshPayApi $api;

    /** The amount limits of the config last given to isUsable() (the resolver calls it right before acceptsAmount()). */
    private ?array $limits = null;

    /**
     * Amoobot instantiates plugins without arguments; the client is injectable for tests.
     */
    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->api = new HooshPayApi($httpClient);
    }

    public function getType(): string
    {
        return self::CODE;
    }

    /**
     * The switch is on and every setting is valid (configProblems()). Missing, empty or unknown values count as off.
     * No network call.
     *
     * @param array<string, mixed> $config
     */
    public function isUsable(array $config): bool
    {
        $problems = self::configProblems($config);
        $this->limits = [] === $problems ? self::amountLimits($config) : null;

        return true === ($config['enabled'] ?? null) && [] === $problems;
    }

    /**
     * Within the limits of the config isUsable() last saw on this instance (a fresh one per resolve), else within
     * HooshPay's own minimum. createPayment() checks the config's limits again.
     */
    public function acceptsAmount(int $toman): bool
    {
        [$min, $max] = $this->limits ?? [self::HARD_MIN_AMOUNT_TOMAN, null];

        return $toman >= $min && (null === $max || $toman <= $max);
    }

    public function invoiceLifetimeSeconds(): int
    {
        return self::DEFAULT_LIFETIME_SECONDS;
    }

    /**
     * What is wrong with the settings, as stable codes (empty: valid). "enabled" is not checked here.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function configProblems(array $config): array
    {
        $problems = [];
        if ('' === self::secret($config, 'api_key')) {
            $problems[] = 'api_key_missing';
        }
        if ('' === self::secret($config, 'api_secret')) {
            $problems[] = 'api_secret_missing';
        }
        if (null === self::baseUrl($config)) {
            $problems[] = 'base_url_not_https';
        }
        if (null === self::callbackBaseUrl($config)) {
            $problems[] = 'callback_base_url_not_https';
        }
        if (null === self::feeMode($config)) {
            $problems[] = 'fee_mode_invalid';
        }
        $min = self::intSetting($config, 'min_amount');
        $max = self::intSetting($config, 'max_amount');
        if (false === $min || (is_int($min) && $min < self::HARD_MIN_AMOUNT_TOMAN)) {
            $problems[] = 'min_amount_below_1000';
        }
        if (false === $max || (is_int($max) && $max < max(self::HARD_MIN_AMOUNT_TOMAN, is_int($min) ? $min : 0))) {
            $problems[] = 'max_amount_invalid';
        }
        $lifetime = self::intSetting($config, 'invoice_lifetime_seconds');
        if (false === $lifetime || (is_int($lifetime) && ($lifetime < self::MIN_LIFETIME_SECONDS || $lifetime > self::MAX_LIFETIME_SECONDS))) {
            $problems[] = 'invoice_lifetime_seconds_out_of_range';
        }

        return $problems;
    }

    /**
     * The amount HooshPay is asked for, in Toman. Amoobot's Payment::payableAmount (and amount) are Toman whatever the
     * gateway's display currency is (AmountNormalizer, docs/en/payments/amount-units.md), and HooshPay counts in Toman
     * too: no conversion. A Rial value would be ten times too much.
     */
    public static function hooshPayAmount(Payment $payment): int
    {
        return max(0, $payment->getPayableAmount() ?? $payment->getAmount());
    }

    /**
     * The webhook signature: lowercase hex HMAC-SHA256 of the payload with its top-level keys sorted, re-encoded with
     * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, keyed with the Secret (HooshPay's own PHP sample).
     *
     * @param array<mixed> $payload the decoded request body
     */
    public static function signature(array $payload, #[\SensitiveParameter] string $secret): string
    {
        ksort($payload);

        return hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        if (!$this->isUsable($config)) {
            return $this->createFailed(['error' => 'not_usable', 'problems' => self::configProblems($config)], self::CUSTOMER_FAILED);
        }
        $amount = self::hooshPayAmount($payment);
        [$min, $max] = self::amountLimits($config);
        if ($amount < $min || (null !== $max && $amount > $max)) {
            $message = sprintf(self::CUSTOMER_AMOUNT_OUT_OF_RANGE, number_format($min), null === $max ? '…' : number_format($max));

            return $this->createFailed(['error' => 'amount_out_of_range', 'amount' => $amount, 'min' => $min, 'max' => $max], $message);
        }

        $live = $this->liveInvoice($payment, $amount);
        if (null !== $live) {
            return $live;
        }

        $paymentId = $payment->getId();
        if (null === $paymentId) {
            return $this->createFailed(['error' => 'payment_not_saved'], self::CUSTOMER_FAILED);
        }
        $apiKey = self::secret($config, 'api_key');
        $secrets = self::secrets($config);
        $baseUrl = (string) self::baseUrl($config);
        $callbackBaseUrl = (string) self::callbackBaseUrl($config);
        $returnBase = $config[PluginPaymentGatewayDriverAdapter::CONFIG_RETURN_BASE_URL] ?? null;
        $orderId = self::orderReference($payment, $order);

        $params = [
            'amount' => $amount,
            'fee_mode' => (string) self::feeMode($config),
            'description' => self::description($payment, $order, $config, $amount),
            'order_id' => $orderId,
            'callback_url' => $callbackBaseUrl.self::WEBHOOK_PATH,
            // The core return page: it verifies and shows the state. On the brand domain when there is one.
            'return_url' => PaymentReturnUrl::returnUrl(is_string($returnBase) && '' !== trim($returnBase) ? $returnBase : $callbackBaseUrl, $payment),
        ];

        try {
            $response = $this->api->post($baseUrl, HooshPayApi::INVOICES_PATH, $apiKey, $params);
        } catch (HooshPayException $exception) {
            return $this->createFailed(['error' => $exception->timeout ? 'timeout' : 'transport', 'detail' => $this->mask($exception->getMessage(), $secrets)]);
        }

        $httpStatus = $response['status'];
        $body = $response['body'];
        $failure = $this->accountFailure($httpStatus);
        if (null !== $failure) {
            return $this->createFailed(['error' => $failure['error'], 'http_status' => $httpStatus, 'detail' => $failure['message']], 503 === $httpStatus ? self::CUSTOMER_UNAVAILABLE : self::CUSTOMER_FAILED);
        }
        if (!in_array($httpStatus, [200, 201], true)) {
            return $this->createFailed(['error' => 'http_'.$httpStatus, 'http_status' => $httpStatus, 'message' => $this->text($body['message'] ?? null, $secrets)], self::CUSTOMER_FAILED);
        }
        $data = $body['data'] ?? null;
        if (true !== ($body['success'] ?? null) || !is_array($data)) {
            return $this->createFailed(['error' => 'success_not_true', 'http_status' => $httpStatus, 'message' => $this->text($body['message'] ?? null, $secrets)], self::CUSTOMER_FAILED);
        }

        $uid = $this->uid($data['uid'] ?? null);
        if (null === $uid) {
            return $this->createFailed(['error' => 'invalid_uid', 'http_status' => $httpStatus], self::CUSTOMER_FAILED);
        }
        // From here on an invoice exists at HooshPay: one that is not used is cancelled (best effort).
        $paymentUrl = $this->link($data['payment_url'] ?? null, $baseUrl);
        $payable = self::positiveInt($data['payable_amount'] ?? null);
        $error = match (true) {
            self::positiveInt($data['amount'] ?? null) !== $amount => 'amount_mismatch',
            null === $paymentUrl => 'invalid_link',
            null === $payable => 'invalid_payable_amount',
            default => null,
        };
        if (null !== $error) {
            $this->cancel($baseUrl, $apiKey, $uid);

            return $this->createFailed(['error' => $error, 'http_status' => $httpStatus, 'uid' => $uid], self::CUSTOMER_FAILED);
        }

        // Frozen at invoice time: verifyPayment() and AmountMatcher compare with it. Never payable_amount.
        $payment
            ->setExpectation((string) $amount, self::CURRENCY)
            ->setExpiresAt($this->expiresAt($data['expires_at'] ?? null, $config));

        // An allowlist: the card object (card number, holder name, bank) and anything else HooshPay sends are dropped.
        $stored = ['success' => true, 'uid' => $uid, 'order_id' => $orderId, 'amount' => $amount, GatewayPayableAmount::KEY => $payable];
        foreach (['fee_mode', 'fee_percent', 'fee_amount', 'payable_amount', 'merchant_credit', 'status', 'expires_at'] as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) || (is_string($value) && 1 === preg_match('/^[A-Za-z0-9 _:.+-]{1,40}$/', $value))) {
                $stored[$key] = $value;
            }
        }

        return new PaymentRequestResult(
            success: true,
            paymentUrl: $paymentUrl,
            transactionId: $uid,
            message: 'HooshPay invoice created.',
            rawResponse: $stored,
        );
    }

    /**
     * @param array<string, mixed> $payload ignored: the request proves nothing, only HooshPay's verify does
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        // Not gated by "enabled": an invoice issued before the switch went off can still be paid (return page).
        $apiKey = self::secret($config, 'api_key');
        $baseUrl = self::baseUrl($config);
        if ('' === $apiKey || null === $baseUrl) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'HooshPay api_key or base_url is not configured.');
        }
        // Only the uid stored by createPayment(), never one from the request.
        $uid = $this->uid($payment->getGatewayTransactionId());
        if (null === $uid) {
            return new PaymentVerificationResult(success: false, paid: false, message: 'HooshPay invoice uid is not stored for this payment.');
        }
        $secrets = self::secrets($config);

        $answer = $this->call(fn (): array => $this->api->post($baseUrl, HooshPayApi::invoicePath($uid, 'verify'), $apiKey, []), $uid, $secrets);
        if ($answer instanceof PaymentVerificationResult) {
            return $answer;
        }
        [$httpStatus, $body] = $answer;
        $rawResponse = $this->sanitize($body, $secrets);
        if (404 === $httpStatus) {
            return new PaymentVerificationResult(success: false, paid: false, transactionId: $uid, message: 'HooshPay does not know this invoice (HTTP 404).', rawResponse: $rawResponse);
        }
        if (200 !== $httpStatus || true !== ($body['success'] ?? null)) {
            return new PaymentVerificationResult(success: false, paid: false, transactionId: $uid, message: sprintf('HooshPay verify failed (HTTP %d).', $httpStatus), rawResponse: $rawResponse);
        }

        $status = $body['status'] ?? null;
        if (true !== ($body['paid'] ?? null)) {
            if (is_string($status) && in_array($status, self::NOT_PAID_STATUSES, true)) {
                return new PaymentVerificationResult(success: true, paid: false, transactionId: $uid, message: sprintf('HooshPay: status "%s"; not paid.', $status), rawResponse: $rawResponse);
            }

            return new PaymentVerificationResult(success: false, paid: false, transactionId: $uid, message: 'HooshPay verify has no usable status.', rawResponse: $rawResponse);
        }

        return $this->paidAnswer($payment, $uid, $body, $rawResponse, $apiKey, $baseUrl, $secrets);
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * Authenticates HooshPay's webhook, fail closed: the switch on, the Secret set, the signature header present and equal
     * (hash_equals) to signature() of the raw body. Accepted, it hands the core the invoice uid as the only reference;
     * whether anything was paid is decided by verifyPayment().
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        if (true !== ($config['enabled'] ?? null)) {
            return new PaymentWebhookResult(false, self::WEBHOOK_DISABLED);
        }
        $secret = self::secret($config, 'api_secret');
        $signature = strtolower(trim((string) $request->headers->get(self::SIGNATURE_HEADER, '')));
        if ('' === $secret || 1 !== preg_match('/^[0-9a-f]{64}$/D', $signature)) {
            return new PaymentWebhookResult(false, self::WEBHOOK_UNAUTHORIZED);
        }
        $body = json_decode((string) $request->getContent(), true, 32);
        if (!is_array($body) || [] === $body || array_is_list($body)) {
            return new PaymentWebhookResult(false, self::WEBHOOK_UNAUTHORIZED);
        }
        if (!hash_equals(self::signature($body, $secret), $signature)) {
            return new PaymentWebhookResult(false, self::WEBHOOK_UNAUTHORIZED);
        }
        $uid = $this->uid($body['invoice'] ?? null);
        if (null === $uid) {
            return new PaymentWebhookResult(false, self::WEBHOOK_INVALID_PAYLOAD);
        }

        // The uid is the only reference: every other key the core would read as one is cleared.
        return new PaymentWebhookResult(true, 'authenticated', [
            'payment_id' => null, 'paymentId' => null, 'transactionId' => null, 'authority' => null, 'Authority' => null, 'trackId' => null,
            'transaction_id' => $uid,
        ]);
    }

    /**
     * Cancels the invoice of a row the core closed (PaymentStarter, a superseded invoice). Best effort: HooshPay only
     * cancels a pending invoice, and a failure leaves the row as it is. Returns CANCELLED, CANCEL_SKIPPED or an error code.
     *
     * @param array<string, mixed> $config
     */
    public function cancelInvoice(Payment $payment, array $config): string
    {
        $apiKey = self::secret($config, 'api_key');
        $baseUrl = self::baseUrl($config);
        $uid = $this->uid($payment->getGatewayTransactionId());
        if ('' === $apiKey || null === $baseUrl || null === $uid || self::CODE !== $payment->getGateway()?->getPluginCode()) {
            return self::CANCEL_SKIPPED;
        }

        return $this->cancel($baseUrl, $apiKey, $uid);
    }

    /**
     * verify said paid. Paid with our amount only when every rule holds: status "paid", the stored uid, our order_id and
     * our expected amount. order_id and amount come from verify's data, else from the invoice's status (the docs elide
     * them in verify's example). Anything else is not settled: the evidence goes into the verify payload and the result
     * carries ERROR_VERIFY_MISMATCH, so the webhook logs a security warning.
     *
     * @param array<mixed>  $body
     * @param array<mixed>  $rawResponse
     * @param list<string>  $secrets
     */
    private function paidAnswer(Payment $payment, string $uid, array $body, array $rawResponse, string $apiKey, string $baseUrl, array $secrets): PaymentVerificationResult
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!array_key_exists('order_id', $data) || !array_key_exists('amount', $data)) {
            $status = $this->call(fn (): array => $this->api->get($baseUrl, HooshPayApi::invoicePath($uid), $apiKey), $uid, $secrets);
            if ($status instanceof PaymentVerificationResult) {
                return $status;
            }
            $statusData = 200 === $status[0] && is_array($status[1]['data'] ?? null) ? $status[1]['data'] : [];
            if ($this->scalarString($statusData['uid'] ?? null) === $uid) {
                $data += array_intersect_key($statusData, ['order_id' => true, 'amount' => true]);
            }
        }

        $expected = $this->expectedAmount($payment);
        $expectedOrderId = $this->storedOrderId($payment);
        $reportedUid = $this->scalarString($data['uid'] ?? null);
        $reportedOrderId = $this->scalarString($data['order_id'] ?? null);
        $amount = $data['amount'] ?? null;
        $amountText = is_int($amount) ? (string) $amount : (is_string($amount) ? $amount : null);

        $reasons = [];
        if (self::PAID !== ($body['status'] ?? null)) {
            $reasons[] = 'status_not_paid';
        }
        if ($reportedUid !== $uid) {
            $reasons[] = null === $reportedUid ? 'uid_missing' : 'uid_mismatch';
        }
        if ($reportedOrderId !== $expectedOrderId || null === $expectedOrderId) {
            $reasons[] = null === $reportedOrderId ? 'order_id_missing' : 'order_id_mismatch';
        }
        if (null === $amount) {
            $reasons[] = 'amount_missing';
        } elseif (null === $amountText || 1 !== preg_match('/^\d+$/D', $amountText)) {
            $reasons[] = 'amount_not_integer';
        } elseif (null === $expected || $amountText !== $expected) {
            $reasons[] = 'amount_mismatch';
        }

        $trackingCode = $this->scalarString($data['tracking_code'] ?? null);
        $trackingCode = null !== $trackingCode && 1 === preg_match(self::TRACKING_CODE_PATTERN, $trackingCode) ? $trackingCode : null;

        if ([] === $reasons && null !== $expected) {
            return new PaymentVerificationResult(
                success: true,
                paid: true,
                transactionId: $uid,
                refId: $trackingCode ?? $uid,
                message: 'HooshPay confirmed the payment.',
                rawResponse: $rawResponse,
                // Our own amount, never payable_amount (which carries the unique-amount adjustment and the buyer's fee).
                amount: $expected,
                currency: self::CURRENCY,
            );
        }

        $evidence = [
            'reason' => $reasons[0],
            'reasons' => $reasons,
            'reported_amount' => null === $amount ? null : mb_substr(is_string($amount) ? $amount : (string) json_encode($amount, JSON_PRESERVE_ZERO_FRACTION), 0, 32),
            'expected_amount' => $expected,
            'uid_match' => $reportedUid === $uid ? 'yes' : 'no',
            'order_id_match' => null !== $expectedOrderId && $reportedOrderId === $expectedOrderId ? 'yes' : 'no',
        ];
        $payment->setVerifyPayload([self::REVIEW_EVIDENCE_KEY => $evidence]);

        return new PaymentVerificationResult(
            success: false,
            paid: false,
            transactionId: $uid,
            message: sprintf('HooshPay says paid, but the answer does not match this payment (%s); not settled.', implode(', ', $reasons)),
            rawResponse: ['error' => self::ERROR_VERIFY_MISMATCH] + $evidence,
        );
    }

    /**
     * One API call for verifyPayment(): the answer, or the verification result its failure is.
     *
     * @param callable(): array{status: int, body: array<mixed>} $request
     * @param list<string>                                       $secrets
     *
     * @return array{int, array<mixed>}|PaymentVerificationResult
     */
    private function call(callable $request, string $uid, array $secrets): array|PaymentVerificationResult
    {
        try {
            $response = $request();
        } catch (HooshPayException $exception) {
            $message = $this->mask($exception->getMessage(), $secrets);

            return new PaymentVerificationResult(success: false, paid: false, transactionId: $uid, message: $message, rawResponse: ['error' => $exception->timeout ? 'timeout' : 'transport']);
        }
        $failure = $this->accountFailure($response['status']);
        if (null !== $failure) {
            return new PaymentVerificationResult(success: false, paid: false, transactionId: $uid, message: $failure['message'], rawResponse: ['error' => $failure['error'], 'http_status' => $response['status']]);
        }

        return [$response['status'], $response['body']];
    }

    private function cancel(string $baseUrl, #[\SensitiveParameter] string $apiKey, string $uid): string
    {
        try {
            $response = $this->api->post($baseUrl, HooshPayApi::invoicePath($uid, 'cancel'), $apiKey, []);
        } catch (HooshPayException $exception) {
            return $exception->timeout ? 'timeout' : 'transport';
        }

        return 200 === $response['status'] && true === ($response['body']['success'] ?? null) ? self::CANCELLED : 'http_'.$response['status'];
    }

    /**
     * The row already holds this gateway's invoice, unexpired, for this amount: the customer gets its link again and no
     * second invoice is created (one row, one invoice).
     */
    private function liveInvoice(Payment $payment, int $amount): ?PaymentRequestResult
    {
        $uid = $this->uid($payment->getGatewayTransactionId());
        $expiresAt = $payment->getExpiresAt();
        $paymentUrl = $payment->getPaymentUrl();
        if (null === $uid || null === $paymentUrl || '' === $paymentUrl || null === $expiresAt || $expiresAt <= new \DateTimeImmutable()
            || self::CODE !== $payment->getGateway()?->getPluginCode()
            || (string) $amount !== $payment->getExpectedAmount() || self::CURRENCY !== $payment->getExpectedCurrency()) {
            return null;
        }

        return new PaymentRequestResult(success: true, paymentUrl: $paymentUrl, transactionId: $uid, message: 'HooshPay invoice is still open.');
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    private function createFailed(array $rawResponse, string $message = self::CUSTOMER_UNAVAILABLE): PaymentRequestResult
    {
        return new PaymentRequestResult(success: false, message: $message, rawResponse: $rawResponse);
    }

    /**
     * 401, 403 and 503: decided by the status alone (HooshPayApi::STATUS_ONLY).
     *
     * @return array{error: string, message: string}|null
     */
    private function accountFailure(int $httpStatus): ?array
    {
        return match ($httpStatus) {
            401 => ['error' => self::ERROR_UNAUTHORIZED, 'message' => 'HooshPay rejected the API key (HTTP 401).'],
            403 => ['error' => self::ERROR_STORE_DISABLED, 'message' => 'HooshPay says the account is blocked (HTTP 403).'],
            503 => ['error' => 'gateway_unavailable', 'message' => 'HooshPay is disabled or has no card available (HTTP 503).'],
            default => null,
        };
    }

    /**
     * The invoice's own expiry; the configured fallback lifetime when expires_at is missing, unparseable, already past
     * or more than a week away. An expires_at without an offset is read in PROVIDER_TIMEZONE.
     *
     * @param array<string, mixed> $config
     */
    private function expiresAt(mixed $value, array $config): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        if (is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/D', trim($value))) {
            try {
                $parsed = new \DateTimeImmutable(trim($value), new \DateTimeZone(self::PROVIDER_TIMEZONE));
                if ($parsed > $now && $parsed <= $now->modify('+7 days')) {
                    return $parsed;
                }
            } catch (\Exception) {
                // Falls back below.
            }
        }
        $lifetime = self::intSetting($config, 'invoice_lifetime_seconds');

        return $now->modify(sprintf('+%d seconds', is_int($lifetime) ? $lifetime : self::DEFAULT_LIFETIME_SECONDS));
    }

    /**
     * Our unique reference for this payment row, sent as order_id: the order's tracking code and the payment id.
     */
    public static function orderReference(Payment $payment, Order $order): string
    {
        $tracking = (string) preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $order->getTrackingCode());
        $reference = ('' !== $tracking ? $tracking.'-' : 'payment-').'P'.(string) $payment->getId();

        return substr($reference, -self::ORDER_ID_MAX_LENGTH);
    }

    /** The order_id createPayment() sent (stored in the request payload). */
    private function storedOrderId(Payment $payment): ?string
    {
        $request = $payment->getRequestPayload();
        $orderId = is_array($request) ? ($request['order_id'] ?? null) : null;

        return is_string($orderId) && '' !== $orderId ? $orderId : null;
    }

    /** The amount frozen at invoice time, else the payable amount; digits only, or null. */
    private function expectedAmount(Payment $payment): ?string
    {
        $expected = self::CURRENCY === $payment->getExpectedCurrency() ? $payment->getExpectedAmount() : null;
        $expected ??= (string) self::hooshPayAmount($payment);

        return 1 === preg_match('/^\d+$/D', $expected) && '0' !== $expected ? $expected : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function description(Payment $payment, Order $order, array $config, int $amount): string
    {
        $template = $config['description_template'] ?? null;
        $template = is_string($template) && '' !== trim($template) ? trim($template) : self::DEFAULT_DESCRIPTION;
        $text = strtr($template, [
            '{order}' => (string) ($order->getTrackingCode() ?? ''),
            '{payment}' => (string) $payment->getId(),
            '{amount}' => (string) $amount,
        ]);
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

        return mb_substr('' !== $text ? $text : 'Order', 0, self::DESCRIPTION_MAX_LENGTH);
    }

    /**
     * [min, max|null] in Toman: the settings, never below HooshPay's minimum.
     *
     * @param array<string, mixed> $config
     *
     * @return array{int, int|null}
     */
    private static function amountLimits(array $config): array
    {
        $min = self::intSetting($config, 'min_amount');
        $max = self::intSetting($config, 'max_amount');

        return [max(self::HARD_MIN_AMOUNT_TOMAN, is_int($min) ? $min : 0), is_int($max) ? $max : null];
    }

    /**
     * An integer setting: null when not set, false when set but not a whole number.
     *
     * @param array<string, mixed> $config
     */
    private static function intSetting(array $config, string $key): int|false|null
    {
        $value = $config[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_string($value) && 1 === preg_match('/^\d{1,15}$/D', trim($value))) {
            return (int) trim($value);
        }

        return is_int($value) ? $value : false;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function secret(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function secrets(array $config): array
    {
        return array_values(array_filter([self::secret($config, 'api_key'), self::secret($config, 'api_secret')], static fn (string $s): bool => '' !== $s));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function feeMode(array $config): ?string
    {
        $mode = $config['fee_mode'] ?? null;
        if (null === $mode || '' === $mode) {
            return self::DEFAULT_FEE_MODE;
        }

        return is_string($mode) && in_array($mode, self::FEE_MODES, true) ? $mode : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function baseUrl(array $config): ?string
    {
        $url = $config['base_url'] ?? null;

        return self::httpsBase(null === $url || '' === $url ? HooshPayApi::DEFAULT_BASE_URL : $url);
    }

    /**
     * https, a host, no user info, no query or fragment (a path is appended to it). Without a trailing slash. The same
     * rule as Tetraminator's callback_base_url.
     *
     * @param array<string, mixed> $config
     */
    private static function callbackBaseUrl(array $config): ?string
    {
        return self::httpsBase($config['callback_base_url'] ?? null);
    }

    private static function httpsBase(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || 1 === preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        return $url;
    }

    private function uid(mixed $value): ?string
    {
        $uid = $this->scalarString($value);

        return null !== $uid && 1 === preg_match(self::UID_PATTERN, $uid) ? $uid : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_string($value) && 1 === preg_match('/^\d{1,15}$/D', $value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /** An int or a string as a string; anything else (bool, float, array, null) is no value. */
    private function scalarString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }

    /** An https URL on the base URL's host, no user info and no port. */
    private function link(mixed $value, string $baseUrl): ?string
    {
        if (!is_string($value) || '' === trim($value) || strlen($value) > 2048 || 1 === preg_match('/[\s\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || 0 !== strcasecmp($parts['host'], (string) parse_url($baseUrl, PHP_URL_HOST))) {
            return null;
        }

        return $value;
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
     * Provider response as stored on the payment: the card object and secret-looking keys removed, the exact secrets
     * masked anywhere.
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
            if (is_string($key) && 1 === preg_match(self::DROPPED_KEYS, $key)) {
                continue;
            }
            if (is_array($item)) {
                $sanitized[$key] = $this->sanitize($item, $secrets);
            } else {
                $sanitized[$key] = is_string($item) ? $this->mask($item, $secrets) : $item;
            }
        }

        return $sanitized;
    }
}
