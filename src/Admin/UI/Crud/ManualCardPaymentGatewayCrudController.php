<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class ManualCardPaymentGatewayCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Action::INDEX, Action::DETAIL);
    }

    public function createEntity(string $entityFqcn): object
    {
        return (new PaymentGateway())
            ->setType(PaymentGatewayType::MANUAL_CARD)
            ->setCurrency('IRR')
            ->setIsActive(true);
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $entityInstance
                ->setType(PaymentGatewayType::MANUAL_CARD)
                ->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $entityInstance
                ->setType(PaymentGatewayType::MANUAL_CARD)
                ->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addFieldset('fieldset.general'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            TextareaField::new('description')->hideOnIndex(),
            BooleanField::new('isActive')->setLabel('enabled'),
            TextField::new('currency'),
            TextField::new('manualCardNumber')->setLabel('card_number'),
            TextField::new('manualCardHolder')->setLabel('card_holder'),
            TextField::new('manualBankName')->setLabel('bank_name'),
            TextareaField::new('manualInstructions')->setLabel('instructions'),
            FormField::addFieldset('fieldset.metadata'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $qb->andWhere('entity.type = :gatewayType')
            ->setParameter('gatewayType', PaymentGatewayType::MANUAL_CARD);

        return $qb;
    }
}
