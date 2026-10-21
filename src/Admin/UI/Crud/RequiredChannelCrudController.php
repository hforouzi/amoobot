<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\RequiredChannel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
class RequiredChannelCrudController extends AbstractCrudController
{
    /** The list is the "required channels" tab of the settings page; create/edit/detail stay here. */
    public function index(AdminContext $context): Response
    {
        return $this->redirectToRoute('admin_settings', ['tab' => 'channels']);
    }

    public static function getEntityFqcn(): string
    {
        return RequiredChannel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(AdminCrudPermissions::view(self::class));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            TextField::new('chatId')
                ->setHelp('help.required_channel.chat_id'),
            TextField::new('inviteUrl')
                ->setHelp('help.required_channel.invite_url'),
            BooleanField::new('isActive'),
            BooleanField::new('requireForPurchase'),
            BooleanField::new('requireForTrial'),
            IntegerField::new('sortOrder'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
