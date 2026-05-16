<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\BotButtonLabel;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

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
            ->setDefaultSort(['updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = Crud::PAGE_EDIT === $pageName;

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('key')->setLabel('Key')->setFormTypeOption('disabled', $isEdit),
            ChoiceField::new('locale')->setChoices(['fa' => 'fa', 'en' => 'en'])->setFormTypeOption('disabled', $isEdit),
            TextField::new('label')->setLabel('Label'),
            ChoiceField::new('buttonType')
                ->setChoices(['Reply keyboard' => 'reply_keyboard', 'Inline' => 'inline', 'Command' => 'command', 'System' => 'system'])
                ->setFormTypeOption('disabled', $isEdit),
            TextField::new('category')->setLabel('Category')->setFormTypeOption('disabled', true),
            BooleanField::new('isActive')->setLabel('Active'),
            BooleanField::new('isSystem')->setLabel('System')->hideOnForm(),
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
