<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
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
        $testConnection = Action::new('testConnection', 'vpn_panel.actions.test_legacy_connection')
            ->linkToRoute('admin_vpn_panel_test_connection', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType() && SanaeiIntegrationMode::MODERN !== $panel->getSanaeiIntegrationMode());

        $testModernConnection = Action::new('testModernConnection', 'vpn_panel.actions.test_modern_connection')
            ->linkToRoute('admin_vpn_panel_test_modern_connection', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType() && SanaeiIntegrationMode::MODERN === $panel->getSanaeiIntegrationMode());

        $syncInbounds = Action::new('syncInbounds', 'همگامسازی اینباندها')
            ->linkToRoute('admin_vpn_panel_sync_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        $viewInbounds = Action::new('viewInbounds', 'مشاهده اینباندها')
            ->linkToRoute('admin_vpn_panel_view_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-info')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        return $actions
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, static fn (Action $action): Action => $action->setLabel('vpn_panel.actions.save_changes'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, static fn (Action $action): Action => $action->setLabel('vpn_panel.actions.save_changes'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, static fn (Action $action): Action => $action->setLabel('vpn_panel.actions.save_and_continue'))
            ->add(Action::INDEX, $testConnection)
            ->add(Action::INDEX, $testModernConnection)
            ->add(Action::INDEX, $syncInbounds)
            ->add(Action::INDEX, $viewInbounds)
            ->add(Action::DETAIL, $testConnection)
            ->add(Action::DETAIL, $testModernConnection)
            ->add(Action::DETAIL, $syncInbounds)
            ->add(Action::DETAIL, $viewInbounds);
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addJsFile('assets/admin/vpn-panel-form.js');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),

            FormField::addFieldset('vpn_panel.sections.type_mode', 'fa fa-server')
                ->addCssClass('vpn-panel-form-card vpn-panel-type-mode-card'),
            ChoiceField::new('type', 'vpn_panel.fields.type')
                ->setChoices([
                    'dummy' => 'dummy',
                    'sanaei_3xui' => 'sanaei_3xui',
                ])
                ->setHelp('vpn_panel.help.type')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-type-field')
                ->setFormTypeOption('attr', ['data-vpn-panel-role' => 'type']),
            ChoiceField::new('sanaeiIntegrationMode', 'vpn_panel.fields.sanaei_integration')
                ->setChoices([
                    'vpn_panel.integration.legacy.label' => SanaeiIntegrationMode::LEGACY,
                    'vpn_panel.integration.modern.label' => SanaeiIntegrationMode::MODERN,
                ])
                ->setFormTypeOption('expanded', true)
                ->setFormTypeOption('choice_attr', static fn (mixed $choice): array => match ($choice) {
                    SanaeiIntegrationMode::MODERN => ['data-description' => 'vpn_panel.integration.modern.description'],
                    default => ['data-description' => 'vpn_panel.integration.legacy.description'],
                })
                ->setHelp('vpn_panel.help.sanaei_integration')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-sanaei vpn-panel-mode-choice')
                ->setFormTypeOption('attr', ['data-vpn-panel-role' => 'integration-mode']),

            FormField::addFieldset('vpn_panel.sections.connection', 'fa fa-plug')
                ->addCssClass('vpn-panel-form-card vpn-panel-connection-card'),
            TextField::new('title', 'vpn_panel.fields.title')
                ->setHelp('vpn_panel.help.title')
                ->setColumns('col-md-6')
                ->setFormTypeOption('required', true)
                ->addCssClass('vpn-panel-field vpn-panel-common-field'),
            TextField::new('baseUrl', 'vpn_panel.fields.base_url')
                ->setHelp('vpn_panel.help.base_url')
                ->setColumns('col-md-6')
                ->setFormTypeOption('required', true)
                ->setFormTypeOption('attr', ['placeholder' => 'https://panel.example.com:2053'])
                ->addCssClass('vpn-panel-field vpn-panel-common-field'),
            BooleanField::new('useProxy', 'vpn_panel.fields.use_proxy')
                ->setHelp('vpn_panel.help.use_proxy')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-switch-field vpn-panel-common-field'),

            FormField::addFieldset('vpn_panel.sections.modern_api', 'fa fa-key')
                ->setHelp('vpn_panel.help.modern_callout')
                ->addCssClass('vpn-panel-form-card vpn-panel-token-section vpn-panel-modern-card'),
            TextField::new('apiToken', 'vpn_panel.fields.api_token')
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('always_empty', true)
                ->setFormTypeOption('required', false)
                ->setHelp('vpn_panel.help.modern_api_token')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-token-section vpn-panel-secret-field')
                ->onlyOnForms(),

            FormField::addFieldset('vpn_panel.sections.legacy', 'fa fa-user-lock')
                ->setHelp('vpn_panel.help.legacy_callout')
                ->addCssClass('vpn-panel-form-card vpn-panel-mode-legacy vpn-panel-legacy-card'),
            ChoiceField::new('authMode', 'vpn_panel.fields.auth_mode')
                ->setChoices([
                    'vpn_panel.auth.cookie.label' => 'cookie',
                    'vpn_panel.auth.bearer.label' => 'bearer',
                ])
                ->setFormTypeOption('expanded', true)
                ->setFormTypeOption('choice_attr', static fn (mixed $choice, mixed $key, mixed $value): array => match ($value) {
                    'bearer' => ['data-description' => 'vpn_panel.auth.bearer.description'],
                    default => ['data-description' => 'vpn_panel.auth.cookie.description'],
                })
                ->setHelp('vpn_panel.help.auth_mode')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-auth-mode-choice')
                ->setFormTypeOption('attr', ['data-vpn-panel-role' => 'auth-mode']),
            TextField::new('username', 'vpn_panel.fields.username')
                ->setHelp('vpn_panel.help.legacy_username')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-auth-cookie'),
            TextField::new('password', 'vpn_panel.fields.password')
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('always_empty', true)
                ->setFormTypeOption('required', false)
                ->setHelp('vpn_panel.help.legacy_password')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-auth-cookie vpn-panel-secret-field')
                ->onlyOnForms(),
            FormField::addFieldset('vpn_panel.sections.advanced', 'fa fa-sliders')
                ->renderCollapsed()
                ->addCssClass('vpn-panel-form-card vpn-panel-advanced-card vpn-panel-mode-sanaei'),
            ChoiceField::new('apiVersion', 'vpn_panel.fields.api_version')
                ->setChoices([
                    'legacy' => 'legacy',
                    'v3' => 'v3',
                ])
                ->setHelp('vpn_panel.help.api_version')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-advanced-field'),
            ChoiceField::new('clientApiMode', 'vpn_panel.fields.client_api_mode')
                ->setChoices([
                    'inbound_legacy' => 'inbound_legacy',
                    'clients' => 'clients',
                ])
                ->setHelp('vpn_panel.help.client_api_mode')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-advanced-field'),
            TextField::new('basePath', 'vpn_panel.fields.base_path')
                ->setHelp('vpn_panel.help.base_path')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-sanaei vpn-panel-advanced-field'),
            TextField::new('subscriptionPathPrefix', 'vpn_panel.fields.subscription_path_prefix')
                ->setHelp('vpn_panel.help.subscription_path_prefix')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-advanced-field'),
            TextField::new('subscriptionBaseUrl', 'vpn_panel.fields.subscription_base_url')
                ->setHelp('vpn_panel.help.subscription_base_url')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-advanced-field'),
            TextField::new('publicHost', 'vpn_panel.fields.public_host')
                ->setHelp('vpn_panel.help.public_host')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-mode-legacy vpn-panel-advanced-field'),
            TextField::new('passwordConfiguredLabel', 'vpn_panel.fields.password_configured')
                ->hideOnForm(),
            TextField::new('apiTokenConfiguredLabel', 'vpn_panel.fields.token_configured')
                ->hideOnForm(),
            TextField::new('lastTestResultSummary', 'vpn_panel.fields.last_test_result')
                ->hideOnForm(),
            CodeEditorField::new('config')
                ->setCustomOption(CodeEditorField::OPTION_LANGUAGE, 'json')
                ->formatValue(static fn (mixed $value): string => self::formatJsonConfig($value))
                ->setHelp('vpn_panel.help.config')
                ->hideOnForm()
                ->hideOnIndex(),

            FormField::addFieldset('vpn_panel.sections.status', 'fa fa-toggle-on')
                ->addCssClass('vpn-panel-form-card vpn-panel-status-card'),
            BooleanField::new('isActive', 'vpn_panel.fields.is_active')
                ->setHelp('vpn_panel.help.is_active')
                ->setColumns('col-md-6')
                ->addCssClass('vpn-panel-field vpn-panel-switch-field'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }

    private static function formatJsonConfig(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_array($value)) {
            if ([] === $value) {
                return '';
            }

            $value = self::redactSensitiveConfig($value);

            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $text = trim((string) $value);
        if ('' === $text) {
            return '';
        }

        $decoded = json_decode($text, true);
        if (JSON_ERROR_NONE === json_last_error()) {
            $decoded = self::redactSensitiveConfig($decoded);

            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $text;
        }

        return $text;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function redactSensitiveConfig(array $config): array
    {
        $sensitiveKeys = ['api_token', 'apiToken', 'modern_api_token', 'modernApiToken', 'token', 'password', 'authorization'];
        foreach ($config as $key => $value) {
            if (is_string($key) && in_array($key, $sensitiveKeys, true)) {
                $config[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $config[$key] = self::redactSensitiveConfig($value);
            }
        }

        return $config;
    }
}
