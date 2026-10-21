<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Field\JsonField;
use App\Admin\UI\Support\AdminStatusBadge;
use App\Admin\UI\DashboardController;
use App\Entity\Order;
use App\Shop\Domain\OrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

class OrderCrudController extends AbstractCrudController
{
    public function __construct(private readonly AdminUrlGeneratorInterface $adminUrlGenerator)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    /**
     * Order drafts (the bot's unfinished purchase flows) are reached from here, not from the menu.
     */
    public function configureActions(Actions $actions): Actions
    {
        $drafts = Action::new('orderDrafts', 'admin.order_drafts', 'fa fa-list-alt')
            ->linkToUrl(fn (): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setDashboard(DashboardController::class)
                ->setController(OrderDraftCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl())
            ->createAsGlobalAction();

        return $actions->add(Crud::PAGE_INDEX, $drafts);
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
            TextField::new('status')
                ->formatValue(static fn (mixed $value): string => AdminStatusBadge::html($value))
                ->renderAsHtml(),
            JsonField::new('metadata')->setLabel('Metadata'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('paidAt')->setLabel('Paid At'),
            DateTimeField::new('provisionedAt')->setLabel('Provisioned At'),
        ];
    }
}
