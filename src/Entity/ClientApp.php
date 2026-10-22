<?php

declare(strict_types=1);

namespace App\Entity;

use App\Subscription\Domain\ClientPlatform;
use App\Subscription\Domain\ImportLinkTemplate;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A VPN client app offered on the subscription page: "Install app" (a download linked to it, else its store URL) and
 * "Add subscription" (the import deep link built from importUrlTemplate, see ImportLinkTemplate).
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_client_app_active_sort', columns: ['is_active', 'sort_order'])]
class ClientApp implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /** @var list<string> ClientPlatform values */
    #[ORM\Column(type: 'json')]
    #[Assert\Count(min: 1, minMessage: 'client_app.platforms.required')]
    #[Assert\All([new Assert\Choice(callback: [self::class, 'platformValues'])])]
    private array $platforms = [];

    /** Null: no deep link (the page offers install + copy link). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $importUrlTemplate = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    private ?string $storeUrl = null;

    /** A short symbol (emoji) shown before the name; no image files. */
    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\Length(max: 16)]
    private ?string $icon = null;

    /** For admins: where the scheme was verified, or why the app is inactive. Never shown to customers. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private int $sortOrder = 100;

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

    /**
     * @return list<string>
     */
    public static function platformValues(): array
    {
        return array_map(static fn (ClientPlatform $p): string => $p->value, ClientPlatform::cases());
    }

    #[Assert\Callback]
    public function validateTemplate(ExecutionContextInterface $context): void
    {
        $problem = ImportLinkTemplate::problem($this->importUrlTemplate);
        if (null !== $problem) {
            $context->buildViolation($problem)->atPath('importUrlTemplate')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getPlatforms(): array
    {
        return $this->platforms;
    }

    /**
     * @param array<array-key, string> $platforms
     */
    public function setPlatforms(array $platforms): self
    {
        // Admin order kept, duplicates dropped.
        $this->platforms = array_values(array_unique(array_map('strval', $platforms)));

        return $this;
    }

    public function supports(ClientPlatform $platform): bool
    {
        return in_array($platform->value, $this->platforms, true);
    }

    public function getImportUrlTemplate(): ?string
    {
        return $this->importUrlTemplate;
    }

    public function setImportUrlTemplate(?string $importUrlTemplate): self
    {
        $importUrlTemplate = trim((string) $importUrlTemplate);
        $this->importUrlTemplate = '' === $importUrlTemplate ? null : $importUrlTemplate;

        return $this;
    }

    public function getStoreUrl(): ?string
    {
        return $this->storeUrl;
    }

    public function setStoreUrl(?string $storeUrl): self
    {
        $storeUrl = trim((string) $storeUrl);
        $this->storeUrl = '' === $storeUrl ? null : $storeUrl;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $icon = trim((string) $icon);
        $this->icon = '' === $icon ? null : $icon;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

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
        return $this->name;
    }
}
