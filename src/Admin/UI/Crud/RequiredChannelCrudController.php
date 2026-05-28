<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\RequiredChannel;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class RequiredChannelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RequiredChannel::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'Title'),
            TextField::new('chatId', 'Chat ID')
                ->setHelp('Numeric chat id like -1001234567890 or public username like @my_channel.'),
            TextField::new('inviteUrl', 'Invite URL')
                ->setHelp('Used for the Join channel button. Leave empty if unavailable.'),
            BooleanField::new('isActive', 'Active'),
            BooleanField::new('requireForPurchase', 'Require for purchase'),
            BooleanField::new('requireForTrial', 'Require for trial'),
            IntegerField::new('sortOrder', 'Sort order'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
