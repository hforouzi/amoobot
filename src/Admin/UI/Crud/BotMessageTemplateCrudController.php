<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\BotMessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Bot texts page, "message templates" tab (AdminTabs); was /admin/bot-message-template (redirects here).
 */
#[AdminRoute(path: '/bot-texts/messages', name: 'bot_texts_messages')]
final class BotMessageTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BotMessageTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.bot_message_template')
            ->setEntityLabelInPlural('admin.bot_message_templates')
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
            TextField::new('category')->setFormTypeOption('disabled', $isEdit),
            TextField::new('title'),
            TextareaField::new('body')->hideOnIndex(),
            ChoiceField::new('parseMode')->setChoices(['bot_text.parse_mode.html' => 'html', 'bot_text.parse_mode.markdown' => 'markdown', 'bot_text.parse_mode.plain' => 'plain']),
            TextareaField::new('variablesJson')
                
                ->setFormTypeOption('disabled', true)
                ->hideOnIndex(),
            BooleanField::new('isActive'),
            BooleanField::new('isSystem')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->onlyOnIndex(),
        ];
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof BotMessageTemplate) {
            $entityInstance->touch();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
