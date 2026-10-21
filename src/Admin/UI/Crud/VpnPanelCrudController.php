<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Field\JsonField;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
class VpnPanelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnPanel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(AdminCrudPermissions::view(self::class));
    }

    public function configureActions(Actions $actions): Actions
    {
        // Panels are added, edited and tested in the guided setup (PanelSetupController, PanelConnectionType); this
        // controller keeps the list and the detail page.
        $testConnection = Action::new('testConnection', 'panel_connection.steps.connect')
            ->linkToRoute('admin_panel_setup_show', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        $syncInbounds = Action::new('syncInbounds', 'همگامسازی اینباندها')
            ->linkToRoute('admin_vpn_panel_sync_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType() && !$panel->isModern());

        $viewInbounds = Action::new('viewInbounds', 'مشاهده اینباندها')
            ->linkToRoute('admin_vpn_panel_view_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-info')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType() && !$panel->isModern());

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action): Action => $action->linkToRoute('admin_panel_setup_new'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action): Action => $action->linkToRoute('admin_panel_setup_edit', static fn (VpnPanel $panel): array => ['id' => $panel->getId()]))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, static fn (Action $action): Action => $action->linkToRoute('admin_panel_setup_edit', static fn (VpnPanel $panel): array => ['id' => $panel->getId()]))
            ->add(Action::INDEX, $testConnection)
            ->add(Action::INDEX, $syncInbounds)
            ->add(Action::INDEX, $viewInbounds)
            ->add(Action::DETAIL, $testConnection)
            ->add(Action::DETAIL, $syncInbounds)
            ->add(Action::DETAIL, $viewInbounds);
    }

    public function configureFields(string $pageName): iterable
    {
        $instance = $this->getContext()?->getEntity()->getInstance();
        // Modern panels use the API token only: the old credential and API-variant fields are not shown for them.
        $legacyOnly = !($instance instanceof VpnPanel && $instance->isModern());
        $fields = [
            IdField::new('id')->onlyOnIndex(),
            ChoiceField::new('type')
                ->setChoices([
                    'dummy' => 'dummy',
                    'sanaei_3xui' => 'sanaei_3xui',
                ]),
            TextField::new('title'),
            TextField::new('baseUrl'),
            ChoiceField::new('integrationModeValue', 'panel_connection.integration_mode')
                ->setChoices([
                    'panel_connection.mode.auto' => SanaeiIntegrationMode::AUTO->value,
                    'panel_connection.mode.legacy' => SanaeiIntegrationMode::LEGACY->value,
                    'panel_connection.mode.modern' => SanaeiIntegrationMode::MODERN->value,
                ])
                ->setHelp('panel_connection.integration_mode_help'),
            BooleanField::new('useProxy', 'Use proxy')
                ->setHelp('Use the PANEL_PROXY_* proxy from .env.local for this panel. If disabled, this panel connects directly.'),
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
            ChoiceField::new('clientApiMode', 'Client API Mode')
                ->setChoices([
                    'inbound_legacy' => 'inbound_legacy',
                    'clients' => 'clients',
                ])
                ->setHelp('inbound_legacy uses /panel/api/inbounds/addClient. clients uses /panel/api/clients/add for newer 3x-ui v3 panels.'),
            TextField::new('basePath', 'Base Path')
                ->setHelp('Optional panel path prefix, e.g. /xui'),
            TextField::new('subscriptionPathPrefix', 'Subscription Path Prefix')
                ->setHelp('Used with subscription base URL to build subscription URL.'),
            TextField::new('subscriptionBaseUrl')
                ->setHelp('For Sanaei subscriptions, prefer config.subscription_base_url and config.subscription_path_prefix.'),
            TextField::new('publicHost')
                ->setHelp('Used as fallback host for generated single links when inbound/external proxy host is missing.'),
            TextField::new('username'),
            TextField::new('passwordConfiguredLabel', 'Password configured')
                ->hideOnForm(),
            TextField::new('apiTokenConfiguredLabel', 'Token configured')
                ->hideOnForm(),
            TextField::new('lastTestResultSummary', 'Last test result')
                ->hideOnForm(),
            JsonField::new('config'),
            BooleanField::new('isActive'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];

        if (!$legacyOnly) {
            $fields = array_values(array_filter($fields, static fn (object $field): bool => !in_array($field->getAsDto()->getProperty(), ['apiVersion', 'authMode', 'clientApiMode', 'basePath', 'username', 'passwordConfiguredLabel'], true)));
        }

        return $fields;
    }

    /**
     * The one panel form is the guided one: /admin/vpn-panel/new and /admin/vpn-panel/{id}/edit lead there.
     */
    public function new(AdminContext $context): KeyValueStore|Response
    {
        return $this->redirectToRoute('admin_panel_setup_new');
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        return $this->redirectToRoute('admin_panel_setup_edit', ['id' => $context->getEntity()->getPrimaryKeyValue()]);
    }
}
