<?php

declare(strict_types=1);

namespace App\Entity;

use App\Subscription\Application\StoredFile;
use App\Subscription\Domain\ClientPlatform;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A client app installer customers can download from the subscription page and the bot: an uploaded file (kept
 * outside public/, served by /d/{id}, see AppDownloadStorage) or a link to an external page (http(s) only).
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_app_download_active_platform', columns: ['is_active', 'platform', 'sort_order'])]
class AppDownload implements \Stringable
{
    public const TYPE_FILE = 'file';
    public const TYPE_URL = 'url';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $title = '';

    #[ORM\Column(length: 16)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ClientApp::class, 'platformValues'])]
    private string $platform = '';

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $version = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $description = null;

    #[ORM\Column(length: 8)]
    #[Assert\Choice(choices: [self::TYPE_FILE, self::TYPE_URL])]
    private string $type = self::TYPE_FILE;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    private ?string $externalUrl = null;

    /** Random name of the file in the downloads directory (32 hex characters); null for a link. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $storedName = null;

    /** Sanitized name the file is downloaded as. */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    /** Optional: the app this installs, so its "Install app" button on the subscription page leads here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ClientApp $clientApp = null;

    #[ORM\Column]
    private int $sortOrder = 100;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $downloadCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateSource(ExecutionContextInterface $context): void
    {
        if (self::TYPE_URL === $this->type && null === $this->externalUrl) {
            $context->buildViolation('app_download.external_url.required')->atPath('externalUrl')->addViolation();
        }
    }

    public function isFile(): bool
    {
        return self::TYPE_FILE === $this->type;
    }

    public function attachStoredFile(StoredFile $file): self
    {
        $this->type = self::TYPE_FILE;
        $this->storedName = $file->storedName;
        $this->originalName = $file->originalName;
        $this->mimeType = $file->mimeType;
        $this->sizeBytes = $file->sizeBytes;
        $this->sha256 = $file->sha256;

        return $this;
    }

    /** Forget the stored file (it was deleted, or the download became a link). */
    public function detachStoredFile(): self
    {
        $this->storedName = null;
        $this->originalName = null;
        $this->mimeType = null;
        $this->sizeBytes = null;
        $this->sha256 = null;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getPlatformEnum(): ?ClientPlatform
    {
        return ClientPlatform::tryFrom($this->platform);
    }

    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): self
    {
        $version = trim((string) $version);
        $this->version = '' === $version ? null : $version;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $description = trim((string) $description);
        $this->description = '' === $description ? null : $description;

        return $this;
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

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): self
    {
        $externalUrl = trim((string) $externalUrl);
        $this->externalUrl = '' === $externalUrl ? null : $externalUrl;

        return $this;
    }

    public function getStoredName(): ?string
    {
        return $this->storedName;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): ?int
    {
        return null === $this->sizeBytes ? null : (int) $this->sizeBytes;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getClientApp(): ?ClientApp
    {
        return $this->clientApp;
    }

    public function setClientApp(?ClientApp $clientApp): self
    {
        $this->clientApp = $clientApp;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
