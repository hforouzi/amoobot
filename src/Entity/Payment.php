<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_payment_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_return_token', columns: ['return_token'])]
class Payment
{
    /** confirmationSource / adminNote of a pending payment rejected because its order expired unpaid. */
    public const SOURCE_ORDER_EXPIRY = 'order_expiry';
    public const NOTE_ORDER_EXPIRY = 'Auto expired incomplete order';
    /** confirmationSource / adminNote of a pending payment the customer cancelled (bot cancel buttons). */
    public const SOURCE_CUSTOMER_CANCEL = 'customer_cancel';
    public const NOTE_CUSTOMER_CANCEL = 'Cancelled by customer';
    /** Review reasons of payments paid after their cancel/expiry start with this. */
    public const REVIEW_PREFIX_LATE_PAYMENT = 'paid after ';

    /**
     * confirmationSource / adminNote of a wallet payment (pending, in review or provisioning_failed) closed because
     * another payment of its order was confirmed (or verified and failed provisioning): its debit was given back
     * (PaymentSettlementService).
     */
    public const SOURCE_ORDER_PAID_OTHERWISE = 'order_paid_otherwise';
    public const NOTE_ORDER_PAID_OTHERWISE = 'Order paid by another payment';
    /**
     * confirmationSource / adminNote of a wallet payment closed because an admin rejected another payment of its order
     * and the order failed: its debit was given back (PaymentSettlementService::reject()).
     */
    public const SOURCE_ORDER_FAILED = 'order_failed';
    public const NOTE_ORDER_FAILED = 'Order failed: another payment of it was rejected';

    /** Paid from the customer's wallet (WalletPayment): no gateway; the debit is in the wallet ledger. */
    public const METHOD_WALLET = 'wallet';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(length: 64)]
    private string $method = 'manual_card';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PaymentGateway $gateway = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StorePaymentMethod $storePaymentMethod = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $gatewayType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gatewayTransactionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authority = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $paymentUrl = null;

    #[ORM\Column(length: 8)]
    private string $currency = 'IRR';

    #[ORM\Column(nullable: true)]
    private ?int $payableAmount = null;

    /**
     * Exact amount a non-Toman gateway (e.g. crypto) quoted at createPayment time, as a decimal string.
     * Null means the expectation is payableAmount in Toman. See App\Payment\Domain\Money\AmountMatcher.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $expectedAmount = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $expectedCurrency = null;

    /** Amount/currency the gateway reported when it was verified (audit trail). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $verifiedAmount = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $verifiedCurrency = null;

    /** e.g. "overpaid": confirmed, but worth a look. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $amountFlag = null;

    /** Why the payment is in NEEDS_REVIEW. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewReason = null;

    /** Last provisioning error (PROVISIONING_FAILED). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $provisioningError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisioningFailedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $provisioningAttempts = 0;

    /** Which flow settled the payment (admin, telegram_admin, zibal_callback, nowpayments_webhook, ...). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $confirmationSource = null;

    #[ORM\Column(nullable: true)]
    private ?int $confirmedByAdminId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $confirmedByTelegramId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $callbackPayload = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $requestPayload = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $verifyPayload = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 50)]
    private string $status = PaymentStatus::PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $trackingCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $receiptFileId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $receiptMessage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    // Crypto payment fields
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $cryptoPriceCurrency = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $cryptoPayCurrency = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cryptoPayAmount = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cryptoActuallyPaid = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cryptoOutcomeAmount = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cryptoPaymentStatus = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $cryptoPaymentId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $cryptoPurchaseId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $cryptoInvoiceId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cryptoInvoiceUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cryptoAddress = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cryptoNetwork = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cryptoExpiresAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $ipnPayload = null;

    /**
     * Unguessable per-payment token carried in gateway return/callback URLs. The customer return
     * page identifies the payment only by this value. NULL only for payments created before the
     * column existed.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $returnToken = null;

    /** Set when an admin rejected the payment and recorded that the money was returned (e.g. a duplicate). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    /**
     * When the admins were alerted that crediting this (wallet top-up) payment failed; set once, by a conditional UPDATE
     * (AdminPaymentAlerter::walletTopUpCreditFailed()), so retries never alert again. Not a status: nothing else reads it.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $walletCreditAlertedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->returnToken = self::generateReturnToken();
    }

    /**
     * Fits a provider-reported, descriptive value (status, amount, currency, source) into its column.
     *
     * The database runs in strict mode, so an over-long value would make the whole settlement fail
     * after the customer has paid. These columns are informational only (decisions use the values
     * from the verification itself), so they are cut explicitly, marked with "…". References used
     * for lookups (transaction ids, authority, …) are deliberately NOT clipped: they must fail loudly.
     */
    private static function clip(?string $value, int $length): ?string
    {
        if (null === $value || mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1).'…';
    }

    public static function generateReturnToken(): string
    {
        // 32 random bytes, base64url without padding: 43 characters.
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function getWalletCreditAlertedAt(): ?\DateTimeImmutable
    {
        return $this->walletCreditAlertedAt;
    }

    public function setRefundedAt(?\DateTimeImmutable $refundedAt): self
    {
        $this->refundedAt = $refundedAt;

        return $this;
    }

    public function getReturnToken(): ?string
    {
        return $this->returnToken;
    }

    /**
     * Rejected while still unpaid, by the incomplete-order expiry job or by the customer cancelling
     * (not by an admin, not reported failed by the gateway). The customer may still complete such a
     * payment at the gateway, so a later "paid" must reach PaymentSettlementService (-> NEEDS_REVIEW).
     */
    public function isRejectedUnpaid(): bool
    {
        return $this->isRejectedByOrderExpiry() || $this->isCancelledByCustomer();
    }

    public function isRejectedByOrderExpiry(): bool
    {
        return PaymentStatus::REJECTED === $this->status
            && (self::SOURCE_ORDER_EXPIRY === $this->confirmationSource || self::NOTE_ORDER_EXPIRY === $this->adminNote);
    }

    public function isCancelledByCustomer(): bool
    {
        return PaymentStatus::REJECTED === $this->status
            && (self::SOURCE_CUSTOMER_CANCEL === $this->confirmationSource || self::NOTE_CUSTOMER_CANCEL === $this->adminNote);
    }

    /**
     * In review because the money arrived after the payment had been cancelled or expired.
     */
    public function isLatePaymentInReview(): bool
    {
        return PaymentStatus::NEEDS_REVIEW === $this->status && str_starts_with((string) $this->reviewReason, self::REVIEW_PREFIX_LATE_PAYMENT);
    }

    /**
     * Whether a gateway verification may still change this payment (pending, or expired unpaid).
     */
    public function acceptsGatewayVerification(): bool
    {
        return in_array($this->status, PaymentStatus::SETTLEABLE, true) || $this->isRejectedUnpaid();
    }

    public function ensureReturnToken(): string
    {
        return $this->returnToken ??= self::generateReturnToken();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function isWalletPayment(): bool
    {
        return self::METHOD_WALLET === $this->method;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        if (null === $this->gatewayType || '' === trim($this->gatewayType)) {
            $this->gatewayType = $method;
        }

        return $this;
    }

    public function getGateway(): ?PaymentGateway
    {
        return $this->gateway;
    }

    public function setGateway(?PaymentGateway $gateway): self
    {
        $this->gateway = $gateway;
        if ($gateway instanceof PaymentGateway) {
            $this->gatewayType = $gateway->getType();
            $this->currency = $gateway->getCurrency();
        }

        return $this;
    }

    public function getStorePaymentMethod(): ?StorePaymentMethod
    {
        return $this->storePaymentMethod;
    }

    public function setStorePaymentMethod(?StorePaymentMethod $storePaymentMethod): self
    {
        $this->storePaymentMethod = $storePaymentMethod;
        if ($storePaymentMethod instanceof StorePaymentMethod) {
            $this->setGateway($storePaymentMethod->getGateway());
        }

        return $this;
    }

    public function getGatewayType(): ?string
    {
        return $this->gatewayType;
    }

    public function setGatewayType(?string $gatewayType): self
    {
        $this->gatewayType = null === $gatewayType ? null : trim($gatewayType);

        return $this;
    }

    public function getGatewayTransactionId(): ?string
    {
        return $this->gatewayTransactionId;
    }

    public function setGatewayTransactionId(?string $gatewayTransactionId): self
    {
        $this->gatewayTransactionId = $gatewayTransactionId;

        return $this;
    }

    public function getAuthority(): ?string
    {
        return $this->authority;
    }

    public function setAuthority(?string $authority): self
    {
        $this->authority = $authority;

        return $this;
    }

    public function getPaymentUrl(): ?string
    {
        return $this->paymentUrl;
    }

    public function setPaymentUrl(?string $paymentUrl): self
    {
        $this->paymentUrl = $paymentUrl;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));
        $this->currency = '' === $currency ? 'IRR' : $currency;

        return $this;
    }

    public function getPayableAmount(): ?int
    {
        return $this->payableAmount;
    }

    public function setPayableAmount(?int $payableAmount): self
    {
        $this->payableAmount = $payableAmount;

        return $this;
    }

    public function getExpectedAmount(): ?string
    {
        return $this->expectedAmount;
    }

    public function getExpectedCurrency(): ?string
    {
        return $this->expectedCurrency;
    }

    public function setExpectation(?string $amount, ?string $currency): self
    {
        $this->expectedAmount = $amount;
        $this->expectedCurrency = null === $currency ? null : strtoupper(trim($currency));

        return $this;
    }

    public function getVerifiedAmount(): ?string
    {
        return $this->verifiedAmount;
    }

    public function getVerifiedCurrency(): ?string
    {
        return $this->verifiedCurrency;
    }

    public function setVerifiedMoney(?string $amount, ?string $currency): self
    {
        $this->verifiedAmount = self::clip($amount, 64);
        $this->verifiedCurrency = self::clip($currency, 16);

        return $this;
    }

    public function getAmountFlag(): ?string
    {
        return $this->amountFlag;
    }

    public function setAmountFlag(?string $amountFlag): self
    {
        $this->amountFlag = $amountFlag;

        return $this;
    }

    public function getReviewReason(): ?string
    {
        return $this->reviewReason;
    }

    public function setReviewReason(?string $reviewReason): self
    {
        $this->reviewReason = $reviewReason;

        return $this;
    }

    public function getProvisioningError(): ?string
    {
        return $this->provisioningError;
    }

    public function setProvisioningError(?string $provisioningError): self
    {
        $this->provisioningError = $provisioningError;

        return $this;
    }

    public function getProvisioningFailedAt(): ?\DateTimeImmutable
    {
        return $this->provisioningFailedAt;
    }

    public function setProvisioningFailedAt(?\DateTimeImmutable $provisioningFailedAt): self
    {
        $this->provisioningFailedAt = $provisioningFailedAt;

        return $this;
    }

    public function getProvisioningAttempts(): int
    {
        return $this->provisioningAttempts;
    }

    public function incrementProvisioningAttempts(): self
    {
        ++$this->provisioningAttempts;

        return $this;
    }

    public function getConfirmationSource(): ?string
    {
        return $this->confirmationSource;
    }

    public function setConfirmationSource(?string $confirmationSource): self
    {
        $this->confirmationSource = self::clip($confirmationSource, 64);

        return $this;
    }

    public function getConfirmedByAdminId(): ?int
    {
        return $this->confirmedByAdminId;
    }

    public function getConfirmedByTelegramId(): ?string
    {
        return $this->confirmedByTelegramId;
    }

    public function setConfirmedBy(?int $adminId, ?string $telegramId): self
    {
        $this->confirmedByAdminId = $adminId;
        $this->confirmedByTelegramId = $telegramId;

        return $this;
    }

    public function getCallbackPayload(): ?array
    {
        return $this->callbackPayload;
    }

    public function setCallbackPayload(?array $callbackPayload): self
    {
        $this->callbackPayload = $callbackPayload;

        return $this;
    }

    public function getRequestPayload(): ?array
    {
        return $this->requestPayload;
    }

    public function setRequestPayload(?array $requestPayload): self
    {
        $this->requestPayload = $requestPayload;

        return $this;
    }

    public function getVerifyPayload(): ?array
    {
        return $this->verifyPayload;
    }

    public function setVerifyPayload(?array $verifyPayload): self
    {
        $this->verifyPayload = $verifyPayload;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function setFailedAt(?\DateTimeImmutable $failedAt): self
    {
        $this->failedAt = $failedAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;
        if (null === $this->payableAmount) {
            $this->payableAmount = $amount;
        }

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTrackingCode(): ?string
    {
        return $this->trackingCode;
    }

    public function setTrackingCode(?string $trackingCode): self
    {
        $this->trackingCode = $trackingCode;

        return $this;
    }

    public function getReceiptFileId(): ?string
    {
        return $this->receiptFileId;
    }

    public function setReceiptFileId(?string $receiptFileId): self
    {
        $this->receiptFileId = $receiptFileId;

        return $this;
    }

    public function getReceiptMessage(): ?string
    {
        return $this->receiptMessage;
    }

    public function setReceiptMessage(?string $receiptMessage): self
    {
        $this->receiptMessage = $receiptMessage;

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): self
    {
        $this->adminNote = $adminNote;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): self
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('Payment #%d', $this->id ?? 0);
    }

    public function getCryptoPriceCurrency(): ?string
    {
        return $this->cryptoPriceCurrency;
    }

    public function setCryptoPriceCurrency(?string $cryptoPriceCurrency): self
    {
        $this->cryptoPriceCurrency = self::clip($cryptoPriceCurrency, 32);

        return $this;
    }

    public function getCryptoPayCurrency(): ?string
    {
        return $this->cryptoPayCurrency;
    }

    public function setCryptoPayCurrency(?string $cryptoPayCurrency): self
    {
        $this->cryptoPayCurrency = self::clip($cryptoPayCurrency, 32);

        return $this;
    }

    public function getCryptoPayAmount(): ?string
    {
        return $this->cryptoPayAmount;
    }

    public function setCryptoPayAmount(?string $cryptoPayAmount): self
    {
        $this->cryptoPayAmount = self::clip($cryptoPayAmount, 64);

        return $this;
    }

    public function getCryptoActuallyPaid(): ?string
    {
        return $this->cryptoActuallyPaid;
    }

    public function setCryptoActuallyPaid(?string $cryptoActuallyPaid): self
    {
        $this->cryptoActuallyPaid = self::clip($cryptoActuallyPaid, 64);

        return $this;
    }

    public function getCryptoOutcomeAmount(): ?string
    {
        return $this->cryptoOutcomeAmount;
    }

    public function setCryptoOutcomeAmount(?string $cryptoOutcomeAmount): self
    {
        $this->cryptoOutcomeAmount = self::clip($cryptoOutcomeAmount, 64);

        return $this;
    }

    public function getCryptoPaymentStatus(): ?string
    {
        return $this->cryptoPaymentStatus;
    }

    public function setCryptoPaymentStatus(?string $cryptoPaymentStatus): self
    {
        $this->cryptoPaymentStatus = self::clip($cryptoPaymentStatus, 64);

        return $this;
    }

    public function getCryptoPaymentId(): ?string
    {
        return $this->cryptoPaymentId;
    }

    public function setCryptoPaymentId(?string $cryptoPaymentId): self
    {
        $this->cryptoPaymentId = $cryptoPaymentId;

        return $this;
    }

    public function getCryptoPurchaseId(): ?string
    {
        return $this->cryptoPurchaseId;
    }

    public function setCryptoPurchaseId(?string $cryptoPurchaseId): self
    {
        $this->cryptoPurchaseId = $cryptoPurchaseId;

        return $this;
    }

    public function getCryptoInvoiceId(): ?string
    {
        return $this->cryptoInvoiceId;
    }

    public function setCryptoInvoiceId(?string $cryptoInvoiceId): self
    {
        $this->cryptoInvoiceId = $cryptoInvoiceId;

        return $this;
    }

    public function getCryptoInvoiceUrl(): ?string
    {
        return $this->cryptoInvoiceUrl;
    }

    public function setCryptoInvoiceUrl(?string $cryptoInvoiceUrl): self
    {
        $this->cryptoInvoiceUrl = $cryptoInvoiceUrl;

        return $this;
    }

    public function getCryptoAddress(): ?string
    {
        return $this->cryptoAddress;
    }

    public function setCryptoAddress(?string $cryptoAddress): self
    {
        $this->cryptoAddress = $cryptoAddress;

        return $this;
    }

    public function getCryptoNetwork(): ?string
    {
        return $this->cryptoNetwork;
    }

    public function setCryptoNetwork(?string $cryptoNetwork): self
    {
        $this->cryptoNetwork = self::clip($cryptoNetwork, 64);

        return $this;
    }

    public function getCryptoExpiresAt(): ?\DateTimeImmutable
    {
        return $this->cryptoExpiresAt;
    }

    public function setCryptoExpiresAt(?\DateTimeImmutable $cryptoExpiresAt): self
    {
        $this->cryptoExpiresAt = $cryptoExpiresAt;

        return $this;
    }

    public function getIpnPayload(): ?array
    {
        return $this->ipnPayload;
    }

    public function setIpnPayload(?array $ipnPayload): self
    {
        $this->ipnPayload = $ipnPayload;

        return $this;
    }
}
