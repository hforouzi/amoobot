<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Form\Type\JsonTextareaType;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class PaymentGatewayCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.payment_gateway')
            ->setEntityLabelInPlural('admin.payment_gateways')
            ->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $addStoreMethod = Action::new('addStoreMethod', 'payment_gateway.add_store_method', 'fa fa-list-ol')
            ->linkToUrl(fn (PaymentGateway $gateway): string => $this->adminUrlGenerator
                ->setController(StorePaymentMethodCrudController::class)
                ->setAction(Action::NEW)
                ->set('gatewayId', $gateway->getId())
                ->generateUrl())
            ->displayIf(fn (PaymentGateway $gateway): bool => $this->moduleRegistry->supports($gateway->getType()));

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action): Action => $action
                ->setLabel('payment_gateway.install_action')
                ->setIcon('fa fa-plus'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action): Action => $action->setLabel('common.edit'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action): Action => $action->setLabel('common.delete'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $addStoreMethod)
            ->add(Crud::PAGE_DETAIL, $addStoreMethod);
    }

    public function createEntity(string $entityFqcn): object
    {
        $type = (string) ($this->requestStack->getCurrentRequest()?->query->get('type') ?? '');
        if (!$this->moduleRegistry->supports($type)) {
            $type = $this->moduleRegistry->supportedTypes()[0] ?? 'manual_card';
        }

        return (new PaymentGateway())
            ->setType($type)
            ->setTitle($this->moduleRegistry->defaultTitle($type))
            ->setCurrency('IRR')
            ->setIsActive(true)
            ->setConfig($this->moduleRegistry->defaultConfig($type));
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $entityInstance
                ->setUpdatedAt(new \DateTimeImmutable())
                ->setConfig($this->normalizedConfig($entityInstance));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $entityInstance
                ->setUpdatedAt(new \DateTimeImmutable())
                ->setConfig($this->normalizedConfig($entityInstance));
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        $currentType = $this->currentGatewayType();
        $configHelp = $this->moduleRegistry->configHelp($currentType);

        $configuredField = ChoiceField::new('configured', 'payment_gateway.configured')
            ->setChoices([
                'common.yes' => true,
                'common.no' => false,
            ])
            ->renderAsBadges([
                true => 'success',
                false => 'secondary',
            ])
            ->onlyOnIndex();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'payment_gateway.title'),
            ChoiceField::new('type', 'payment_gateway.type')
                ->setChoices($this->moduleRegistry->choiceMap())
                ->formatValue(fn (mixed $value): string => $this->moduleRegistry->displayName((string) $value))
                ->setHelp('payment_gateway.type_help'),
            $configuredField,
            BooleanField::new('isActive', 'payment_gateway.active'),
            TextField::new('currency', 'payment_gateway.currency'),
            DateTimeField::new('updatedAt', 'common.updated_at')->hideOnForm(),
            TextareaField::new('description', 'payment_gateway.description')
                ->hideOnIndex(),
            TextareaField::new('config', 'payment_gateway.json_config')
                ->setFormType(JsonTextareaType::class)
                ->setFormTypeOption('invalid_message', 'payment_gateway.invalid_json')
                ->setHelp($configHelp)
                ->hideOnIndex()
                ->formatValue(static fn (mixed $value): string => is_array($value)
                    ? (json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                    : ''),
            DateTimeField::new('createdAt', 'common.created_at')->hideOnForm()->hideOnIndex(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        return $qb
            ->andWhere('entity.type IN (:supportedTypes)')
            ->setParameter('supportedTypes', $this->moduleRegistry->supportedTypes());
    }

    private function currentGatewayType(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $type = trim((string) $request->query->get('type', ''));
        if ($this->moduleRegistry->supports($type)) {
            return $type;
        }

        $entityId = (int) $request->query->get('entityId', 0);
        if ($entityId <= 0) {
            return null;
        }

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($entityId);

        return $gateway instanceof PaymentGateway ? $gateway->getType() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedConfig(PaymentGateway $gateway): array
    {
        $config = is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
        $normalized = [];
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
