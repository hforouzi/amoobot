<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnPanel;
use App\Provisioning\Application\ModernModeGuard;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Contracts\Translation\TranslatorInterface;

class VpnPanelCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ModernModeGuard $modernModeGuard,
        private readonly TranslatorInterface $translator,
    ) {
    }

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
            TextField::new('plainPassword', 'Password')
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('always_empty', true)
                ->setFormTypeOption('required', false)
                ->onlyOnForms(),
            TextField::new('passwordConfiguredLabel', 'Password configured')
                ->hideOnForm(),
            TextField::new('plainApiToken', 'API Token')
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('always_empty', true)
                ->setFormTypeOption('required', false)
                ->setHelp('help.panel_bearer_token')
                ->onlyOnForms(),
            TextField::new('apiTokenConfiguredLabel', 'Token configured')
                ->hideOnForm(),
            TextField::new('lastTestResultSummary', 'Last test result')
                ->hideOnForm(),
            CodeEditorField::new('config')
                ->setCustomOption(CodeEditorField::OPTION_LANGUAGE, 'json')
                ->formatValue(static fn (mixed $value): string => self::formatJsonConfig($value))
                ->setHelp('Example: {"api_version":"v3","auth_mode":"bearer","subscription_base_url":"https://sub.example.com:8443","subscription_path_prefix":"/rain","base_path":"/xui"}')
                ->hideOnForm()
                ->hideOnIndex(),
            BooleanField::new('isActive'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof VpnPanel) {
            $this->enforceModeGuard($entityInstance, SanaeiIntegrationMode::AUTO->value);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof VpnPanel) {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
            $this->enforceModeGuard($entityInstance, (string) ($original['sanaeiIntegrationMode'] ?? SanaeiIntegrationMode::AUTO->value));
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * A refused switch to "modern" keeps the previous mode; the rest of the form is saved. Flash messages are
     * rendered as raw HTML by EasyAdmin, so the text is escaped.
     */
    private function enforceModeGuard(VpnPanel $panel, string $previousMode): void
    {
        $requested = $panel->getIntegrationMode();
        if ($requested->value === $previousMode) {
            return;
        }
        $refusal = $this->modernModeGuard->refusal($panel, $requested);
        if (null === $refusal) {
            return;
        }
        $panel->setIntegrationMode($previousMode);
        $this->addFlash('danger', htmlspecialchars(
            $this->translator->trans($refusal['key'], $refusal['params'], 'admin').' '.$this->translator->trans('panel_connection.guard.mode_kept', [], 'admin'),
            ENT_QUOTES | ENT_SUBSTITUTE,
        ));
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

            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $text = trim((string) $value);
        if ('' === $text) {
            return '';
        }

        $decoded = json_decode($text, true);
        if (JSON_ERROR_NONE === json_last_error()) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $text;
        }

        return $text;
    }
}
