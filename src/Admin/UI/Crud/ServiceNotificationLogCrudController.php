<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminJsonFormatter;
use App\Entity\ServiceNotificationLog;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ServiceNotificationLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServiceNotificationLog::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('service'),
            AssociationField::new('user'),
            TextField::new('type'),
            TextField::new('keyName'),
            DateTimeField::new('sentAt')->hideOnForm(),
            TextareaField::new('payload')
                ->formatValue(static fn (mixed $value): string => AdminJsonFormatter::toPrettyHtml($value))
                ->renderAsHtml()
                ->hideOnIndex()
                ->hideOnForm(),
        ];
    }
}
