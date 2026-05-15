<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class VpnServiceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VpnService::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $regenerateConfig = Action::new('regenerateConfig', '🔄 بازسازی کانفیگ')
            ->linkToRoute('admin_vpn_service_regenerate_config', fn (VpnService $service): array => ['id' => $service->getId()])
            ->setCssClass('btn btn-info');

        return $actions
            ->add(Action::INDEX, $regenerateConfig)
            ->add(Action::DETAIL, $regenerateConfig);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user')->setLabel('admin.users'),
            AssociationField::new('order')->setLabel('admin.orders'),
            AssociationField::new('panel')->setLabel('admin.vpn_panels'),
            AssociationField::new('inbound')->setLabel('admin.vpn_inbounds'),
            TextField::new('remoteId')->setLabel('Remote ID'),
            TextField::new('username')->setLabel('Username'),
            TextField::new('clientUuid')->hideOnIndex(),
            TextField::new('clientEmail')->hideOnIndex(),
            TextField::new('subId')->hideOnIndex(),
            IntegerField::new('ipLimit')->setLabel('IP Limit'),
            TextareaField::new('subscriptionUrl')->hideOnIndex(),
            TextareaField::new('configText')->hideOnIndex(),
            TextField::new('status')
                ->formatValue(static fn (mixed $value): string => AdminStatusBadge::html($value))
                ->renderAsHtml(),
            DateTimeField::new('startsAt')->setLabel('Starts At'),
            DateTimeField::new('expiresAt')->setLabel('Expires At'),
            IntegerField::new('trafficLimitGb')->setLabel('Traffic Limit (GB)'),
            IntegerField::new('trafficUsedGb')->setLabel('Traffic Used (GB)'),
            IntegerField::new('trafficLimitBytes')->hideOnIndex(),
            IntegerField::new('trafficUsedBytes')->hideOnIndex(),
            DateTimeField::new('lastUsageSyncedAt')->hideOnForm(),
            DateTimeField::new('lastStatusCheckedAt')->hideOnForm(),
            DateTimeField::new('lastAccessInfoSyncedAt')->hideOnForm(),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $queryBuilder
            ->andWhere('entity.status != :deletedStatus')
            ->setParameter('deletedStatus', VpnServiceStatus::DELETED);

        return $queryBuilder;
    }
}
