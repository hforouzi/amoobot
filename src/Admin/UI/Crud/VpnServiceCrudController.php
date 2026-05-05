<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnService;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class VpnServiceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnService::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user'),
            AssociationField::new('order'),
            AssociationField::new('panel'),
            TextField::new('remoteId'),
            TextField::new('username'),
            TextareaField::new('subscriptionUrl')->hideOnIndex(),
            TextareaField::new('configText')->hideOnIndex(),
            TextField::new('status'),
            DateTimeField::new('startsAt'),
            DateTimeField::new('expiresAt'),
            IntegerField::new('trafficLimitGb'),
            IntegerField::new('trafficUsedGb'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
