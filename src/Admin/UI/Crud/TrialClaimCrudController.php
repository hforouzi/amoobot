<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\TrialClaim;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

final class TrialClaimCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TrialClaim::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('telegramAccount'))
            ->add(EntityFilter::new('trialPlan'))
            ->add(ChoiceFilter::new('status')->setChoices(AdminStatusBadge::choices([
                TrialClaim::STATUS_PENDING,
                TrialClaim::STATUS_PROVISIONED,
                TrialClaim::STATUS_FAILED,
            ])));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('telegramAccount', 'Telegram user'),
            AssociationField::new('trialPlan', 'Trial plan'),
            AssociationField::new('order', 'Order'),
            AssociationField::new('vpnService', 'VPN service'),
            TextField::new('status')
                ->formatValue(static fn (mixed $value): string => AdminStatusBadge::html($value))
                ->renderAsHtml(),
            TextareaField::new('failureReason', 'Failure reason')->hideOnIndex(),
            DateTimeField::new('createdAt')->setLabel('common.created_at'),
            DateTimeField::new('provisionedAt', 'Provisioned at'),
        ];
    }
}
