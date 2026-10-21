<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Field\JsonField;
use App\Entity\ServiceNotificationLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Logs page, "notification log" tab (AdminTabs); was /admin/service-notification-log (redirects here).
 */
#[AdminRoute(path: '/logs/notifications', name: 'logs_notifications')]
class ServiceNotificationLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServiceNotificationLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission(AdminCrudPermissions::view(self::class))
            ->overrideTemplate('crud/index', 'admin/crud/index_tabs.html.twig');
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
            JsonField::new('payload'),
        ];
    }
}
