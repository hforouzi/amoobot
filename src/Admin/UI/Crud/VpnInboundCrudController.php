<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnInbound;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class VpnInboundCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnInbound::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $testCreateClient = Action::new('testCreateClient', 'تست ساخت کلاینت')
            ->linkToRoute('admin_vpn_inbound_test_create_client', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        $resync = Action::new('resyncInbound', 'همگامسازی مجدد')
            ->linkToRoute('admin_vpn_inbound_resync', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        $syncAccessMetadata = Action::new('syncAccessMetadata', 'همگامسازی متادیتای دسترسی')
            ->linkToRoute('admin_vpn_inbound_sync_access_metadata', fn (VpnInbound $inbound): array => ['id' => $inbound->getId()])
            ->setCssClass('btn btn-warning')
            ->displayIf(fn (VpnInbound $inbound): bool => 'sanaei_3xui' === $inbound->getPanel()->getType());

        return $actions
            ->add(Action::INDEX, $testCreateClient)
            ->add(Action::INDEX, $resync)
            ->add(Action::INDEX, $syncAccessMetadata)
            ->add(Action::DETAIL, $testCreateClient)
            ->add(Action::DETAIL, $resync)
            ->add(Action::DETAIL, $syncAccessMetadata);
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
            TextField::new('port'),
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
            BooleanField::new('isActive'),
            DateTimeField::new('lastAccessMetadataSyncedAt')->hideOnForm(),
            DateTimeField::new('lastSyncedAt'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
