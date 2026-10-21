<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminFormat;
use App\Entity\VpnService;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminPermission;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
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
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * VPN services, with tabs (?tab=): all, active, expiring within 3 days, expired, suspended (the dashboard links here).
 */
class VpnServiceCrudController extends AbstractCrudController
{
    public const TABS = ['all', 'active', 'expiring', 'expired', 'suspended'];
    public const EXPIRING_DAYS = 3;

    public function __construct(
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly AdminFormat $format,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnService::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['id' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/crud/index_tabs.html.twig');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $counts = self::countByTab($this->entityManager, $this->clock->now());
            $active = $this->currentTab();
            $tabs = [];
            foreach (self::TABS as $tab) {
                $tabs[] = ['key' => $tab, 'label' => 'service.tabs.'.$tab, 'url' => $this->generateUrl('admin_vpn_service_index', 'all' === $tab ? [] : ['tab' => $tab]), 'active' => $tab === $active, 'count' => $counts[$tab]];
            }
            $responseParameters->set('admin_tabs', $tabs);
        }

        return $responseParameters;
    }

    /**
     * Services per tab (deleted services are never listed), in one query.
     *
     * @return array<string, int>
     */
    public static function countByTab(EntityManagerInterface $entityManager, \DateTimeImmutable $now): array
    {
        $row = $entityManager->createQueryBuilder()
            ->select(
                'COUNT(s.id) AS all_count',
                'SUM(CASE WHEN s.status = :active AND (s.expiresAt IS NULL OR s.expiresAt > :now) THEN 1 ELSE 0 END) AS active_count',
                'SUM(CASE WHEN s.status = :active AND s.expiresAt > :now AND s.expiresAt <= :soon THEN 1 ELSE 0 END) AS expiring_count',
                'SUM(CASE WHEN s.status = :expired THEN 1 ELSE 0 END) AS expired_count',
                'SUM(CASE WHEN s.status = :suspended THEN 1 ELSE 0 END) AS suspended_count',
            )
            ->from(VpnService::class, 's')
            ->andWhere('s.status != :deleted')
            ->setParameters(new ArrayCollection([
                new Parameter('active', VpnServiceStatus::ACTIVE),
                new Parameter('expired', VpnServiceStatus::EXPIRED),
                new Parameter('suspended', VpnServiceStatus::SUSPENDED),
                new Parameter('deleted', VpnServiceStatus::DELETED),
                new Parameter('now', $now),
                new Parameter('soon', $now->modify('+'.self::EXPIRING_DAYS.' days')),
            ]))
            ->getQuery()
            ->getSingleResult();

        return [
            'all' => (int) $row['all_count'],
            'active' => (int) $row['active_count'],
            'expiring' => (int) $row['expiring_count'],
            'expired' => (int) $row['expired_count'],
            'suspended' => (int) $row['suspended_count'],
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $regenerateConfig = Action::new('regenerateConfig', 'action.regenerate_config')
            ->linkToRoute('admin_vpn_service_regenerate_config', fn (VpnService $service): array => ['id' => $service->getId()])
            ->setCssClass('btn btn-info');

        return $actions
            ->add(Action::INDEX, $regenerateConfig)
            ->add(Action::DETAIL, $regenerateConfig)
            // Its route lives in VpnPanelInboundActionsController (PANELS_MANAGE).
            ->setPermission('regenerateConfig', AdminPermission::PANELS_MANAGE->value);
    }

    /** By customer: the Customers page links each customer's services here. */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('user'));
    }

    /**
     * The list: who, what, where, state, until when, how much is used, how fresh that is. Everything else (remote id,
     * username, links, raw byte counters, sync times) is on the detail page.
     */
    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;

        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('user', 'field.customer'),
            AdminFormat::field('plan', static fn (mixed $v, ?VpnService $s): string => null === ($plan = $s?->getOrder()?->getPlan()) ? AdminFormat::NONE : AdminFormat::e($plan->getTitle()))->onlyOnIndex(),
            AssociationField::new('order')->hideOnIndex(),
            AssociationField::new('panel'),
            AssociationField::new('inbound')->hideOnIndex(),
            TextField::new('status')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->status($v)),
            DateTimeField::new('expiresAt')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->expiry($v)),
            AdminFormat::field('traffic', static fn (mixed $v, ?VpnService $s): string => null === $s ? '' : $f->traffic($s->getTrafficUsedBytes(), $s->getTrafficLimitBytes()))->onlyOnIndex(),
            DateTimeField::new('lastUsageSyncedAt')->hideOnForm()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->relative($v)),

            // Detail page and form.
            TextField::new('remoteId')->hideOnIndex()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->copyable($v)),
            TextField::new('username')->hideOnIndex()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->copyable($v)),
            TextField::new('clientUuid')->hideOnIndex()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->copyable($v)),
            TextField::new('clientEmail')->hideOnIndex(),
            TextField::new('subId')->hideOnIndex(),
            IntegerField::new('ipLimit')->hideOnIndex(),
            TextareaField::new('subscriptionUrl')->hideOnIndex(),
            TextareaField::new('configText')
                ->formatValue(function (mixed $value, ?VpnService $service): string {
                    if (!$service instanceof VpnService) {
                        return (string) $value;
                    }

                    $finalLinks = $this->finalConfigLinkProvider->getFinalLinksForService($service, 'admin_vpn_service_crud');

                    return [] !== $finalLinks ? implode("\n", $finalLinks) : (string) $value;
                })
                ->hideOnIndex(),
            DateTimeField::new('startsAt')->hideOnIndex(),
            IntegerField::new('trafficLimitGb')->hideOnIndex(),
            IntegerField::new('trafficUsedGb')->hideOnIndex(),
            IntegerField::new('trafficLimitBytes')->hideOnIndex(),
            IntegerField::new('trafficUsedBytes')->hideOnIndex(),
            DateTimeField::new('lastStatusCheckedAt')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('lastAccessInfoSyncedAt')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        // The list's customer, plan and panel columns, in the same query (no query per row).
        $queryBuilder
            ->leftJoin('entity.user', 'listUser')->addSelect('listUser')
            ->leftJoin('entity.order', 'listOrder')->addSelect('listOrder')
            ->leftJoin('listOrder.plan', 'listPlan')->addSelect('listPlan')
            ->leftJoin('entity.panel', 'listPanel')->addSelect('listPanel')
            ->andWhere('entity.status != :deletedStatus')
            ->setParameter('deletedStatus', VpnServiceStatus::DELETED);

        $now = $this->clock->now();
        match ($this->currentTab()) {
            'active' => $queryBuilder->andWhere('entity.status = :tabStatus AND (entity.expiresAt IS NULL OR entity.expiresAt > :tabNow)')->setParameter('tabStatus', VpnServiceStatus::ACTIVE)->setParameter('tabNow', $now),
            'expiring' => $queryBuilder->andWhere('entity.status = :tabStatus AND entity.expiresAt > :tabNow AND entity.expiresAt <= :tabSoon')->setParameter('tabStatus', VpnServiceStatus::ACTIVE)->setParameter('tabNow', $now)->setParameter('tabSoon', $now->modify('+'.self::EXPIRING_DAYS.' days')),
            'expired' => $queryBuilder->andWhere('entity.status = :tabStatus')->setParameter('tabStatus', VpnServiceStatus::EXPIRED),
            'suspended' => $queryBuilder->andWhere('entity.status = :tabStatus')->setParameter('tabStatus', VpnServiceStatus::SUSPENDED),
            default => null,
        };

        return $queryBuilder;
    }

    private function currentTab(): string
    {
        $tab = (string) $this->requestStack->getCurrentRequest()?->query->get('tab', 'all');

        return in_array($tab, self::TABS, true) ? $tab : 'all';
    }
}
