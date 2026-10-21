<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Field\JsonField;
use App\Admin\UI\Support\AdminFormat;
use App\Admin\UI\Support\AdminStatusBadge;
use App\Admin\UI\DashboardController;
use App\Entity\Order;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly AdminFormat $format,
        private readonly TranslatorInterface $translator,
    ) {
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

    /**
     * List: customer, plan, type, amount (store currency), status, when. Tracking code, renewal target, metadata
     * and the paid / provisioned times are on the detail page.
     */
    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;

        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user', 'field.customer'),
            AssociationField::new('plan'),
            TextField::new('type')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => AdminFormat::e($this->translator->trans('order.type.'.$v, [], 'admin'))),
            IntegerField::new('amount')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->money($v)),
            TextField::new('status')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->status($v)),
            DateTimeField::new('createdAt')->hideOnForm(),

            // Detail page and form.
            TextField::new('trackingCode')->hideOnIndex(),
            AssociationField::new('targetService')->setLabel('admin.vpn_services')->hideOnIndex(),
            JsonField::new('metadata')->hideOnIndex(),
            DateTimeField::new('paidAt')->hideOnIndex(),
            DateTimeField::new('provisionedAt')->hideOnIndex(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // The list's customer and plan columns, in the same query (no query per row).
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.user', 'listUser')->addSelect('listUser')
            ->leftJoin('entity.plan', 'listPlan')->addSelect('listPlan');
    }
}
