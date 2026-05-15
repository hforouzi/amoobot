<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminJsonFormatter;
use App\Entity\BotMessageLog;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BotMessageLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BotMessageLog::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('telegramId'),
            TextField::new('direction'),
            TextField::new('updateType'),
            TextareaField::new('payload')
                ->formatValue(static fn (mixed $value): string => AdminJsonFormatter::toPrettyHtml($value))
                ->renderAsHtml()
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
        ];
    }
}
