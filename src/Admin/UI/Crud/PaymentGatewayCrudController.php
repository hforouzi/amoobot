<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\DashboardController;
use App\Admin\UI\Support\AdminJsonFormatter;
use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class PaymentGatewayCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PaymentPluginDoctor $pluginDoctor,
        private readonly PluginConfigSchemaValidator $schemaValidator,
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
        $installGateway = Action::new('installGateway', 'payment_gateway.install_action', 'fa fa-plus')
            ->createAsGlobalAction()
            ->linkToRoute('admin_payment_gateways_install');

        $editConfig = Action::new('editGatewayConfig', 'payment_gateway.edit_config', 'fa fa-sliders')
            ->linkToRoute('admin_payment_gateways_config', fn (PaymentGateway $gateway): array => [
                'id' => $gateway->getId(),
            ])
            ->displayIf(fn (PaymentGateway $gateway): bool => $this->moduleRegistry->supports($gateway->getType()));

        $addStoreMethod = Action::new('addStoreMethod', 'payment_gateway.add_store_method', 'fa fa-list-ol')
            ->linkToUrl(fn (PaymentGateway $gateway): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setDashboard(DashboardController::class)
                ->setController(StorePaymentMethodCrudController::class)
                ->setAction(Action::NEW)
                ->set('gatewayId', $gateway->getId())
                ->generateUrl())
            ->displayIf(fn (PaymentGateway $gateway): bool => $this->moduleRegistry->supports($gateway->getType()));

        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action): Action => $action->setLabel('common.edit'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action): Action => $action->setLabel('common.delete'))
            ->add(Crud::PAGE_INDEX, $installGateway)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $editConfig)
            ->add(Crud::PAGE_INDEX, $addStoreMethod)
            ->add(Crud::PAGE_DETAIL, $editConfig)
            ->add(Crud::PAGE_DETAIL, $addStoreMethod);
    }

    public function new(AdminContext $context): Response
    {
        return $this->redirectToRoute('admin_payment_gateways_install');
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
        $configuredField = TextField::new('configuredStatus', 'payment_gateway.configured')
            ->formatValue(fn (mixed $value, PaymentGateway $gateway): string => AdminStatusBadge::boolHtml($this->moduleRegistry->isConfigured($gateway)))
            ->renderAsHtml()
            ->hideOnForm();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title', 'payment_gateway.title'),
            TextField::new('type', 'payment_gateway.type')
                ->setFormTypeOption('attr', ['readonly' => 'readonly'])
                ->formatValue(fn (mixed $value): string => $this->moduleRegistry->displayName((string) $value))
                ->setHelp('payment_gateway.type_help'),
            TextField::new('pluginCode', 'payment_gateway.plugin_code')
                ->formatValue(function (mixed $value, PaymentGateway $gateway): string {
                    $pluginCode = trim((string) $value);
                    if ('' === $pluginCode) {
                        return '';
                    }

                    $plugin = $this->pluginRegistry->findByCode($pluginCode);
                    $doctor = $this->pluginDoctor->inspect($plugin);
                    $missing = $plugin instanceof \App\Entity\Plugin
                        ? $this->schemaValidator->missingRequiredKeys($plugin->getManifest()['configSchema'] ?? [], $gateway->getConfig())
                        : [];

                    return sprintf(
                        '%s<br><span class="text-muted">Plugin status: %s | Driver available: %s | Missing config: %s</span>',
                        htmlspecialchars($pluginCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        null === $plugin ? 'missing' : $plugin->getStatus(),
                        $doctor->ok() ? 'yes' : 'no',
                        [] === $missing ? '-' : htmlspecialchars(implode(', ', $missing), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    );
                })
                ->renderAsHtml()
                ->hideOnForm()
                ->hideOnIndex(),
            $configuredField,
            BooleanField::new('isActive', 'payment_gateway.active'),
            TextField::new('currency', 'payment_gateway.currency'),
            DateTimeField::new('updatedAt', 'common.updated_at')->hideOnForm(),
            TextareaField::new('description', 'payment_gateway.description')
                ->hideOnIndex(),
            TextareaField::new('configJson', 'payment_gateway.json_config')
                ->hideOnForm()
                ->hideOnIndex()
                ->formatValue(static fn (mixed $value): string => AdminJsonFormatter::toPrettyHtml($value))
                ->renderAsHtml(),
            DateTimeField::new('createdAt', 'common.created_at')->hideOnForm()->hideOnIndex(),
        ];
    }

    #[Route('/admin/payment-gateways/install', name: 'admin_payment_gateways_install', methods: ['GET'])]
    public function install(): Response
    {
        return $this->render('admin/payment_gateway/install.html.twig', [
            'modules' => $this->moduleRegistry->all(),
            'listUrl' => $this->gatewayListUrl(),
        ]);
    }

    #[Route('/admin/payment-gateways/install/{type}', name: 'admin_payment_gateways_install_type', methods: ['GET', 'POST'])]
    public function installType(Request $request, string $type): Response
    {
        if (!$this->moduleRegistry->supports($type)) {
            throw $this->createNotFoundException('Unsupported payment gateway module.');
        }

        $form = $this->createGatewaySchemaForm($type, [
            'title' => $this->moduleRegistry->defaultTitle($type),
            'currency' => 'IRR',
            'isActive' => true,
            'description' => '',
            'config' => $this->moduleRegistry->defaultConfig($type),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->normalizeGatewayFormData((array) $form->getData(), $type);

            $gateway = (new PaymentGateway())
                ->setType($type)
                ->setPluginCode(true === ($this->moduleRegistry->get($type)['isPlugin'] ?? false) ? $type : null)
                ->setTitle($data['title'])
                ->setCurrency($data['currency'])
                ->setIsActive($data['isActive'])
                ->setDescription($data['description'])
                ->setConfig($data['config'])
                ->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($gateway);
            $this->entityManager->flush();

            return $this->redirectToRoute('admin_payment_gateways_install_success', [
                'id' => $gateway->getId(),
            ]);
        }

        return $this->render('admin/payment_gateway/schema_form.html.twig', [
            'form' => $form,
            'module' => $this->moduleRegistry->get($type),
            'title' => 'payment_gateway.install_config_title',
            'backUrl' => $this->generateUrl('admin_payment_gateways_install'),
            'submitLabel' => 'payment_gateway.install_submit',
        ]);
    }

    #[Route('/admin/payment-gateways/install/success/{id}', name: 'admin_payment_gateways_install_success', methods: ['GET'])]
    public function installSuccess(PaymentGateway $gateway): Response
    {
        return $this->render('admin/payment_gateway/install_success.html.twig', [
            'gateway' => $gateway,
            'addStoreMethodUrl' => $this->generateUrl('admin_payment_gateways_add_store_method', [
                'id' => $gateway->getId(),
            ]),
            'listUrl' => $this->gatewayListUrl(),
        ]);
    }

    #[Route('/admin/payment-gateways/{id}/add-store-method', name: 'admin_payment_gateways_add_store_method', methods: ['POST'])]
    public function addStoreMethod(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('add_store_payment_method_'.$gateway->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $method = (new StorePaymentMethod())
            ->setGateway($gateway)
            ->setTitle($gateway->getTitle())
            ->setIsActive(true)
            ->setSortOrder($this->nextStorePaymentMethodSortOrder())
            ->setCurrency($gateway->getCurrency())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($method);
        $this->entityManager->flush();

        return $this->redirect($this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(StorePaymentMethodCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($method->getId())
            ->generateUrl());
    }

    #[Route('/admin/payment-gateways/{id}/config', name: 'admin_payment_gateways_config', methods: ['GET', 'POST'])]
    public function config(Request $request, PaymentGateway $gateway): Response
    {
        $type = $gateway->getType();
        if (!$this->moduleRegistry->supports($type)) {
            throw $this->createNotFoundException('Unsupported payment gateway module.');
        }

        $form = $this->createGatewaySchemaForm($type, [
            'title' => $gateway->getTitle(),
            'currency' => $gateway->getCurrency(),
            'isActive' => $gateway->isActive(),
            'description' => $gateway->getDescription() ?? '',
            'config' => array_replace(
                $this->moduleRegistry->defaultConfig($type),
                is_array($gateway->getConfig()) ? $gateway->getConfig() : []
            ),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->normalizeGatewayFormData((array) $form->getData(), $type);

            $gateway
                ->setTitle($data['title'])
                ->setCurrency($data['currency'])
                ->setIsActive($data['isActive'])
                ->setDescription($data['description'])
                ->setConfig($data['config'])
                ->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->flush();

            return $this->redirect($this->gatewayListUrl());
        }

        return $this->render('admin/payment_gateway/schema_form.html.twig', [
            'form' => $form,
            'module' => $this->moduleRegistry->get($type),
            'title' => 'payment_gateway.edit_config',
            'backUrl' => $this->gatewayListUrl(),
            'submitLabel' => 'common.save',
        ]);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        return $qb
            ->andWhere('entity.type IN (:supportedTypes)')
            ->setParameter('supportedTypes', $this->moduleRegistry->supportedTypes());
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

    /**
     * @param array{title?: string, currency?: string, isActive?: bool, description?: ?string, config?: array<string, mixed>} $data
     */
    private function createGatewaySchemaForm(string $type, array $data): \Symfony\Component\Form\FormInterface
    {
        $builder = $this->createFormBuilder($this->flattenGatewayFormData($data), [
            'translation_domain' => 'admin',
        ])
            ->add('title', TextType::class, [
                'label' => 'payment_gateway.title',
                'constraints' => [new NotBlank()],
            ])
            ->add('currency', TextType::class, [
                'label' => 'payment_gateway.currency',
                'constraints' => [new NotBlank()],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'payment_gateway.active',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'payment_gateway.description',
                'required' => false,
            ]);

        foreach ((array) ($this->moduleRegistry->get($type)['configSchema'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? $field['key'] ?? '');
            if ('' === $name) {
                continue;
            }

            $fieldType = (string) ($field['type'] ?? 'text');
            $required = true === ($field['required'] ?? false);
            $options = [
                'label' => $this->configFieldLabel($name),
                'required' => $required,
                'constraints' => $required ? [new NotBlank()] : [],
            ];

            if ('textarea' === $fieldType) {
                $builder->add($name, TextareaType::class, $options + ['empty_data' => '']);
                continue;
            }

            if ('boolean' === $fieldType) {
                $builder->add($name, CheckboxType::class, [
                    'label' => $this->configFieldLabel($name),
                    'required' => false,
                ]);
                continue;
            }

            if ('choice' === $fieldType) {
                $builder->add($name, ChoiceType::class, $options + [
                    'choices' => (array) ($field['choices'] ?? []),
                ]);
                continue;
            }

            if ('integer' === $fieldType) {
                $builder->add($name, IntegerType::class, [
                    'label' => $this->configFieldLabel($name),
                    'required' => $required,
                    'constraints' => $required ? [new NotBlank(), new Positive()] : [new Positive()],
                ]);
                continue;
            }

            if ('number' === $fieldType) {
                $builder->add($name, NumberType::class, [
                    'label' => $this->configFieldLabel($name),
                    'required' => $required,
                    'constraints' => $required ? [new NotBlank()] : [],
                ]);
                continue;
            }

            if ('password' === $fieldType) {
                $builder->add($name, PasswordType::class, $options + [
                    'always_empty' => false,
                    'empty_data' => '',
                ]);
                continue;
            }

            if ('url' === $fieldType) {
                $builder->add($name, UrlType::class, $options + ['empty_data' => '']);
                continue;
            }

            $builder->add($name, TextType::class, $options + ['empty_data' => '']);
        }

        return $builder->getForm();
    }

    /**
     * @param array{title?: string, currency?: string, isActive?: bool, description?: ?string, config?: array<string, mixed>} $data
     *
     * @return array<string, mixed>
     */
    private function flattenGatewayFormData(array $data): array
    {
        $flat = [
            'title' => (string) ($data['title'] ?? ''),
            'currency' => (string) ($data['currency'] ?? 'IRR'),
            'isActive' => true === ($data['isActive'] ?? true),
            'description' => (string) ($data['description'] ?? ''),
        ];

        foreach ((array) ($data['config'] ?? []) as $key => $value) {
            $flat[(string) $key] = $value;
        }

        return $flat;
    }

    /**
     * @return array{title: string, currency: string, isActive: bool, description: ?string, config: array<string, mixed>}
     */
    private function normalizeGatewayFormData(array $data, string $type): array
    {
        $config = [];
        foreach ((array) ($this->moduleRegistry->get($type)['configSchema'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? $field['key'] ?? '');
            if ('' === $name) {
                continue;
            }

            $value = $data[$name] ?? ($field['default'] ?? null);
            if (is_string($value)) {
                $value = trim($value);
            }

            if ('' === $value || null === $value) {
                continue;
            }

            if ('integer' === (string) ($field['type'] ?? '')) {
                $value = (int) $value;
            }

            if ('number' === (string) ($field['type'] ?? '')) {
                $value = (float) $value;
            }

            if ('boolean' === (string) ($field['type'] ?? '')) {
                $value = true === $value;
            }

            $config[$name] = $value;
        }

        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'IRR'))),
            'isActive' => true === ($data['isActive'] ?? false),
            'description' => '' === trim((string) ($data['description'] ?? '')) ? null : trim((string) $data['description']),
            'config' => $config,
        ];
    }

    private function configFieldLabel(string $field): string
    {
        return 'payment_gateway.config.'.$field;
    }

    private function gatewayListUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }

    private function nextStorePaymentMethodSortOrder(): int
    {
        $max = $this->entityManager->getRepository(StorePaymentMethod::class)
            ->createQueryBuilder('method')
            ->select('MAX(method.sortOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) ($max ?? 0)) + 10;
    }
}
