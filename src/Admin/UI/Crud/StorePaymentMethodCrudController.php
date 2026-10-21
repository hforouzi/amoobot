<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Store payment methods (what customers see in the bot). Listed, ordered and switched on/off on the payment gateways
 * page (PaymentGatewayCrudController, "store payment methods" section); this controller keeps the create/edit/detail
 * pages and the move/toggle actions. Its own list redirects to that section.
 */
final class StorePaymentMethodCrudController extends AbstractCrudController
{
    public const CSRF_PREFIX = 'store-payment-method-';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
        private readonly AdminAuditLogger $auditLogger,
    ) {
    }

    /** The list lives on the payment gateways page. */
    public function index(AdminContext $context): Response
    {
        return new RedirectResponse(self::listUrl($this->generateUrl('admin_payment_gateway_index')));
    }

    public static function listUrl(string $gatewaysUrl): string
    {
        return $gatewaysUrl.'#store-payment-methods';
    }

    /**
     * The methods in the order the bot shows them (sortOrder, then id).
     *
     * @return list<StorePaymentMethod>
     */
    public static function ordered(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getRepository(StorePaymentMethod::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    #[Route('/admin/store-payment-method/{id}/move/{direction}', name: 'admin_store_payment_method_move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(Request $request, StorePaymentMethod $method, string $direction): RedirectResponse
    {
        $this->checkToken($request, $method);

        // Renumber 10, 20, 30... in the current order (equal sort orders get apart), then swap with the neighbour.
        $methods = self::ordered($this->entityManager);
        $position = array_search($method, $methods, true);
        $neighbour = false === $position ? false : $position + ('up' === $direction ? -1 : 1);
        if (false !== $neighbour && isset($methods[$neighbour])) {
            [$methods[$position], $methods[$neighbour]] = [$methods[$neighbour], $methods[$position]];
            $now = new \DateTimeImmutable();
            foreach ($methods as $index => $each) {
                if ($each->getSortOrder() !== ($index + 1) * 10) {
                    $each->setSortOrder(($index + 1) * 10)->setUpdatedAt($now);
                    $this->auditLogger->record('store_payment_method.update', 'StorePaymentMethod', (string) $each->getId(), ['changed' => ['sortOrder']]);
                }
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'store_methods.updated');
        }

        return new RedirectResponse(self::listUrl($this->generateUrl('admin_payment_gateway_index')));
    }

    #[Route('/admin/store-payment-method/{id}/toggle', name: 'admin_store_payment_method_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, StorePaymentMethod $method): RedirectResponse
    {
        $this->checkToken($request, $method);

        $method->setIsActive(!$method->isActive())->setUpdatedAt(new \DateTimeImmutable());
        $this->auditLogger->record('store_payment_method.update', 'StorePaymentMethod', (string) $method->getId(), ['changed' => ['isActive']]);
        $this->entityManager->flush();
        $this->addFlash('success', 'store_methods.updated');

        return new RedirectResponse(self::listUrl($this->generateUrl('admin_payment_gateway_index')));
    }

    private function checkToken(Request $request, StorePaymentMethod $method): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$method->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    public static function getEntityFqcn(): string
    {
        return StorePaymentMethod::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(AdminCrudPermissions::view(self::class));
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
