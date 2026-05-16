<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_bot_message_template_key_locale', columns: ['template_key', 'locale'])]
#[ORM\HasLifecycleCallbacks]
class BotMessageTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'template_key', length: 190)]
    private string $key = '';

    #[ORM\Column(length: 10)]
    private string $locale = 'fa';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(length: 20)]
    private string $parseMode = 'html';

    #[ORM\Column(nullable: true)]
    private ?array $variables = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $category = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isSystem = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = trim($key);

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = trim($locale) ?: 'fa';

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = null === $title ? null : trim($title);

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getParseMode(): string
    {
        return $this->parseMode;
    }

    public function setParseMode(string $parseMode): self
    {
        $this->parseMode = in_array($parseMode, ['html', 'markdown', 'plain'], true) ? $parseMode : 'html';

        return $this;
    }

    public function getVariables(): ?array
    {
        return $this->variables;
    }

    public function getVariablesJson(): string
    {
        return null === $this->variables ? '' : (string) json_encode($this->variables, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function setVariables(?array $variables): self
    {
        $this->variables = $variables;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = null === $category ? null : trim($category);

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

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;

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
}
