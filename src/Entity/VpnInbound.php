<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class VpnInbound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VpnPanel $panel;

    #[ORM\Column(length: 128)]
    private string $remoteInboundId;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $remark = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $protocol = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $network = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $security = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $host = null;

    #[ORM\Column(nullable: true)]
    private ?int $port = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sni = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostHeader = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shortId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $spiderX = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $flow = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serviceName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alpn = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAccessMetadataSyncedAt = null;

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

    public function getPanel(): VpnPanel
    {
        return $this->panel;
    }

    public function setPanel(VpnPanel $panel): self
    {
        $this->panel = $panel;

        return $this;
    }

    public function getRemoteInboundId(): string
    {
        return $this->remoteInboundId;
    }

    public function setRemoteInboundId(string $remoteInboundId): self
    {
        $this->remoteInboundId = $remoteInboundId;

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

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function setRemark(?string $remark): self
    {
        $this->remark = $remark;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function getProtocol(): ?string
    {
        return $this->protocol;
    }

    public function setProtocol(mixed $protocol): self
    {
        $this->protocol = $this->normalizeNullableString($protocol);

        return $this;
    }

    public function getNetwork(): ?string
    {
        return $this->network;
    }

    public function setNetwork(mixed $network): self
    {
        $this->network = $this->normalizeNullableString($network);

        return $this;
    }

    public function getSecurity(): ?string
    {
        return $this->security;
    }

    public function setSecurity(mixed $security): self
    {
        $this->security = $this->normalizeNullableString($security);

        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(mixed $host): self
    {
        $this->host = $this->normalizeNullableString($host);

        return $this;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(mixed $port): self
    {
        $this->port = $this->normalizeNullableInt($port);

        return $this;
    }

    public function getSni(): ?string
    {
        return $this->sni;
    }

    public function setSni(mixed $sni): self
    {
        $this->sni = $this->normalizeNullableString($sni);

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(mixed $path): self
    {
        $this->path = $this->normalizeNullableString($path);

        return $this;
    }

    public function getHostHeader(): ?string
    {
        return $this->hostHeader;
    }

    public function setHostHeader(mixed $hostHeader): self
    {
        $this->hostHeader = $this->normalizeNullableString($hostHeader);

        return $this;
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function setPublicKey(mixed $publicKey): self
    {
        $this->publicKey = $this->normalizeNullableString($publicKey);

        return $this;
    }

    public function getShortId(): ?string
    {
        return $this->shortId;
    }

    public function setShortId(mixed $shortId): self
    {
        $this->shortId = $this->normalizeNullableString($shortId);

        return $this;
    }

    public function getSpiderX(): ?string
    {
        return $this->spiderX;
    }

    public function setSpiderX(mixed $spiderX): self
    {
        $this->spiderX = $this->normalizeNullableString($spiderX);

        return $this;
    }

    public function getFlow(): ?string
    {
        return $this->flow;
    }

    public function setFlow(mixed $flow): self
    {
        $this->flow = $this->normalizeNullableString($flow);

        return $this;
    }

    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }

    public function setServiceName(mixed $serviceName): self
    {
        $this->serviceName = $this->normalizeNullableString($serviceName);

        return $this;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(mixed $fingerprint): self
    {
        $this->fingerprint = $this->normalizeNullableString($fingerprint);

        return $this;
    }

    public function getAlpn(): ?string
    {
        return $this->alpn;
    }

    public function setAlpn(mixed $alpn): self
    {
        $this->alpn = $this->normalizeNullableString($alpn);

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

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): self
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getLastAccessMetadataSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastAccessMetadataSyncedAt;
    }

    public function setLastAccessMetadataSyncedAt(?\DateTimeImmutable $lastAccessMetadataSyncedAt): self
    {
        $this->lastAccessMetadataSyncedAt = $lastAccessMetadataSyncedAt;

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
        $country = trim((string) ($this->country ?? ''));
        $title = isset($this->title) ? trim($this->title) : '';
        $protocol = trim((string) ($this->protocol ?? ''));

        return sprintf(
            '%s - %s - %s - #%s',
            '' !== $country ? $country : 'N/A',
            '' !== $title ? $title : 'Inbound',
            '' !== $protocol ? $protocol : 'unknown',
            $this->remoteInboundId ?: '-'
        );
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $scalar = $this->extractFirstScalar($value);
        if (null === $scalar) {
            return null;
        }

        $text = trim((string) $scalar);

        return '' === $text ? null : $text;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        $scalar = $this->extractFirstScalar($value);
        if (null === $scalar || is_bool($scalar) || !is_numeric($scalar)) {
            return null;
        }

        $intValue = (int) $scalar;

        return $intValue >= 1 && $intValue <= 65535 ? $intValue : null;
    }

    private function extractFirstScalar(mixed $value): string|int|float|bool|null
    {
        if (null === $value) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $scalar = $this->extractFirstScalar($item);
                if (null !== $scalar) {
                    return $scalar;
                }
            }

            return null;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $text = trim((string) $value);

            return '' === $text ? null : $text;
        }

        return null;
    }
}
