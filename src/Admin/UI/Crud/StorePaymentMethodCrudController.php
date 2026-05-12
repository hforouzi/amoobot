<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\StorePaymentMethod;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class StorePaymentMethodCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return StorePaymentMethod::class;
    }

    public function createEntity(string $entityFqcn): object
    {
        return (new StorePaymentMethod())
            ->setIsActive(true)
            ->setSortOrder(0)
            ->setCurrency('IRR');
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof StorePaymentMethod) {
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof StorePaymentMethod) {
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            AssociationField::new('gateway'),
            BooleanField::new('isActive'),
            IntegerField::new('sortOrder'),
            IntegerField::new('minAmount'),
            IntegerField::new('maxAmount'),
            TextField::new('currency'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
