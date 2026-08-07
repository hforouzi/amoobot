<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\VpnInbound;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class VpnInboundCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VpnInbound::class;
    }

    public function new(AdminContext $context): KeyValueStore|Response
    {
        throw $this->createAccessDeniedException('VpnInbound administration is read-only.');
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        throw $this->createAccessDeniedException('VpnInbound administration is read-only.');
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        throw $this->createAccessDeniedException('VpnInbound administration is read-only.');
    }

    public function batchDelete(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        throw $this->createAccessDeniedException('VpnInbound administration is read-only.');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('vpn_inbound.singular')
            ->setEntityLabelInPlural('vpn_inbound.plural')
            ->setPageTitle(Crud::PAGE_INDEX, 'vpn_inbound.pages.index')
            ->setPageTitle(Crud::PAGE_DETAIL, 'vpn_inbound.pages.detail')
            ->setSearchFields(['id', 'remoteInboundId', 'title', 'remark', 'protocol', 'port'])
            ->setDefaultSort(['updatedAt' => 'DESC', 'id' => 'DESC'])
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureActions(Actions $actions): Actions
    {
        $backToPanel = Action::new('backToPanel', 'vpn_inbound.actions.back_to_panel')
            ->linkToRoute('admin', fn (VpnInbound $inbound): array => [
                'crudAction' => 'detail',
                'crudControllerFqcn' => VpnPanelCrudController::class,
                'entityId' => $inbound->getPanel()->getId(),
            ])
            ->setCssClass('btn btn-secondary');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Action::INDEX, Action::DETAIL)
            ->add(Action::INDEX, $backToPanel)
            ->add(Action::DETAIL, $backToPanel);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('panel', 'vpn_inbound.fields.panel')->autocomplete())
            ->add(TextFilter::new('protocol', 'vpn_inbound.fields.protocol'))
            ->add(BooleanFilter::new('isActive', 'vpn_inbound.fields.is_active'))
            ->add(NumericFilter::new('port', 'vpn_inbound.fields.port'))
            ->add(TextFilter::new('remoteInboundId', 'vpn_inbound.fields.remote_inbound_id'))
            ->add(TextFilter::new('remark', 'vpn_inbound.fields.remark'));
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $panelId = $this->requestStack?->getCurrentRequest()?->query->getInt('panelId', 0) ?? 0;
        if ($panelId > 0) {
            $queryBuilder
                ->andWhere('entity.panel = :panelId')
                ->setParameter('panelId', $panelId);
        }

        return $queryBuilder;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addFieldset('vpn_inbound.sections.identity', 'fa fa-fingerprint'),
            IdField::new('id', 'vpn_inbound.fields.id')->onlyOnIndex(),
            AssociationField::new('panel', 'vpn_inbound.fields.panel'),
            TextField::new('remoteInboundId', 'vpn_inbound.fields.remote_inbound_id'),
            TextField::new('title', 'vpn_inbound.fields.title'),
            TextField::new('remark', 'vpn_inbound.fields.remark')->hideOnIndex(),
            TextField::new('protocol', 'vpn_inbound.fields.protocol')
                ->formatValue(static fn (mixed $value): string => self::protocolBadge($value))
                ->renderAsHtml(),
            IntegerField::new('port', 'vpn_inbound.fields.port'),
            TextField::new('remoteTag', 'vpn_inbound.fields.tag')->setSortable(false),
            TextField::new('remoteListen', 'vpn_inbound.fields.listen')->setSortable(false)->hideOnIndex(),

            FormField::addFieldset('vpn_inbound.sections.status', 'fa fa-toggle-on'),
            BooleanField::new('isActive', 'vpn_inbound.fields.is_active'),
            TextField::new('remoteExpiryTime', 'vpn_inbound.fields.expiry')
                ->formatValue(fn (mixed $value): string => $this->formatExpiry($value))
                ->setSortable(false),
            DateTimeField::new('lastSyncedAt', 'vpn_inbound.fields.last_synced_at'),
            DateTimeField::new('lastAccessMetadataSyncedAt', 'vpn_inbound.fields.last_access_metadata_synced_at')->hideOnIndex(),
            DateTimeField::new('updatedAt', 'common.updated_at')->hideOnForm(),
            DateTimeField::new('createdAt', 'common.created_at')->hideOnForm()->hideOnIndex(),

            FormField::addFieldset('vpn_inbound.sections.traffic', 'fa fa-bar-chart'),
            TextField::new('uploadTrafficBytes', 'vpn_inbound.fields.upload')
                ->formatValue(static fn (mixed $value): string => self::formatBytes($value))
                ->setSortable(false),
            TextField::new('downloadTrafficBytes', 'vpn_inbound.fields.download')
                ->formatValue(static fn (mixed $value): string => self::formatBytes($value))
                ->setSortable(false),
            TextField::new('totalTrafficBytes', 'vpn_inbound.fields.total')
                ->formatValue(static fn (mixed $value): string => self::formatBytes($value))
                ->setSortable(false),

            FormField::addFieldset('vpn_inbound.sections.technical', 'fa fa-code')->onlyOnDetail(),
            TextField::new('country', 'vpn_inbound.fields.country')->onlyOnDetail(),
            TextField::new('location', 'vpn_inbound.fields.location')->onlyOnDetail(),
            TextField::new('network', 'vpn_inbound.fields.network')->onlyOnDetail(),
            TextField::new('security', 'vpn_inbound.fields.security')->onlyOnDetail(),
            TextField::new('host', 'vpn_inbound.fields.host')->onlyOnDetail(),
            TextField::new('sni', 'vpn_inbound.fields.sni')->onlyOnDetail(),
            TextField::new('path', 'vpn_inbound.fields.path')->onlyOnDetail(),
            TextField::new('hostHeader', 'vpn_inbound.fields.host_header')->onlyOnDetail(),
            TextField::new('flow', 'vpn_inbound.fields.flow')->onlyOnDetail(),
            TextField::new('serviceName', 'vpn_inbound.fields.service_name')->onlyOnDetail(),
            TextField::new('fingerprint', 'vpn_inbound.fields.fingerprint')->onlyOnDetail(),
            TextField::new('alpn', 'vpn_inbound.fields.alpn')->onlyOnDetail(),
            CodeEditorField::new('config', 'vpn_inbound.fields.config')
                ->setCustomOption(CodeEditorField::OPTION_LANGUAGE, 'json')
                ->formatValue(static fn (mixed $value): string => self::formatJsonConfig($value))
                ->onlyOnDetail(),
        ];
    }

    public static function formatJsonConfig(mixed $value): string
    {
        if (null === $value || [] === $value) {
            return '';
        }

        $decoded = is_array($value) ? $value : json_decode(trim((string) $value), true);
        if (!is_array($decoded)) {
            return '';
        }

        $decoded = self::redactSensitiveConfig($decoded);

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public static function formatBytes(mixed $value): string
    {
        if (null === $value || '' === $value || !is_numeric($value)) {
            return '-';
        }

        $bytes = max(0, (float) $value);
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || 'TB' === $unit) {
                return sprintf('%s %s', rtrim(rtrim(number_format($bytes, $bytes >= 10 || 'B' === $unit ? 0 : 1), '0'), '.'), $unit);
            }
            $bytes /= 1024;
        }

        return '-';
    }

    public function formatExpiry(mixed $value): string
    {
        if (null === $value || '' === $value || !is_numeric($value) || (int) $value <= 0) {
            return $this->translate('vpn_inbound.expiry.never');
        }

        $timestamp = (int) $value;
        if ($timestamp > 100000000000) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        $date = (new \DateTimeImmutable())->setTimestamp($timestamp);
        $prefix = $date < new \DateTimeImmutable() ? $this->translate('vpn_inbound.expiry.expired') : '';

        return trim($prefix.' '.$date->format('Y-m-d H:i:s'));
    }

    private function translate(string $key): string
    {
        return $this->translator?->trans($key, domain: 'admin') ?? $key;
    }

    private static function protocolBadge(mixed $value): string
    {
        $protocol = strtoupper(trim((string) $value));
        if ('' === $protocol) {
            return '-';
        }

        return sprintf('<span class="badge badge-info">%s</span>', htmlspecialchars($protocol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function redactSensitiveConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if ('clients' === $key) {
                $config[$key] = '[omitted]';
                continue;
            }

            if (is_string($key) && preg_match('/uuid|token|password|secret|authorization|cookie|session|sub/i', $key)) {
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
