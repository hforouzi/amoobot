<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminFormat;
use App\Entity\AppDownload;
use App\Subscription\Application\AppDownloadRejected;
use App\Subscription\Application\AppDownloadStorage;
use App\Subscription\Domain\ClientPlatform;
use App\Subscription\UI\SubscriptionFormat;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Apps page, "downloads" tab (AdminTabs): installers customers get from the subscription page and the bot. A file is
 * checked and stored by AppDownloadStorage when the form is valid (a rejected file is a form error); replacing or
 * deleting a download removes its old file.
 */
#[AdminRoute(path: '/apps/downloads', name: 'apps_downloads')]
final class AppDownloadCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AppDownloadStorage $storage,
        private readonly AdminFormat $format,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AppDownload::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.app_download')
            ->setEntityLabelInPlural('admin.app_downloads')
            ->setDefaultSort(['platform' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC'])
            ->setHelp(Crud::PAGE_NEW, 'help.app_download.upload')
            ->setHelp(Crud::PAGE_EDIT, 'help.app_download.upload')
            ->overrideTemplate('crud/index', 'admin/crud/index_tabs.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;
        $maxMb = intdiv($this->storage->maxBytes(), 1024 * 1024);

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            ChoiceField::new('platform')->setChoices(ClientPlatform::choices()),
            TextField::new('version'),
            ChoiceField::new('type')->setChoices(['app_download.type.file' => AppDownload::TYPE_FILE, 'app_download.type.url' => AppDownload::TYPE_URL]),
            Field::new('uploadedFile', 'field.uploaded_file')
                ->setFormType(FileType::class)
                ->setFormTypeOptions(['mapped' => false, 'required' => false])
                ->setHelp(new TranslatableMessage('help.app_download.file', ['%allowed%' => AppDownloadStorage::allowedExtensions(), '%max%' => $maxMb], 'admin'))
                ->onlyOnForms(),
            UrlField::new('externalUrl')->hideOnIndex()->setHelp('help.app_download.external_url'),
            AssociationField::new('clientApp')->hideOnIndex()->setHelp('help.app_download.client_app'),
            TextareaField::new('description')->hideOnIndex(),
            IntegerField::new('sortOrder')->hideOnIndex(),
            BooleanField::new('isActive'),
            AdminFormat::field('size', static fn (mixed $v, ?AppDownload $d): string => null === $d?->getSizeBytes() ? AdminFormat::NONE : AdminFormat::e(SubscriptionFormat::bytes($d->getSizeBytes()))),
            IntegerField::new('downloadCount')->hideOnForm(),
            TextField::new('originalName')->onlyOnDetail(),
            TextField::new('mimeType')->onlyOnDetail(),
            TextField::new('sha256')->onlyOnDetail()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v): string => $f->copyable(null === $v ? null : (string) $v, 64)),
            AdminFormat::field('publicLink', fn (mixed $v, ?AppDownload $d): string => null === $d?->getId() ? AdminFormat::NONE : $f->copyable($this->generateUrl('app_download', ['id' => $d->getId()], UrlGeneratorInterface::ABSOLUTE_URL), 64), 'field.public_link')->onlyOnDetail(),
            DateTimeField::new('createdAt')->onlyOnDetail(),
            DateTimeField::new('updatedAt')->onlyOnDetail(),
        ];
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withUpload(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withUpload(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AppDownload) {
            $entityInstance->touch();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::deleteEntity($entityManager, $entityInstance);
        if ($entityInstance instanceof AppDownload) {
            $this->storage->delete($entityInstance);
        }
    }

    /**
     * Stores the uploaded file once everything else in the form is valid (after validation: priority below the
     * validator's), so a rejected form leaves no file behind.
     */
    private function withUpload(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $download = $form->getData();
            if (!$download instanceof AppDownload || !$form->isValid()) {
                return;
            }

            $file = $form->has('uploadedFile') ? $form->get('uploadedFile')->getData() : null;
            if (!$download->isFile()) {
                // Now a link: its old file (if any) is not needed any more.
                if (null !== $download->getStoredName()) {
                    $this->storage->delete($download);
                    $download->detachStoredFile();
                }

                return;
            }

            if (null === $file) {
                if (null === $download->getStoredName()) {
                    $form->get('uploadedFile')->addError(new FormError($this->translator->trans('app_download.upload.required', [], 'admin')));
                }

                return;
            }

            try {
                $stored = $this->storage->store($file);
            } catch (AppDownloadRejected $e) {
                $form->get('uploadedFile')->addError(new FormError($this->translator->trans($e->getMessage(), $e->parameters, 'admin')));

                return;
            }

            $previous = clone $download;
            $download->attachStoredFile($stored);
            if (null !== $previous->getStoredName()) {
                $this->storage->delete($previous);
            }
        }, -10);

        return $builder;
    }
}
