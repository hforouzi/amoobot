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
    private string $title;

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

    public function setProtocol(?string $protocol): self
    {
        $this->protocol = $protocol;

        return $this;
    }

    public function getNetwork(): ?string
    {
        return $this->network;
    }

    public function setNetwork(?string $network): self
    {
        $this->network = $network;

        return $this;
    }

    public function getSecurity(): ?string
    {
        return $this->security;
    }

    public function setSecurity(?string $security): self
    {
        $this->security = $security;

        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(?int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function getSni(): ?string
    {
        return $this->sni;
    }

    public function setSni(?string $sni): self
    {
        $this->sni = $sni;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getHostHeader(): ?string
    {
        return $this->hostHeader;
    }

    public function setHostHeader(?string $hostHeader): self
    {
        $this->hostHeader = $hostHeader;

        return $this;
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function setPublicKey(?string $publicKey): self
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    public function getShortId(): ?string
    {
        return $this->shortId;
    }

    public function setShortId(?string $shortId): self
    {
        $this->shortId = $shortId;

        return $this;
    }

    public function getSpiderX(): ?string
    {
        return $this->spiderX;
    }

    public function setSpiderX(?string $spiderX): self
    {
        $this->spiderX = $spiderX;

        return $this;
    }

    public function getFlow(): ?string
    {
        return $this->flow;
    }

    public function setFlow(?string $flow): self
    {
        $this->flow = $flow;

        return $this;
    }

    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }

    public function setServiceName(?string $serviceName): self
    {
        $this->serviceName = $serviceName;

        return $this;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(?string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getAlpn(): ?string
    {
        return $this->alpn;
    }

    public function setAlpn(?string $alpn): self
    {
        $this->alpn = $alpn;

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
        $title = trim((string) $this->title);
        $protocol = trim((string) ($this->protocol ?? ''));

        return sprintf(
            '%s - %s - %s - #%s',
            '' !== $country ? $country : 'N/A',
            '' !== $title ? $title : 'Inbound',
            '' !== $protocol ? $protocol : 'unknown',
            $this->remoteInboundId ?: '-'
        );
    }
}
