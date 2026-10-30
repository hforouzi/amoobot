<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Field\JsonField;
use App\Admin\UI\Support\AdminFormat;
use App\Admin\UI\Support\PanelHealth;
use App\Admin\UI\TranslatedFlashes;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\Catalog\PlanInboundUsage;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
/**
 * VPN panels. A panel's inbounds are listed on its detail page (and on the panel setup page) instead of a menu
 * entry of their own; the inbound list stays reachable from there, filtered on the panel.
 */
class VpnPanelCrudController extends AbstractCrudController
{
    use TranslatedFlashes;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminFormat $format,
        private readonly TranslatorInterface $translator,
        private readonly PlanInboundUsage $inboundUsage,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnPanel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission(AdminCrudPermissions::view(self::class))
            ->overrideTemplate('crud/detail', 'admin/vpn_panel/detail.html.twig');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $panel = $this->getContext()?->getEntity()->getInstance();
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName') && $panel instanceof VpnPanel) {
            $responseParameters->set('panel_inbounds', self::inbounds($this->entityManager, $panel));
        }

        return $responseParameters;
    }

    /**
     * @return list<VpnInbound>
     */
    /**
     * Deleting a panel deletes its inbounds (ON DELETE CASCADE): refused, naming the plans, while one of them is an
     * additional inbound of a plan (that key is RESTRICT), instead of the database's error.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof VpnPanel) {
            $ids = array_map(static fn (VpnInbound $i): int => (int) $i->getId(), self::inbounds($entityManager, $entityInstance));
            $problem = $this->inboundUsage->deletionProblem($ids, 'flash.panel.used_by_plans');
            if (null !== $problem) {
                $this->flash('danger', $problem['key'], $problem['parameters']);

                return;
            }
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    public static function inbounds(EntityManagerInterface $entityManager, VpnPanel $panel): array
    {
        return $entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel], ['id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Panels are added, edited and tested in the guided setup (PanelSetupController, PanelConnectionType); this
        // controller keeps the list and the detail page.
        $testConnection = Action::new('testConnection', 'panel_connection.steps.connect')
            ->linkToRoute('admin_panel_setup_show', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType());

        $syncInbounds = Action::new('syncInbounds', 'action.sync_inbounds')
            ->linkToRoute('admin_vpn_panel_sync_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (VpnPanel $panel): bool => 'sanaei_3xui' === $panel->getType() && !$panel->isModern());

        // Every panel: its inbounds are not in the menu any more.
        $viewInbounds = Action::new('viewInbounds', 'action.view_inbounds')
            ->linkToRoute('admin_vpn_panel_view_inbounds', fn (VpnPanel $panel): array => ['id' => $panel->getId()])
            ->setCssClass('btn btn-info');

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

    /**
     * List: title, connection state, active switch, last check, host. The URL is shown as its host only (a panel's
     * path is a secret); the legacy API settings are on the detail page, in their own section, for legacy panels.
     */
    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;
        $instance = $this->getContext()?->getEntity()->getInstance();
        $legacy = !($instance instanceof VpnPanel && $instance->isModern());

        $fields = [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            AdminFormat::field('health', function (mixed $v, ?VpnPanel $panel): string {
                if (null === $panel) {
                    return '';
                }
                $state = PanelHealth::of($panel);

                return sprintf('<span class="badge badge-%s" data-health="%s">%s</span>', PanelHealth::badge($state), $state, AdminFormat::e($this->translator->trans('panel.health.'.$state, [], 'admin')));
            }),
            BooleanField::new('isActive'),
            AdminFormat::field('probedAt', static fn (mixed $v): string => $f->relative($v), 'field.last_check'),
            AdminFormat::field('host', static fn (mixed $v, ?VpnPanel $panel): string => $f->host($panel?->getBaseUrl())),

            // Detail page.
            ChoiceField::new('type')->hideOnIndex()->setChoices(['panel.type.dummy' => 'dummy', 'panel.type.sanaei_3xui' => 'sanaei_3xui']),
            TextField::new('baseUrl')->hideOnIndex(),
            ChoiceField::new('integrationModeValue', 'panel_connection.integration_mode')->hideOnIndex()
                ->setChoices([
                    'panel_connection.mode.auto' => SanaeiIntegrationMode::AUTO->value,
                    'panel_connection.mode.legacy' => SanaeiIntegrationMode::LEGACY->value,
                    'panel_connection.mode.modern' => SanaeiIntegrationMode::MODERN->value,
                ])
                ->setHelp('panel_connection.integration_mode_help'),
            BooleanField::new('useProxy')->hideOnIndex()->setHelp('help.vpn_panel.use_proxy'),
            TextField::new('apiTokenConfiguredLabel')->hideOnIndex()->hideOnForm()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->yesNo('yes' === $v)),
            TextField::new('lastTestResultSummary')->hideOnIndex()->hideOnForm(),
            FormField::addFieldset('panel.subscription_section')->hideOnIndex(),
            TextField::new('subscriptionBaseUrl')->hideOnIndex()->setHelp('help.vpn_panel.subscription_base_url'),
            TextField::new('subscriptionPathPrefix')->hideOnIndex()->setHelp('help.vpn_panel.subscription_path_prefix'),
            TextField::new('publicHost')->hideOnIndex()->setHelp('help.vpn_panel.public_host'),
            JsonField::new('config')->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnIndex()->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnIndex()->hideOnForm(),
        ];

        if ($legacy) {
            array_push(
                $fields,
                FormField::addFieldset('panel.legacy_section')->hideOnIndex()->setHelp('panel.legacy_section_help'),
                ChoiceField::new('apiVersion')->hideOnIndex()->setChoices(['panel.api_version.legacy' => 'legacy', 'panel.api_version.v3' => 'v3'])->setHelp('help.vpn_panel.api_version'),
                ChoiceField::new('authMode')->hideOnIndex()->setChoices(['panel.auth_mode.cookie' => 'cookie', 'panel.auth_mode.bearer' => 'bearer']),
                ChoiceField::new('clientApiMode')->hideOnIndex()->setChoices(['panel.client_api_mode.inbound_legacy' => 'inbound_legacy', 'panel.client_api_mode.clients' => 'clients'])->setHelp('help.vpn_panel.client_api_mode'),
                TextField::new('basePath')->hideOnIndex()->setHelp('help.vpn_panel.base_path'),
                TextField::new('username')->hideOnIndex(),
                TextField::new('passwordConfiguredLabel')->hideOnIndex()->hideOnForm()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->yesNo('yes' === $v)),
            );
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
        // The list's active switch (EasyAdmin's PATCH of one boolean field).
        if ($context->getRequest()->isXmlHttpRequest() && $context->getRequest()->isMethod('PATCH')) {
            return parent::edit($context);
        }

        return $this->redirectToRoute('admin_panel_setup_edit', ['id' => $context->getEntity()->getPrimaryKeyValue()]);
    }
}
