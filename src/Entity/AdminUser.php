<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'admin_user')]
#[ORM\UniqueConstraint(name: 'uniq_admin_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_admin_user_username', columns: ['username'])]
#[UniqueEntity(fields: ['email'])]
#[UniqueEntity(fields: ['username'], ignoreNull: true)]
class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_SUPPORT = 'ROLE_SUPPORT';

    public const SUPPORTED_ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_ADMIN,
        self::ROLE_SUPPORT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $displayName = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [self::ROLE_ADMIN];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    private ?string $plainPassword = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $username = null === $username ? null : trim($username);
        $this->username = '' === $username ? null : $username;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $displayName = null === $displayName ? null : trim($displayName);
        $this->displayName = '' === $displayName ? null : $displayName;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = array_values(array_filter($this->roles, static fn (string $role): bool => '' !== trim($role)));
        $roles[] = self::ROLE_ADMIN;

        return array_values(array_unique($roles));
    }

    /**
     * The roles given to this admin, without the ROLE_ADMIN that getRoles() always adds for the /admin access rule.
     * Permissions (AdminRolePermissions) are decided from these.
     *
     * @return list<string>
     */
    public function getAssignedRoles(): array
    {
        return array_values(array_filter($this->roles, static fn (string $role): bool => in_array($role, self::SUPPORTED_ROLES, true)));
    }

    /**
     * Compared on every request with the row reloaded from the database: a deactivated admin, or one whose password,
     * email or roles changed, is logged out at once (not only at the next login).
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->isActive()
            && $user->getId() === $this->id
            && $user->getUserIdentifier() === $this->getUserIdentifier()
            && $user->getPassword() === $this->password
            && $user->getAssignedRoles() === $this->getAssignedRoles();
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values(array_unique(array_filter($roles, static fn (string $role): bool => in_array($role, self::SUPPORTED_ROLES, true))));
        if ([] === $this->roles) {
            $this->roles = [self::ROLE_ADMIN];
        }

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
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

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;

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

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function __toString(): string
    {
        return $this->displayName ?: $this->email;
    }
}
