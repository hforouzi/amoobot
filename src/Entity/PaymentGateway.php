<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payment\Domain\PaymentGatewayType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class PaymentGateway
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\Choice(choices: PaymentGatewayType::ALL)]
    private string $type = PaymentGatewayType::MANUAL_CARD;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(length: 8)]
    private string $currency = 'IRR';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper(trim($currency));

        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function getConfigJson(): string
    {
        if (empty($this->config)) {
            return '';
        }

        $encoded = json_encode($this->config, self::JSON_FLAGS);
        if (false === $encoded) {
            error_log('[PaymentGateway] config_json_encode_failed id='.(string) ($this->id ?? 0));

            return '';
        }

        return $encoded;
    }

    public function setConfigJson(?string $json): self
    {
        if ($json === null || '' === trim($json)) {
            $this->config = [];

            return $this;
        }

        $decoded = json_decode($json, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            $this->config = [];

            return $this;
        }

        $this->config = $decoded;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->title ?: 'Gateway', $this->type);
    }

    public function isConfigured(): bool
    {
        return match ($this->type) {
            PaymentGatewayType::MANUAL_CARD => null !== $this->getManualCardNumber() && null !== $this->getManualCardHolder(),
            PaymentGatewayType::ZIBAL => null !== $this->getZibalCallbackBaseUrl() && null !== $this->getZibalMerchant(),
            PaymentGatewayType::CUSTOM_API => $this->isCustomApiConfigured(),
            PaymentGatewayType::NOWPAYMENTS => $this->isNowPaymentsConfigured(),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomApiConfig(): array
    {
        $config = $this->getConfig();

        return is_array($config) ? $config : [];
    }

    public function getManualCardNumber(): ?string
    {
        return $this->configString('card_number');
    }

    public function setManualCardNumber(?string $value): self
    {
        return $this->setConfigString('card_number', $value);
    }

    public function getManualCardHolder(): ?string
    {
        return $this->configString('card_holder');
    }

    public function setManualCardHolder(?string $value): self
    {
        return $this->setConfigString('card_holder', $value);
    }

    public function getManualBankName(): ?string
    {
        return $this->configString('bank_name');
    }

    public function setManualBankName(?string $value): self
    {
        return $this->setConfigString('bank_name', $value);
    }

    public function getManualInstructions(): ?string
    {
        return $this->configString('instructions');
    }

    public function setManualInstructions(?string $value): self
    {
        return $this->setConfigString('instructions', $value);
    }

    public function getZibalMerchant(): ?string
    {
        return $this->configString('merchant');
    }

    public function setZibalMerchant(?string $value): self
    {
        return $this->setConfigString('merchant', $value);
    }

    public function isZibalSandbox(): bool
    {
        return true === ($this->configBool('sandbox'));
    }

    public function setZibalSandbox(bool $value): self
    {
        return $this->setConfigBool('sandbox', $value);
    }

    public function getZibalCallbackBaseUrl(): ?string
    {
        return $this->configString('callback_base_url');
    }

    public function setZibalCallbackBaseUrl(?string $value): self
    {
        return $this->setConfigString('callback_base_url', $value);
    }

    public function getZibalDescription(): ?string
    {
        return $this->configString('description');
    }

    public function setZibalDescription(?string $value): self
    {
        return $this->setConfigString('description', $value);
    }

    public function getZibalMobile(): ?string
    {
        return $this->configString('mobile');
    }

    public function setZibalMobile(?string $value): self
    {
        return $this->setConfigString('mobile', $value);
    }

    public function getZibalAllowedCards(): ?string
    {
        return $this->configString('allowedCards');
    }

    public function setZibalAllowedCards(?string $value): self
    {
        return $this->setConfigString('allowedCards', $value);
    }

    public function getZibalPercentMode(): ?string
    {
        return $this->configString('percentMode');
    }

    public function setZibalPercentMode(?string $value): self
    {
        return $this->setConfigString('percentMode', $value);
    }

    public function getZibalFeeMode(): ?string
    {
        return $this->configString('feeMode');
    }

    public function setZibalFeeMode(?string $value): self
    {
        return $this->setConfigString('feeMode', $value);
    }

    public function getZibalMultiplexingAccountNumber(): ?string
    {
        return $this->configString('multiplexingAccountNumber');
    }

    public function setZibalMultiplexingAccountNumber(?string $value): self
    {
        return $this->setConfigString('multiplexingAccountNumber', $value);
    }

    private function configString(string $key): ?string
    {
        $value = is_array($this->config) ? ($this->config[$key] ?? null) : null;
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private function configBool(string $key): bool
    {
        return true === (is_array($this->config) ? ($this->config[$key] ?? false) : false);
    }

    private function setConfigString(string $key, ?string $value): self
    {
        $config = is_array($this->config) ? $this->config : [];
        $text = trim((string) $value);
        if ('' === $text) {
            unset($config[$key]);
        } else {
            $config[$key] = $text;
        }
        $this->config = $config;

        return $this;
    }

    private function setConfigBool(string $key, bool $value): self
    {
        $config = is_array($this->config) ? $this->config : [];
        $config[$key] = $value;
        $this->config = $config;

        return $this;
    }

    private function isCustomApiConfigured(): bool
    {
        $config = $this->getCustomApiConfig();
        $createUrl = trim((string) ($config['create']['url'] ?? ''));
        $verifyUrl = trim((string) ($config['verify']['url'] ?? ''));

        return '' !== $createUrl && '' !== $verifyUrl;
    }

    // NOWPayments config accessors

    public function getNowPaymentsApiKey(): ?string
    {
        return $this->configString('api_key');
    }

    public function setNowPaymentsApiKey(?string $value): self
    {
        return $this->setConfigString('api_key', $value);
    }

    public function getNowPaymentsIpnSecret(): ?string
    {
        return $this->configString('ipn_secret');
    }

    public function setNowPaymentsIpnSecret(?string $value): self
    {
        return $this->setConfigString('ipn_secret', $value);
    }

    public function isNowPaymentsSandbox(): bool
    {
        return true === ($this->configBool('sandbox'));
    }

    public function setNowPaymentsSandbox(bool $value): self
    {
        return $this->setConfigBool('sandbox', $value);
    }

    public function getNowPaymentsCallbackBaseUrl(): ?string
    {
        return $this->configString('callback_base_url');
    }

    public function setNowPaymentsCallbackBaseUrl(?string $value): self
    {
        return $this->setConfigString('callback_base_url', $value);
    }

    public function getNowPaymentsApiBaseUrl(): string
    {
        return $this->configString('api_base_url') ?? 'https://api.nowpayments.io/v1';
    }

    public function setNowPaymentsApiBaseUrl(?string $value): self
    {
        $text = trim((string) $value);
        if ('' === $text || 'https://api.nowpayments.io/v1' === $text) {
            $config = is_array($this->config) ? $this->config : [];
            unset($config['api_base_url']);
            $this->config = $config;

            return $this;
        }

        return $this->setConfigString('api_base_url', $text);
    }

    public function getNowPaymentsPriceCurrency(): ?string
    {
        return $this->configString('price_currency');
    }

    public function setNowPaymentsPriceCurrency(?string $value): self
    {
        return $this->setConfigString('price_currency', $value);
    }

    public function getNowPaymentsPayCurrency(): ?string
    {
        return $this->configString('pay_currency');
    }

    public function setNowPaymentsPayCurrency(?string $value): self
    {
        return $this->setConfigString('pay_currency', $value);
    }

    public function getNowPaymentsPaymentMode(): string
    {
        $value = strtolower((string) ($this->configString('payment_mode') ?? 'invoice'));

        return in_array($value, ['invoice', 'payment'], true) ? $value : 'invoice';
    }

    public function setNowPaymentsPaymentMode(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, ['invoice', 'payment'], true)) {
            $normalized = 'invoice';
        }

        return $this->setConfigString('payment_mode', $normalized);
    }

    public function isNowPaymentsLockPayCurrency(): bool
    {
        return true === ($this->configBool('lock_pay_currency'));
    }

    public function setNowPaymentsLockPayCurrency(bool $value): self
    {
        return $this->setConfigBool('lock_pay_currency', $value);
    }

    public function getNowPaymentsAmountUnit(): string
    {
        $value = strtolower((string) ($this->configString('amount_unit') ?? 'toman'));

        return in_array($value, ['toman', 'rial'], true) ? $value : 'toman';
    }

    public function setNowPaymentsAmountUnit(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, ['toman', 'rial'], true)) {
            $normalized = 'toman';
        }

        return $this->setConfigString('amount_unit', $normalized);
    }

    public function getNowPaymentsTomanPerUsd(): ?int
    {
        $val = is_array($this->config) ? ($this->config['toman_per_usd'] ?? null) : null;
        if (null === $val || '' === (string) $val) {
            return null;
        }

        return (int) $val > 0 ? (int) $val : null;
    }

    public function setNowPaymentsTomanPerUsd(?int $value): self
    {
        $config = is_array($this->config) ? $this->config : [];
        if (null === $value || $value <= 0) {
            unset($config['toman_per_usd']);
        } else {
            $config['toman_per_usd'] = $value;
        }
        $this->config = $config;

        return $this;
    }

    public function getNowPaymentsIrrToUsdRate(): ?int
    {
        $val = is_array($this->config) ? ($this->config['irr_to_usd_rate'] ?? null) : null;
        if (null === $val || '' === (string) $val) {
            return null;
        }

        return (int) $val > 0 ? (int) $val : null;
    }

    public function setNowPaymentsIrrToUsdRate(?int $value): self
    {
        $config = is_array($this->config) ? $this->config : [];
        if (null === $value || $value <= 0) {
            unset($config['irr_to_usd_rate']);
        } else {
            $config['irr_to_usd_rate'] = $value;
        }
        $this->config = $config;

        return $this;
    }

    public function getNowPaymentsMinPriceAmountOverride(): ?float
    {
        $val = is_array($this->config) ? ($this->config['min_price_amount_override'] ?? null) : null;
        if (null === $val || '' === trim((string) $val)) {
            return null;
        }

        $number = (float) $val;

        return $number > 0 ? round($number, 8) : null;
    }

    public function setNowPaymentsMinPriceAmountOverride(?string $value): self
    {
        $config = is_array($this->config) ? $this->config : [];
        $text = trim((string) $value);
        if ('' === $text || (float) $text <= 0) {
            unset($config['min_price_amount_override']);
        } else {
            $config['min_price_amount_override'] = $text;
        }
        $this->config = $config;

        return $this;
    }

    public function getNowPaymentsMinOrderAmountToman(): ?int
    {
        $val = is_array($this->config) ? ($this->config['min_order_amount_toman'] ?? null) : null;
        if (null === $val || '' === (string) $val) {
            return null;
        }

        return (int) $val > 0 ? (int) $val : null;
    }

    public function setNowPaymentsMinOrderAmountToman(?int $value): self
    {
        $config = is_array($this->config) ? $this->config : [];
        if (null === $value || $value <= 0) {
            unset($config['min_order_amount_toman']);
        } else {
            $config['min_order_amount_toman'] = $value;
        }
        $this->config = $config;

        return $this;
    }

    public function getNowPaymentsSuccessUrl(): ?string
    {
        return $this->configString('success_url');
    }

    public function setNowPaymentsSuccessUrl(?string $value): self
    {
        return $this->setConfigString('success_url', $value);
    }

    public function getNowPaymentsCancelUrl(): ?string
    {
        return $this->configString('cancel_url');
    }

    public function setNowPaymentsCancelUrl(?string $value): self
    {
        return $this->setConfigString('cancel_url', $value);
    }

    public function getNowPaymentsOrderDescription(): ?string
    {
        return $this->configString('order_description');
    }

    public function setNowPaymentsOrderDescription(?string $value): self
    {
        return $this->setConfigString('order_description', $value);
    }

    public function isNowPaymentsConfigured(): bool
    {
        $apiKey = $this->getNowPaymentsApiKey();
        $apiBaseUrl = trim($this->getNowPaymentsApiBaseUrl());
        $priceCurrency = trim((string) ($this->getNowPaymentsPriceCurrency() ?? ''));
        $payCurrency = trim((string) ($this->getNowPaymentsPayCurrency() ?? ''));
        $paymentMode = $this->getNowPaymentsPaymentMode();
        $payCurrencyRequired = 'payment' === $paymentMode || ('invoice' === $paymentMode && $this->isNowPaymentsLockPayCurrency());

        if (
            null === $apiKey
            || '' === $apiBaseUrl
            || '' === $priceCurrency
            || ($payCurrencyRequired && '' === $payCurrency)
        ) {
            return false;
        }

        if ('irr' === strtolower($this->getCurrency()) && 'usd' === strtolower($priceCurrency)) {
            return match ($this->getNowPaymentsAmountUnit()) {
                'rial' => null !== $this->getNowPaymentsIrrToUsdRate(),
                default => null !== $this->getNowPaymentsTomanPerUsd(),
            };
        }

        return true;
    }
}
