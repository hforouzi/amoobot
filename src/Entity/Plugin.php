<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'plugin')]
#[ORM\UniqueConstraint(name: 'uniq_plugin_code', columns: ['code'])]
#[ORM\Index(name: 'IDX_PLUGIN_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_PLUGIN_TYPE', columns: ['type'])]
class Plugin
{
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ERROR = 'error';

    public const TYPE_PAYMENT_GATEWAY = 'payment_gateway';
    /** A product module's row (docs/design/product-modules.md 4.2): built in only, never installable from a package. */
    public const TYPE_PRODUCT_MODULE = 'product_module';
    public const TYPE_VPN_DRIVER = 'vpn_driver';
    public const TYPE_SERVER_DRIVER = 'server_driver';

    /** Built into the application: no files, no main class, never installed, upgraded or uninstalled. */
    public const SOURCE_CORE = 'core';
    /** Installed from a ZIP into var/plugins/<code>. */
    public const SOURCE_PACKAGE = 'package';

    public const SOURCES = [self::SOURCE_CORE, self::SOURCE_PACKAGE];

    public const STATUSES = [
        self::STATUS_INSTALLED,
        self::STATUS_ENABLED,
        self::STATUS_DISABLED,
        self::STATUS_ERROR,
    ];

    public const TYPES = [
        self::TYPE_PAYMENT_GATEWAY,
        self::TYPE_PRODUCT_MODULE,
        self::TYPE_VPN_DRIVER,
        self::TYPE_SERVER_DRIVER,
    ];

    /** Driver plugin types and the module each one serves (<module>_driver). */
    public const DRIVER_TYPES = [
        self::TYPE_VPN_DRIVER => 'vpn',
        self::TYPE_SERVER_DRIVER => 'server',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    #[Assert\Regex('/^[a-z0-9_-]+$/')]
    private string $code = '';

    #[ORM\Column(length: 64)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = self::TYPE_PAYMENT_GATEWAY;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $name = [];

    #[ORM\Column(length: 64)]
    private string $version = '';

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $description = null;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_INSTALLED;

    #[ORM\Column(length: 16, options: ['default' => self::SOURCE_PACKAGE])]
    #[Assert\Choice(choices: self::SOURCES)]
    private string $source = self::SOURCE_PACKAGE;

    #[ORM\Column(length: 512)]
    private string $path = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mainClass = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $manifest = [];

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $permissions = null;

    #[ORM\Column]
    private \DateTimeImmutable $installedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enabledAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->installedAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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

    /**
     * @return array<string, string>
     */
    public function getName(): array
    {
        return $this->name;
    }

    /**
     * @param array<string, string> $name
     */
    public function setName(array $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDisplayName(string $locale = 'en'): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? $this->name['fa'] ?? $this->code;
    }

    public function getDescriptionText(string $locale = 'en'): string
    {
        if (!is_array($this->description)) {
            return '';
        }

        return $this->description[$locale] ?? $this->description['fa'] ?? $this->description['en'] ?? '';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getDescription(): ?array
    {
        return $this->description;
    }

    /**
     * @param array<string, string>|null $description
     */
    public function setDescription(?array $description): self
    {
        $this->description = $description;

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function isCore(): bool
    {
        return self::SOURCE_CORE === $this->source;
    }

    public function isDriver(): bool
    {
        return isset(self::DRIVER_TYPES[$this->type]);
    }

    /** The module a driver or module row belongs to: its own code for a module row, null for a payment gateway. */
    public function module(): ?string
    {
        return match (true) {
            self::TYPE_PRODUCT_MODULE === $this->type => $this->code,
            isset(self::DRIVER_TYPES[$this->type]) => self::DRIVER_TYPES[$this->type],
            default => null,
        };
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getMainClass(): ?string
    {
        return $this->mainClass;
    }

    public function setMainClass(?string $mainClass): self
    {
        $this->mainClass = $mainClass;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function setManifest(array $manifest): self
    {
        $this->manifest = $manifest;

        return $this;
    }

    public function getManifestJson(): string
    {
        return json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return list<string>|null
     */
    public function getPermissions(): ?array
    {
        return $this->permissions;
    }

    /**
     * @param list<string>|null $permissions
     */
    public function setPermissions(?array $permissions): self
    {
        $this->permissions = $permissions;

        return $this;
    }

    public function getPermissionsSummary(): string
    {
        $count = is_array($this->permissions) ? count($this->permissions) : 0;

        return sprintf('%d permissions', $count);
    }

    public function getPermissionsBadges(): string
    {
        if (!is_array($this->permissions) || [] === $this->permissions) {
            return '<span class="text-muted">-</span>';
        }

        return implode(' ', array_map(
            static fn (mixed $permission): string => sprintf(
                '<span class="badge badge-secondary">%s</span>',
                htmlspecialchars((string) $permission, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ),
            $this->permissions
        ));
    }

    public function getInstalledAt(): \DateTimeImmutable
    {
        return $this->installedAt;
    }

    public function setInstalledAt(\DateTimeImmutable $installedAt): self
    {
        $this->installedAt = $installedAt;

        return $this;
    }

    public function getEnabledAt(): ?\DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function setEnabledAt(?\DateTimeImmutable $enabledAt): self
    {
        $this->enabledAt = $enabledAt;

        return $this;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function setDisabledAt(?\DateTimeImmutable $disabledAt): self
    {
        $this->disabledAt = $disabledAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
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
        return sprintf('%s (%s)', $this->getDisplayName(), $this->code);
    }
}
