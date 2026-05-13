<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\Order;
use App\Shop\Domain\OrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
            ->add(ChoiceFilter::new('status')->setChoices(AdminStatusBadge::choices(OrderStatus::ALL)));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user')->setLabel('admin.users'),
            AssociationField::new('plan')->setLabel('admin.plans'),
            AssociationField::new('targetService')->setLabel('admin.vpn_services'),
            TextField::new('type')->setLabel('Type'),
            TextField::new('trackingCode')->setLabel('Tracking Code'),
            IntegerField::new('amount')->setLabel('Amount'),
            ChoiceField::new('status')
                ->setChoices(AdminStatusBadge::choices(OrderStatus::ALL))
                ->renderAsBadges(AdminStatusBadge::badgeMap()),
            TextareaField::new('metadata')
                ->setLabel('Metadata')
                ->formatValue(static fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('paidAt')->setLabel('Paid At'),
            DateTimeField::new('provisionedAt')->setLabel('Provisioned At'),
        ];
    }
}
