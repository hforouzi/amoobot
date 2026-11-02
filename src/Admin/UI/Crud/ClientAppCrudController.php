<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminFormat;
use App\Entity\ClientApp;
use App\MiniApp\Guide\GuideClientApps;
use App\Subscription\Domain\ClientPlatform;
use App\Subscription\Domain\ImportLinkTemplate;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Apps page, "client apps" tab (AdminTabs): the VPN apps offered on the subscription page with their one-tap
 * "add subscription" link (ImportLinkTemplate placeholders) and install link. The active ones are also the apps of the
 * Mini App guide's "Install and connect" topic (GuideClientApps): the list says so and warns about each device no
 * active app is set up for.
 */
#[AdminRoute(path: '/apps/clients', name: 'apps_clients')]
final class ClientAppCrudController extends AbstractCrudController
{
    /** What the detail page previews the import link with. */
    private const EXAMPLE_URL = 'https://sub.example.com/sub/abc123';

    public function __construct(
        private readonly GuideClientApps $guideApps,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ClientApp::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.client_app')
            ->setEntityLabelInPlural('admin.client_apps')
            ->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'help.client_app.index')
            ->overrideTemplate('crud/index', 'admin/client_app/index.html.twig');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $responseParameters->set('guide_missing_platforms', $this->guideApps->platformsWithoutApps());
        }

        return $responseParameters;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('icon')->setHelp('help.client_app.icon'),
            TextField::new('name'),
            ChoiceField::new('platforms')->setChoices(ClientPlatform::choices())->allowMultipleChoices()->renderExpanded(),
            TextareaField::new('importUrlTemplate')->hideOnIndex()->setHelp('help.client_app.template')
                ->setFormTypeOption('attr', ['dir' => 'ltr', 'rows' => 2]),
            AdminFormat::field('importExample', static fn (mixed $v, ?ClientApp $app): string => null === ($link = ImportLinkTemplate::build($app?->getImportUrlTemplate(), self::EXAMPLE_URL, 'VPN')) ? AdminFormat::NONE : '<code dir="ltr">'.AdminFormat::e($link).'</code>', 'field.import_example')->onlyOnDetail(),
            UrlField::new('storeUrl')->hideOnIndex()->setHelp('help.client_app.store_url'),
            IntegerField::new('sortOrder'),
            BooleanField::new('isActive'),
            TextareaField::new('notes')->hideOnIndex()->setHelp('help.client_app.notes'),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->onlyOnDetail(),
        ];
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ClientApp) {
            $entityInstance->touch();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
