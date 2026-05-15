<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\DashboardController;
use App\Admin\UI\Support\AdminJsonFormatter;
use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\Plugin;
use App\Plugin\PluginManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

final class PluginCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Plugin::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('plugin.singular')
            ->setEntityLabelInPlural('plugin.plural')
            ->setDefaultSort(['installedAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['code', 'type', 'version', 'mainClass', 'path']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $upload = Action::new('uploadPlugin', 'plugin.upload_action', 'fa fa-upload')
            ->createAsGlobalAction()
            ->linkToRoute('admin_plugins_upload');

        $enable = Action::new('enablePlugin', 'plugin.enable', 'fa fa-toggle-on')
            ->linkToRoute('admin_plugins_enable', fn (Plugin $plugin): array => ['id' => $plugin->getId()])
            ->displayIf(fn (Plugin $plugin): bool => Plugin::STATUS_ENABLED !== $plugin->getStatus());

        $disable = Action::new('disablePlugin', 'plugin.disable', 'fa fa-toggle-off')
            ->linkToRoute('admin_plugins_disable', fn (Plugin $plugin): array => ['id' => $plugin->getId()])
            ->displayIf(fn (Plugin $plugin): bool => Plugin::STATUS_ENABLED === $plugin->getStatus());

        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->remove(Crud::PAGE_INDEX, Action::DELETE)
            ->remove(Crud::PAGE_DETAIL, Action::EDIT)
            ->remove(Crud::PAGE_DETAIL, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $upload)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $enable)
            ->add(Crud::PAGE_INDEX, $disable)
            ->add(Crud::PAGE_DETAIL, $enable)
            ->add(Crud::PAGE_DETAIL, $disable);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('code', 'plugin.code'),
            TextField::new('type', 'plugin.type'),
            TextField::new('displayName', 'plugin.name')
                ->formatValue(fn (mixed $value, Plugin $plugin): string => $plugin->getDisplayName($this->getContext()?->getRequest()->getLocale() ?? 'en')),
            TextareaField::new('descriptionText', 'plugin.description')
                ->formatValue(fn (mixed $value, Plugin $plugin): string => $plugin->getDescriptionText($this->getContext()?->getRequest()->getLocale() ?? 'en'))
                ->hideOnIndex()
                ->hideOnForm(),
            TextField::new('version', 'plugin.version'),
            TextField::new('status', 'plugin.status')
                ->formatValue(static fn (mixed $value): string => AdminStatusBadge::html($value))
                ->renderAsHtml(),
            TextField::new('permissionsSummary', 'plugin.permissions')
                ->onlyOnIndex(),
            TextField::new('path', 'plugin.path')->hideOnIndex(),
            TextField::new('mainClass', 'plugin.main_class')->hideOnIndex(),
            TextField::new('permissionsBadges', 'plugin.permissions')
                ->renderAsHtml()
                ->onlyOnDetail()
                ->hideOnForm(),
            TextareaField::new('manifestJson', 'plugin.manifest')
                ->formatValue(static fn (mixed $value): string => AdminJsonFormatter::toPrettyHtml($value))
                ->renderAsHtml()
                ->onlyOnDetail()
                ->hideOnForm(),
            TextareaField::new('errorMessage', 'plugin.error_message')->hideOnIndex(),
            DateTimeField::new('installedAt', 'plugin.installed_at'),
            DateTimeField::new('enabledAt', 'plugin.enabled_at'),
            DateTimeField::new('disabledAt', 'plugin.disabled_at')->hideOnIndex(),
            DateTimeField::new('createdAt', 'common.created_at')->hideOnIndex(),
            DateTimeField::new('updatedAt', 'common.updated_at')->hideOnIndex(),
        ];
    }

    #[Route('/admin/plugins/upload', name: 'admin_plugins_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $form = $this->createFormBuilder(null, [
            'translation_domain' => 'admin',
        ])
            ->add('pluginZip', FileType::class, [
                'label' => 'plugin.zip_file',
                'mapped' => false,
                'constraints' => [
                    new NotNull(message: 'Choose a plugin ZIP file.'),
                    new File(maxSize: '10M'),
                ],
                'attr' => ['accept' => '.zip,application/zip'],
            ])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('pluginZip')->getData();
            if ($file instanceof UploadedFile) {
                $result = $this->pluginManager->installFromZip($file);
                if ($result->success && null !== $result->plugin) {
                    $this->addFlash('success', 'Plugin installed successfully.');

                    return $this->redirect($this->pluginDetailUrl($result->plugin));
                }

                $this->addFlash('danger', $result->error ?? 'Plugin installation failed.');
            }
        }

        return $this->render('admin/plugin/upload.html.twig', [
            'form' => $form,
            'listUrl' => $this->pluginListUrl(),
        ]);
    }

    #[Route('/admin/plugins/{id}/enable', name: 'admin_plugins_enable', methods: ['GET'])]
    public function enable(Plugin $plugin): Response
    {
        $this->pluginManager->enable($plugin);
        $this->addFlash('success', 'Plugin enabled.');

        return $this->redirect($this->pluginListUrl());
    }

    #[Route('/admin/plugins/{id}/disable', name: 'admin_plugins_disable', methods: ['GET'])]
    public function disable(Plugin $plugin): Response
    {
        $this->pluginManager->disable($plugin);
        $this->addFlash('success', 'Plugin disabled.');

        return $this->redirect($this->pluginListUrl());
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('Plugins must be installed from ZIP packages.');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('Plugins cannot be edited manually in this phase.');
    }

    private function pluginListUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }

    private function pluginDetailUrl(Plugin $plugin): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($plugin->getId())
            ->generateUrl();
    }
}
