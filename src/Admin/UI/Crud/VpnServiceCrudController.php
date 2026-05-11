<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
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
            AssociationField::new('user'),
            AssociationField::new('order'),
            AssociationField::new('panel'),
            AssociationField::new('inbound'),
            TextField::new('remoteId'),
            TextField::new('username'),
            TextField::new('clientUuid')->hideOnIndex(),
            TextField::new('clientEmail')->hideOnIndex(),
            TextField::new('subId')->hideOnIndex(),
            IntegerField::new('ipLimit'),
            TextareaField::new('subscriptionUrl')->hideOnIndex(),
            TextareaField::new('configText')->hideOnIndex(),
            TextField::new('status'),
            DateTimeField::new('startsAt'),
            DateTimeField::new('expiresAt'),
            IntegerField::new('trafficLimitGb'),
            IntegerField::new('trafficUsedGb'),
            IntegerField::new('trafficLimitBytes')->hideOnIndex(),
            IntegerField::new('trafficUsedBytes')->hideOnIndex(),
            DateTimeField::new('lastUsageSyncedAt')->hideOnForm(),
            DateTimeField::new('lastStatusCheckedAt')->hideOnForm(),
            DateTimeField::new('lastAccessInfoSyncedAt')->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
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
