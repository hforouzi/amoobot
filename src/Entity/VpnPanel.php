<?php

declare(strict_types=1);

namespace App\Entity;

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subscriptionBaseUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicHost = null;

    #[ORM\Column]
    private bool $isActive = true;

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

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getApiToken(): ?string
    {
        $token = trim((string) ($this->apiToken ?? ''));
        if ('' !== $token) {
            return $token;
        }

        $config = is_array($this->config) ? $this->config : [];
        $fallback = trim((string) ($config['api_token'] ?? ''));

        return '' === $fallback ? null : $fallback;
    }

    public function setApiToken(?string $apiToken): self
    {
        $token = trim((string) ($apiToken ?? ''));
        $this->apiToken = '' === $token ? null : $token;
        $this->setConfigValue('api_token', $this->apiToken);

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
        $this->setConfigValue('subscription_base_url', $this->subscriptionBaseUrl);

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

    public function isApiTokenConfigured(): bool
    {
        return '' !== trim((string) ($this->getApiToken() ?? ''));
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
