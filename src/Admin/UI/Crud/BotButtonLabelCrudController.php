<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\BotButtonLabel;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Bot texts page, "button labels" tab (AdminTabs); was /admin/bot-button-label (redirects here).
 */
#[AdminRoute(path: '/bot-texts/buttons', name: 'bot_texts_buttons')]
final class BotButtonLabelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BotButtonLabel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.bot_button_label')
            ->setEntityLabelInPlural('admin.bot_button_labels')
            ->setDefaultSort(['updatedAt' => 'DESC', 'id' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/crud/index_tabs.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = Crud::PAGE_EDIT === $pageName;

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('key')->setFormTypeOption('disabled', $isEdit),
            ChoiceField::new('locale')->setChoices(['locale.fa' => 'fa', 'locale.en' => 'en'])->setFormTypeOption('disabled', $isEdit),
            TextField::new('label'),
            ChoiceField::new('buttonType')
                ->setChoices(['bot_text.button_type.reply_keyboard' => 'reply_keyboard', 'bot_text.button_type.inline' => 'inline', 'bot_text.button_type.command' => 'command', 'bot_text.button_type.system' => 'system'])
                ->setFormTypeOption('disabled', $isEdit),
            TextField::new('category')->setFormTypeOption('disabled', true),
            BooleanField::new('isActive'),
            BooleanField::new('isSystem')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->onlyOnIndex(),
        ];
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof BotButtonLabel) {
            $entityInstance->touch();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
