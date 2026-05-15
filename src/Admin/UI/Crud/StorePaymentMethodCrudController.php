<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;

final class StorePaymentMethodCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return StorePaymentMethod::class;
    }

    public function createEntity(string $entityFqcn): object
    {
        $method = (new StorePaymentMethod())
            ->setIsActive(true)
            ->setSortOrder(0)
            ->setCurrency('IRR');

        $gatewayId = (int) ($this->requestStack->getCurrentRequest()?->query->get('gatewayId') ?? 0);
        if ($gatewayId > 0) {
            $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($gatewayId);
            if ($gateway instanceof PaymentGateway && $this->moduleRegistry->supports($gateway->getType())) {
                $method
                    ->setGateway($gateway)
                    ->setTitle($gateway->getTitle())
                    ->setCurrency($gateway->getCurrency());
            }
        }

        return $method;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof StorePaymentMethod) {
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof StorePaymentMethod) {
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addFieldset('fieldset.store_payment_method'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'payment_gateway.title')
                ->setHelp('help.payment_gateway_store_method'),
            AssociationField::new('gateway', 'admin.payment_gateways')
                ->setHelp('help.payment_gateway_store_method')
                ->setFormTypeOption('query_builder', fn (EntityRepository $repository) => $repository
                    ->createQueryBuilder('gateway')
                    ->andWhere('gateway.type IN (:types)')
                    ->setParameter('types', $this->moduleRegistry->supportedTypes())
                    ->orderBy('gateway.title', 'ASC')),
            BooleanField::new('isActive', 'payment_gateway.active'),
            FormField::addFieldset('payment_gateway.display_settings'),
            IntegerField::new('sortOrder', 'payment_gateway.sort_order'),
            IntegerField::new('minAmount', 'payment_gateway.min_amount'),
            IntegerField::new('maxAmount', 'payment_gateway.max_amount'),
            TextField::new('currency', 'payment_gateway.currency'),
            FormField::addFieldset('fieldset.metadata'),
            DateTimeField::new('createdAt', 'common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt', 'common.updated_at')->hideOnForm(),
        ];
    }
}
