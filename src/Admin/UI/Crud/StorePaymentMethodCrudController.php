<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\StorePaymentMethod;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
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
            FormField::addFieldset('fieldset.store_payment_method'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title')->setLabel('Title')
                ->setHelp('help.payment_gateway_store_method'),
            AssociationField::new('gateway')->setLabel('admin.payment_gateways')
                ->setHelp('help.payment_gateway_store_method'),
            BooleanField::new('isActive')->setLabel('common.enabled'),
            IntegerField::new('sortOrder')->setLabel('Sort Order'),
            IntegerField::new('minAmount')->setLabel('Min Amount'),
            IntegerField::new('maxAmount')->setLabel('Max Amount'),
            TextField::new('currency')->setLabel('Currency'),
            FormField::addFieldset('fieldset.metadata'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
