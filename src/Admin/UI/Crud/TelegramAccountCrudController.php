<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\TelegramAccount;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TelegramAccountCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TelegramAccount::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user'),
            TextField::new('telegramId'),
            TextField::new('username'),
            TextField::new('firstName'),
            TextField::new('lastName'),
            TextField::new('languageCode'),
            DateTimeField::new('lastActivityAt'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
