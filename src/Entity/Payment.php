<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Payment
{
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

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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
}
