<?php

declare(strict_types=1);

namespace App\Entity;

use App\Provisioning\Domain\SanaeiIntegrationMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class VpnPanel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $type = 'dummy';

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    /** Legacy (cookie login) panels only; stored encrypted (SecretCipher), never for modern panels. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $password = null;

    /** Stored encrypted (SecretCipher); read it through PanelCredentials. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $apiToken = null;

    /** Typed in a form, encrypted into $password/$apiToken on flush (VpnPanelSecretsListener); never persisted. */
    private ?string $plainPassword = null;
    private ?string $plainApiToken = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subscriptionBaseUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicHost = null;

    #[ORM\Column]
    private bool $isActive = true;

    /** auto | legacy | modern (SanaeiIntegrationMode): what the admin chose. */
    #[ORM\Column(length: 32, options: ['default' => 'auto'])]
    private string $sanaeiIntegrationMode = 'auto';

    /** legacy | modern as last confirmed by a capability probe; null = never confirmed. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $sanaeiDetectedMode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sanaeiProbedAt = null;

    /** "ok" or the PanelFailure value of the last probe. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sanaeiProbeStatus = null;

    /** Consecutive failed panel calls (reset by any success). */
    #[ORM\Column(options: ['default' => 0])]
    private int $consecutiveFailures = 0;

    /** Set after DEGRADED_AFTER_FAILURES consecutive failures: the panel's plans are hidden from new purchases. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $degradedSince = null;

    public const DEGRADED_AFTER_FAILURES = 3;

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

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /** As stored: "enc:v1:..." (or plain text from before encryption, until the next flush). */
    public function getStoredPassword(): ?string
    {
        return $this->password;
    }

    public function storePassword(?string $stored): self
    {
        $this->password = '' === trim((string) $stored) ? null : $stored;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    /** Form input: empty keeps the stored password (the field is never pre-filled). */
    public function setPlainPassword(#[\SensitiveParameter] ?string $password): self
    {
        $this->plainPassword = '' === (string) $password ? null : $password;

        return $this;
    }

    public function clearPassword(): self
    {
        $this->password = null;
        $this->plainPassword = null;

        return $this;
    }

    public function getPasswordConfiguredLabel(): string
    {
        return $this->hasPassword() ? 'yes' : 'no';
    }

    public function hasPassword(): bool
    {
        return null !== $this->plainPassword || '' !== trim((string) $this->password);
    }

    /** As stored: "enc:v1:..." (or plain text / the old config.api_token copy from before encryption). */
    public function getStoredApiToken(): ?string
    {
        $token = trim((string) ($this->apiToken ?? ''));
        if ('' !== $token) {
            return $token;
        }
        $fallback = trim((string) ($this->getConfigValue('api_token') ?? ''));

        return '' === $fallback ? null : $fallback;
    }

    /** Stores an already-encrypted token and drops the old plain-text copy in config. */
    public function storeApiToken(?string $stored): self
    {
        $this->apiToken = '' === trim((string) $stored) ? null : $stored;
        $this->setConfigValue('api_token', null);

        return $this;
    }

    public function getPlainApiToken(): ?string
    {
        return $this->plainApiToken;
    }

    /** Form input: empty keeps the stored token (the field is never pre-filled). */
    public function setPlainApiToken(#[\SensitiveParameter] ?string $apiToken): self
    {
        $token = trim((string) $apiToken);
        $this->plainApiToken = '' === $token ? null : $token;

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

    public function getSubscriptionBaseUrl(): ?string
    {
        $value = trim((string) ($this->subscriptionBaseUrl ?? ''));
        if ('' !== $value) {
            return $value;
        }

        $fallback = trim((string) ($this->getConfigValue('subscription_base_url') ?? ''));

        return '' === $fallback ? null : $fallback;
    }

    public function setSubscriptionBaseUrl(?string $subscriptionBaseUrl): self
    {
        $value = trim((string) ($subscriptionBaseUrl ?? ''));
        $this->subscriptionBaseUrl = '' === $value ? null : $value;
        // Stored in the column only. Older rows also have config.subscription_base_url, which the getter still falls
        // back to; drop it on save so readers that look at the config key first cannot see a stale value.
        $this->setConfigValue('subscription_base_url', null);

        return $this;
    }

    public function getPublicHost(): ?string
    {
        return $this->publicHost;
    }

    public function setPublicHost(?string $publicHost): self
    {
        $this->publicHost = $publicHost;

        return $this;
    }

    public function getConfigJson(): string
    {
        if ($this->config === null || $this->config === []) {
            return '';
        }

        return json_encode(
            $this->config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    public function setConfigJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            $this->config = [];

            return $this;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid JSON config: ' . json_last_error_msg());
        }

        $this->config = $decoded;

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
        return sprintf('%s (%s)', $this->title ?? 'Panel', $this->type);
    }

    public function getIntegrationMode(): SanaeiIntegrationMode
    {
        return SanaeiIntegrationMode::fromStored($this->sanaeiIntegrationMode);
    }

    public function setIntegrationMode(SanaeiIntegrationMode|string|null $mode): self
    {
        $this->sanaeiIntegrationMode = ($mode instanceof SanaeiIntegrationMode ? $mode : SanaeiIntegrationMode::fromStored($mode))->value;

        return $this;
    }

    /** auto | legacy | modern, for forms. */
    public function getIntegrationModeValue(): string
    {
        return $this->getIntegrationMode()->value;
    }

    public function setIntegrationModeValue(?string $mode): self
    {
        return $this->setIntegrationMode($mode);
    }

    /**
     * LEGACY or MODERN: the forced mode, else the last detected one, else LEGACY (never probed).
     */
    public function getEffectiveIntegrationMode(): SanaeiIntegrationMode
    {
        $mode = $this->getIntegrationMode();
        if (SanaeiIntegrationMode::AUTO !== $mode) {
            return $mode;
        }

        return SanaeiIntegrationMode::MODERN->value === $this->sanaeiDetectedMode ? SanaeiIntegrationMode::MODERN : SanaeiIntegrationMode::LEGACY;
    }

    public function isModern(): bool
    {
        return SanaeiIntegrationMode::MODERN === $this->getEffectiveIntegrationMode();
    }

    public function getDetectedIntegrationMode(): ?SanaeiIntegrationMode
    {
        return null === $this->sanaeiDetectedMode ? null : SanaeiIntegrationMode::tryFrom($this->sanaeiDetectedMode);
    }

    public function getProbedAt(): ?\DateTimeImmutable
    {
        return $this->sanaeiProbedAt;
    }

    public function getProbeStatus(): ?string
    {
        return $this->sanaeiProbeStatus;
    }

    /**
     * Stores a capability probe. A probe that could not decide (panel down, bad token) keeps the previously detected
     * mode: a temporarily unreachable panel must not silently switch to another API.
     */
    public function recordProbe(?SanaeiIntegrationMode $detected, string $status, \DateTimeImmutable $at): self
    {
        if (SanaeiIntegrationMode::LEGACY === $detected || SanaeiIntegrationMode::MODERN === $detected) {
            $this->sanaeiDetectedMode = $detected->value;
        }
        $this->sanaeiProbeStatus = mb_substr($status, 0, 64);
        $this->sanaeiProbedAt = $at;

        return $this;
    }

    public function getApiVersion(): string
    {
        $apiVersion = strtolower(trim((string) ($this->getConfigValue('api_version') ?? '')));

        return in_array($apiVersion, ['legacy', 'v3'], true) ? $apiVersion : 'legacy';
    }

    public function setApiVersion(?string $apiVersion): self
    {
        $normalized = strtolower(trim((string) ($apiVersion ?? '')));
        if (!in_array($normalized, ['legacy', 'v3'], true)) {
            $normalized = 'legacy';
        }
        $this->setConfigValue('api_version', $normalized);

        return $this;
    }

    public function getAuthMode(): string
    {
        $authMode = strtolower(trim((string) ($this->getConfigValue('auth_mode') ?? '')));

        return in_array($authMode, ['cookie', 'bearer'], true) ? $authMode : 'cookie';
    }

    public function setAuthMode(?string $authMode): self
    {
        $normalized = strtolower(trim((string) ($authMode ?? '')));
        if (!in_array($normalized, ['cookie', 'bearer'], true)) {
            $normalized = 'cookie';
        }
        $this->setConfigValue('auth_mode', $normalized);

        return $this;
    }

    public function getClientApiMode(): string
    {
        $mode = strtolower(trim((string) ($this->getConfigValue('client_api_mode') ?? '')));

        return in_array($mode, ['inbound_legacy', 'clients'], true) ? $mode : 'inbound_legacy';
    }

    public function setClientApiMode(?string $mode): self
    {
        $normalized = strtolower(trim((string) ($mode ?? '')));
        if (!in_array($normalized, ['inbound_legacy', 'clients'], true)) {
            $normalized = 'inbound_legacy';
        }
        $this->setConfigValue('client_api_mode', $normalized);

        return $this;
    }

    public function getBasePath(): ?string
    {
        $value = trim((string) ($this->getConfigValue('base_path') ?? ''));

        return '' === $value ? null : $this->normalizeSlashPrefixedPath($value);
    }

    public function setBasePath(?string $basePath): self
    {
        $value = trim((string) ($basePath ?? ''));
        if ('' === $value) {
            $this->setConfigValue('base_path', null);

            return $this;
        }

        $this->setConfigValue('base_path', $this->normalizeSlashPrefixedPath($value));

        return $this;
    }

    public function getSubscriptionPathPrefix(): ?string
    {
        $value = trim((string) ($this->getConfigValue('subscription_path_prefix') ?? ''));

        return '' === $value ? null : $this->normalizeSlashPrefixedPath($value);
    }

    public function setSubscriptionPathPrefix(?string $prefix): self
    {
        $value = trim((string) ($prefix ?? ''));
        if ('' === $value) {
            $this->setConfigValue('subscription_path_prefix', null);

            return $this;
        }

        $this->setConfigValue('subscription_path_prefix', $this->normalizeSlashPrefixedPath($value));

        return $this;
    }

    public function isUseProxy(): bool
    {
        return true === ($this->getConfigValue('use_proxy') ?? false);
    }

    public function setUseProxy(bool $useProxy): self
    {
        $this->setConfigValue('use_proxy', $useProxy);

        return $this;
    }

    public function isApiTokenConfigured(): bool
    {
        return null !== $this->plainApiToken || null !== $this->getStoredApiToken();
    }

    /** base64 SHA-256 of the panel certificate's public key, for a self-signed panel (null = normal CA verification). */
    public function getTlsPinSha256(): ?string
    {
        $pin = trim((string) ($this->getConfigValue('tls_pin_sha256') ?? ''));

        return '' === $pin ? null : $pin;
    }

    public function setTlsPinSha256(?string $pin): self
    {
        $pin = trim((string) $pin);
        if (str_starts_with(strtolower($pin), 'sha256//')) {
            $pin = substr($pin, 8);
        }
        if ('' !== $pin && 1 !== preg_match('#^[A-Za-z0-9+/]{43}=$#', $pin)) {
            throw new \InvalidArgumentException('The TLS pin must be the base64 SHA-256 of the certificate public key (44 characters ending in "=").');
        }
        $this->setConfigValue('tls_pin_sha256', '' === $pin ? null : $pin);

        return $this;
    }

    public function isDegraded(): bool
    {
        return null !== $this->degradedSince;
    }

    public function getDegradedSince(): ?\DateTimeImmutable
    {
        return $this->degradedSince;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function recordCallSuccess(): self
    {
        $this->consecutiveFailures = 0;
        $this->degradedSince = null;

        return $this;
    }

    /** @return bool true when this failure made the panel degraded */
    public function recordCallFailure(\DateTimeImmutable $at): bool
    {
        ++$this->consecutiveFailures;
        if (null === $this->degradedSince && $this->consecutiveFailures >= self::DEGRADED_AFTER_FAILURES) {
            $this->degradedSince = $at;

            return true;
        }

        return false;
    }

    /** Whether new purchases may use this panel. */
    public function isAvailableForSale(): bool
    {
        return $this->isActive && !$this->isDegraded();
    }

    public function getApiTokenConfiguredLabel(): string
    {
        return $this->isApiTokenConfigured() ? 'yes' : 'no';
    }

    public function getLastTestResultSummary(): ?string
    {
        $config = is_array($this->config) ? $this->config : [];
        $entry = $config['last_test_result'] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $status = strtoupper(trim((string) ($entry['status'] ?? '')));
        $message = trim((string) ($entry['message'] ?? ''));
        $checkedAt = trim((string) ($entry['checked_at'] ?? ''));

        if ('' === $status && '' === $message && '' === $checkedAt) {
            return null;
        }

        $parts = array_values(array_filter([$status, $message, $checkedAt], static fn (string $part): bool => '' !== $part));

        return [] === $parts ? null : implode(' | ', $parts);
    }

    public function setLastTestResult(?string $status, ?string $message = null): self
    {
        $normalizedStatus = strtoupper(trim((string) ($status ?? '')));
        if ('' === $normalizedStatus) {
            $this->setConfigValue('last_test_result', null);

            return $this;
        }

        $entry = [
            'status' => $normalizedStatus,
            'message' => trim((string) ($message ?? '')),
            'checked_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        $this->setConfigValue('last_test_result', $entry);

        return $this;
    }

    private function getConfigValue(string $key): mixed
    {
        $config = is_array($this->config) ? $this->config : [];

        return $config[$key] ?? null;
    }

    private function setConfigValue(string $key, mixed $value): void
    {
        $config = is_array($this->config) ? $this->config : [];
        $shouldUnset = false;
        if (null === $value) {
            $shouldUnset = true;
        } elseif (is_string($value) && '' === trim($value)) {
            $shouldUnset = true;
        }

        if ($shouldUnset) {
            unset($config[$key]);
        } else {
            $config[$key] = $value;
        }

        $this->config = $config;
    }

    private function normalizeSlashPrefixedPath(string $value): string
    {
        return '/'.ltrim(trim($value), '/');
    }
}
