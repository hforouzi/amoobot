<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\BotMessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

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
            ->setDefaultSort(['updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = Crud::PAGE_EDIT === $pageName;

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('key')->setLabel('Key')->setFormTypeOption('disabled', $isEdit),
            ChoiceField::new('locale')->setChoices(['fa' => 'fa', 'en' => 'en'])->setFormTypeOption('disabled', $isEdit),
            TextField::new('category')->setLabel('Category')->setFormTypeOption('disabled', $isEdit),
            TextField::new('title')->setLabel('Title'),
            TextareaField::new('body')->setLabel('Body')->hideOnIndex(),
            ChoiceField::new('parseMode')->setChoices(['HTML' => 'html', 'Markdown' => 'markdown', 'Plain' => 'plain']),
            TextareaField::new('variablesJson')
                ->setLabel('Variables')
                ->setFormTypeOption('disabled', true)
                ->hideOnIndex(),
            BooleanField::new('isActive')->setLabel('Active'),
            BooleanField::new('isSystem')->setLabel('System')->hideOnForm(),
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
