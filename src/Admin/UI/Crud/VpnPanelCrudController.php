<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnPanel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class VpnPanelCrudController extends AbstractCrudController
{
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
            ChoiceField::new('apiVersion', 'API Version')
                ->setChoices([
                    'legacy' => 'legacy',
                    'v3' => 'v3',
                ])
                ->setHelp('legacy for old Sanaei panels, v3 for 3x-ui v3+ API'),
            ChoiceField::new('authMode', 'Auth Mode')
                ->setChoices([
                    'cookie' => 'cookie',
                    'bearer' => 'bearer',
                ]),
            TextField::new('basePath', 'Base Path')
                ->setHelp('Optional panel path prefix, e.g. /xui'),
            TextField::new('subscriptionPathPrefix', 'Subscription Path Prefix')
                ->setHelp('Used with subscription base URL to build subscription URL.'),
            TextField::new('subscriptionBaseUrl')
                ->setHelp('For Sanaei subscriptions, prefer config.subscription_base_url and config.subscription_path_prefix.'),
            TextField::new('publicHost')
                ->setHelp('Used as fallback host for generated single links when inbound/external proxy host is missing.'),
            TextField::new('username'),
            TextField::new('password')->hideOnIndex(),
            TextField::new('apiToken', 'API Token')
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('always_empty', false)
                ->setHelp('For 3x-ui v3+, use Settings → Security → API Token and auth_mode=bearer.')
                ->hideOnIndex(),
            TextField::new('apiTokenConfiguredLabel', 'Token configured')
                ->hideOnForm(),
            TextField::new('lastTestResultSummary', 'Last test result')
                ->hideOnForm(),
            Field::new('config')
                ->formatValue(static fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                ->setHelp('Example: {"api_version":"v3","auth_mode":"bearer","subscription_base_url":"https://sub.example.com:8443","subscription_path_prefix":"/rain","base_path":"/xui"}')
                ->hideOnForm()
                ->hideOnIndex(),
            BooleanField::new('isActive'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
