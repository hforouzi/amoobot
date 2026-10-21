<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Field\JsonField;
use App\Entity\VpnInbound;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
class VpnInboundCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnInbound::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(AdminCrudPermissions::view(self::class));
    }

    public function configureActions(Actions $actions): Actions
    {
        $testCreateClient = Action::new('testCreateClient', 'action.test_create_client')
            ->linkToRoute('admin_vpn_inbound_test_create_client', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        $resync = Action::new('resyncInbound', 'action.resync_inbound')
            ->linkToRoute('admin_vpn_inbound_resync', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        $syncAccessMetadata = Action::new('syncAccessMetadata', 'action.sync_access_metadata')
            ->linkToRoute('admin_vpn_inbound_sync_access_metadata', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-warning')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        $regenerateServiceConfigs = Action::new('regenerateServiceConfigs', 'action.regenerate_service_configs')
            ->linkToRoute('admin_vpn_inbound_regenerate_service_configs', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-info')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        return $actions
            ->add(Action::INDEX, $testCreateClient)
            ->add(Action::INDEX, $resync)
            ->add(Action::INDEX, $syncAccessMetadata)
            ->add(Action::INDEX, $regenerateServiceConfigs)
            ->add(Action::DETAIL, $testCreateClient)
            ->add(Action::DETAIL, $resync)
            ->add(Action::DETAIL, $syncAccessMetadata)
            ->add(Action::DETAIL, $regenerateServiceConfigs);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('panel')->add('protocol')->add('isActive')->add('country');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('panel'),
            TextField::new('remoteInboundId'),
            TextField::new('title'),
            TextField::new('remark')->hideOnIndex(),
            TextField::new('country'),
            TextField::new('location')->hideOnIndex(),
            TextField::new('protocol'),
            TextField::new('host'),
            IntegerField::new('port'),
            TextField::new('network'),
            TextField::new('security'),
            TextField::new('sni'),
            TextField::new('path')->hideOnIndex(),
            TextField::new('hostHeader')->hideOnIndex(),
            TextField::new('publicKey')->hideOnIndex(),
            TextField::new('shortId')->hideOnIndex(),
            TextField::new('spiderX')->hideOnIndex(),
            TextField::new('flow')->hideOnIndex(),
            TextField::new('serviceName')->hideOnIndex(),
            TextField::new('fingerprint')->hideOnIndex(),
            TextField::new('alpn')->hideOnIndex(),
            JsonField::new('config'),
            BooleanField::new('isActive'),
            DateTimeField::new('lastAccessMetadataSyncedAt')->hideOnForm(),
            DateTimeField::new('lastSyncedAt'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
