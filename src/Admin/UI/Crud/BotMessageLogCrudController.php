<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Field\JsonField;
use App\Entity\BotMessageLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Logs page, "bot message log" tab (AdminTabs); was /admin/bot-message-log (redirects here).
 */
#[AdminRoute(path: '/logs/bot-messages', name: 'logs_bot_messages')]
class BotMessageLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BotMessageLog::class;
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
            TextField::new('telegramId'),
            TextField::new('direction'),
            TextField::new('updateType'),
            JsonField::new('payload'),
            DateTimeField::new('createdAt')->hideOnForm(),
        ];
    }
}
