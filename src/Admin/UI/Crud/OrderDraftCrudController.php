<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\OrderDraft;
use App\Shop\Domain\OrderDraftStatus;
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

final class OrderDraftCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrderDraft::class;
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
                OrderDraftStatus::PENDING => OrderDraftStatus::PENDING,
                OrderDraftStatus::AWAITING_USERNAME => OrderDraftStatus::AWAITING_USERNAME,
                OrderDraftStatus::AWAITING_TRAFFIC => OrderDraftStatus::AWAITING_TRAFFIC,
                OrderDraftStatus::AWAITING_DURATION => OrderDraftStatus::AWAITING_DURATION,
                OrderDraftStatus::AWAITING_DISCOUNT_CHOICE => OrderDraftStatus::AWAITING_DISCOUNT_CHOICE,
                OrderDraftStatus::AWAITING_DISCOUNT_CODE => OrderDraftStatus::AWAITING_DISCOUNT_CODE,
                OrderDraftStatus::AWAITING_PAYMENT_METHOD => OrderDraftStatus::AWAITING_PAYMENT_METHOD,
                OrderDraftStatus::CONFIRMED => OrderDraftStatus::CONFIRMED,
                OrderDraftStatus::CANCELLED => OrderDraftStatus::CANCELLED,
                OrderDraftStatus::EXPIRED => OrderDraftStatus::EXPIRED,
            ]));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user'),
            AssociationField::new('plan'),
            TextField::new('status'),
            TextField::new('step'),
            TextField::new('finalUsername'),
            IntegerField::new('trafficGb'),
            IntegerField::new('durationDays'),
            IntegerField::new('calculatedAmount'),
            IntegerField::new('finalAmount'),
            TextareaField::new('data')
                ->formatValue(static function (mixed $value): string {
                    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    return false === $encoded ? '' : $encoded;
                })
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt'),
            DateTimeField::new('expiresAt'),
        ];
    }
}
