<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class AdminUserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AdminUser::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Admin user')
            ->setEntityLabelInPlural('کاربران ادمین')
            ->setEntityPermission(AdminCrudPermissions::view(self::class))
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $plainPassword = TextField::new('plainPassword', 'Password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions([
                'required' => Crud::PAGE_NEW === $pageName,
                'always_empty' => true,
                'constraints' => Crud::PAGE_NEW === $pageName ? [new NotBlank()] : [],
            ])
            ->onlyOnForms();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('email'),
            TextField::new('username')->setRequired(false),
            TextField::new('displayName')->setRequired(false),
            TextField::new('telegramUserId', 'Telegram user id')
                ->setRequired(false)
                ->setHelp('Numeric Telegram id (not the @username). The admin then has the same permissions in the bot; empty = no bot access (except TELEGRAM_ADMIN_CHAT_ID).'),
            ChoiceField::new('roles')
                ->setChoices([
                    'ROLE_SUPER_ADMIN' => AdminUser::ROLE_SUPER_ADMIN,
                    'ROLE_ADMIN' => AdminUser::ROLE_ADMIN,
                    'ROLE_SUPPORT' => AdminUser::ROLE_SUPPORT,
                ])
                ->allowMultipleChoices(),
            BooleanField::new('isActive'),
            $plainPassword,
            DateTimeField::new('lastLoginAt')->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AdminUser) {
            $this->hashPlainPassword($entityInstance);
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AdminUser) {
            $this->preventLosingLastActiveSuperAdmin($entityManager, $entityInstance);
            $this->hashPlainPassword($entityInstance);
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AdminUser && $this->isActiveSuperAdmin($entityInstance) && $this->countActiveSuperAdmins($entityManager) <= 1) {
            throw new \RuntimeException('Cannot delete the last active ROLE_SUPER_ADMIN.');
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function hashPlainPassword(AdminUser $adminUser): void
    {
        $plainPassword = trim((string) $adminUser->getPlainPassword());
        if ('' === $plainPassword) {
            return;
        }

        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, $plainPassword));
        $adminUser->eraseCredentials();
    }

    private function preventLosingLastActiveSuperAdmin(EntityManagerInterface $entityManager, AdminUser $adminUser): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $original = $unitOfWork->getOriginalEntityData($adminUser);
        $wasActive = true === ($original['isActive'] ?? false);
        $wasSuperAdmin = in_array(AdminUser::ROLE_SUPER_ADMIN, (array) ($original['roles'] ?? []), true);

        if (!$wasActive || !$wasSuperAdmin || $this->isActiveSuperAdmin($adminUser)) {
            return;
        }

        if (0 === $this->countActiveSuperAdmins($entityManager, $adminUser->getId())) {
            throw new \RuntimeException('Cannot disable or remove ROLE_SUPER_ADMIN from the last active super admin.');
        }
    }

    private function countActiveSuperAdmins(EntityManagerInterface $entityManager, ?int $excludeId = null): int
    {
        $count = 0;
        foreach ($entityManager->getRepository(AdminUser::class)->findAll() as $user) {
            if (!$user instanceof AdminUser) {
                continue;
            }

            if (null !== $excludeId && $user->getId() === $excludeId) {
                continue;
            }

            if ($this->isActiveSuperAdmin($user)) {
                ++$count;
            }
        }

        return $count;
    }

    private function isActiveSuperAdmin(AdminUser $adminUser): bool
    {
        return $adminUser->isActive() && in_array(AdminUser::ROLE_SUPER_ADMIN, $adminUser->getRoles(), true);
    }
}
