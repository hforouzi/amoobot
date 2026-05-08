<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Form\Type\JsonTextareaType;
use App\Admin\UI\Crud\Support\JsonFieldFormatter;
use App\Entity\VpnPanel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class VpnPanelCrudController extends AbstractCrudController
{
    private const CONFIG_HELP_TEXT = <<<'TEXT'
JSON example:
{
  "subscription_base_url": "https://panel.example.com",
  "remark_prefix": "amoobot"
}
TEXT;

    public static function getEntityFqcn(): string
    {
        return VpnPanel::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $testConnection = Action::new('testConnection', 'تست اتصال')
            ->linkToRoute('admin_vpn_panel_test_connection', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        $syncInbounds = Action::new('syncInbounds', 'همگامسازی اینباندها')
            ->linkToRoute('admin_vpn_panel_sync_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        $viewInbounds = Action::new('viewInbounds', 'مشاهده اینباندها')
            ->linkToRoute('admin_vpn_panel_view_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-info')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        return $actions
            ->add(Action::INDEX, $testConnection)
            ->add(Action::INDEX, $syncInbounds)
            ->add(Action::INDEX, $viewInbounds)
            ->add(Action::DETAIL, $testConnection)
            ->add(Action::DETAIL, $syncInbounds)
            ->add(Action::DETAIL, $viewInbounds);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            ChoiceField::new('type')
                ->setChoices([
                    'dummy' => 'dummy',
                    'sanaei_3xui' => 'sanaei_3xui',
                ]),
            TextField::new('title'),
            TextField::new('baseUrl'),
            TextField::new('username'),
            TextField::new('password')->hideOnIndex(),
            TextareaField::new('apiToken')->hideOnIndex(),
            TextareaField::new('config')
                ->setFormType(JsonTextareaType::class)
                ->formatValue(static fn (mixed $value): string => JsonFieldFormatter::format($value))
                ->setFormTypeOption('attr.rows', 18)
                ->setHelp(self::CONFIG_HELP_TEXT),
            BooleanField::new('isActive'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
