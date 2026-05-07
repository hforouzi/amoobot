<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnPanel;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class VpnPanelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnPanel::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('type'),
            TextField::new('title'),
            TextField::new('baseUrl'),
            TextField::new('username'),
            TextField::new('password')->hideOnIndex(),
            TextareaField::new('apiToken')->hideOnIndex(),
            ArrayField::new('config'),
            BooleanField::new('isActive'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
