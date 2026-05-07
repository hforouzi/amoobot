<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnInbound;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class VpnInboundCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnInbound::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('panel'),
            TextField::new('remoteInboundId'),
            TextField::new('title'),
            TextField::new('remark')->hideOnIndex(),
            TextField::new('country'),
            TextField::new('location')->hideOnIndex(),
            TextField::new('protocol'),
            TextField::new('network'),
            TextField::new('security'),
            BooleanField::new('isActive'),
            ArrayField::new('config')->hideOnIndex(),
            DateTimeField::new('lastSyncedAt'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
