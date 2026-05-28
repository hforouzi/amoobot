<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\TrialPlan;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class TrialPlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TrialPlan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'Title'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
            BooleanField::new('isActive', 'Active'),
            IntegerField::new('sortOrder', 'Sort order'),
            IntegerField::new('durationDays', 'Duration (days)'),
            IntegerField::new('trafficGb', 'Traffic (GB)'),
            IntegerField::new('ipLimit', 'IP limit')
                ->setHelp('Maximum simultaneous IPs/devices. Empty means panel/default/unlimited.'),
            AssociationField::new('inbound', 'اینباند / سرور'),
            IntegerField::new('maxClaimsTotal', 'Max claims total')
                ->setHelp('Empty means unlimited.')
                ->hideOnIndex(),
            IntegerField::new('maxClaimsPerUser', 'Max claims per user'),
            IntegerField::new('cooldownHours', 'Cooldown hours')
                ->setHelp('Optional cooldown between attempts.')
                ->hideOnIndex(),
            AssociationField::new('backingPlan', 'Backing plan')
                ->hideOnForm()
                ->hideOnIndex(),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
