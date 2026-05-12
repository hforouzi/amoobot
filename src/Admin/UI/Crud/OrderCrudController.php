<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\Order;
use App\Shop\Domain\OrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user'))
            ->add(EntityFilter::new('plan'))
            ->add(ChoiceFilter::new('status')->setChoices([
                OrderStatus::DRAFT => OrderStatus::DRAFT,
                OrderStatus::PENDING => OrderStatus::PENDING,
                OrderStatus::WAITING_PAYMENT => OrderStatus::WAITING_PAYMENT,
                OrderStatus::PAYMENT_PENDING => OrderStatus::PAYMENT_PENDING,
                OrderStatus::PAID => OrderStatus::PAID,
                OrderStatus::PROCESSING => OrderStatus::PROCESSING,
                OrderStatus::COMPLETED => OrderStatus::COMPLETED,
                OrderStatus::PROVISIONED => OrderStatus::PROVISIONED,
                OrderStatus::CANCELLED => OrderStatus::CANCELLED,
                OrderStatus::EXPIRED => OrderStatus::EXPIRED,
                OrderStatus::FAILED => OrderStatus::FAILED,
            ]));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user'),
            AssociationField::new('plan'),
            AssociationField::new('targetService'),
            TextField::new('type'),
            IntegerField::new('amount'),
            TextField::new('status'),
            TextareaField::new('metadata')
                ->formatValue(static fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('paidAt'),
            DateTimeField::new('provisionedAt'),
        ];
    }
}
