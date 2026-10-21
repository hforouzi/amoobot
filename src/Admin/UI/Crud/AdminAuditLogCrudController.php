<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Field\JsonField;
use App\Entity\AdminAuditLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only admin audit trail (AdminAuditLogger): who did what, from the web, the bot or the console.
 *
 * Logs page, "audit log" tab (AdminTabs); was /admin/admin-audit-log (redirects here).
 */
#[AdminRoute(path: '/logs/audit', name: 'logs_audit')]
final class AdminAuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AdminAuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.audit_log_entry')
            ->setEntityLabelInPlural('admin.audit_log')
            ->setEntityPermission(AdminCrudPermissions::view(self::class))
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['action', 'actorLabel', 'targetType', 'targetId'])
            ->overrideTemplate('crud/index', 'admin/crud/index_tabs.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('action')->add('source')->add('targetType')->add('actorLabel')->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            DateTimeField::new('createdAt', 'common.created_at'),
            TextField::new('source', 'audit.source'),
            TextField::new('actorLabel', 'audit.actor'),
            IntegerField::new('actorAdminUserId', 'audit.actor_id')->hideOnIndex(),
            TextField::new('action', 'audit.action'),
            TextField::new('targetType', 'audit.target_type'),
            TextField::new('targetId', 'audit.target_id'),
            JsonField::new('details', 'audit.details'),
        ];
    }
}
