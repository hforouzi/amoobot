<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\GuideTextAdminController;
use App\Bot\Application\BotContentRegistry;
use App\Bot\Application\BotTextResolver;
use App\Entity\BotMessageTemplate;
use App\MiniApp\Guide\GuidePreview;
use App\MiniApp\Guide\GuideStateProvider;
use App\MiniApp\Guide\GuideTextRules;
use App\MiniApp\Guide\GuideTopic;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Bot texts page, "message templates" tab (AdminTabs); was /admin/bot-message-template (redirects here).
 *
 * The Mini App guide's texts (guide.<topic>.title / .body, docs/design/help-guide.md, sections 5 and 6) are rows of
 * category "guide" and parse mode "guide" (offered for those keys only; BotMessageTemplate::validateGuideText checks
 * them on save). They have no row until the admin customizes one: with the "Guide" category filter the list starts
 * with every guide text in both languages, Default or Customized, and "Customize" opens a new row filled with the
 * shipped default. Their edit page shows the live preview and "Reset to default" (GuideTextAdminController).
 */
#[AdminRoute(path: '/bot-texts/messages', name: 'bot_texts_messages')]
final class BotMessageTemplateCrudController extends AbstractCrudController
{
    private const LOCALES = ['fa', 'en'];

    public function __construct(
        private readonly BotContentRegistry $registry,
        private readonly BotTextResolver $texts,
        private readonly GuidePreview $preview,
        private readonly GuideStateProvider $guideState,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BotMessageTemplate::class;
    }

    /** The list filtered on the guide's texts (with their overview). */
    public static function guideIndexUrl(AdminUrlGenerator $urls): string
    {
        return $urls->unsetAll()->setController(self::class)->setAction(Action::INDEX)
            ->set('filters', ['category' => ['comparison' => '=', 'value' => GuideTextRules::CATEGORY]])->generateUrl();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.bot_message_template')
            ->setEntityLabelInPlural('admin.bot_message_templates')
            ->setDefaultSort(['updatedAt' => 'DESC', 'id' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/bot_text/messages_index.html.twig')
            ->overrideTemplate('crud/edit', 'admin/bot_text/message_edit.html.twig')
            ->overrideTemplate('crud/new', 'admin/bot_text/message_new.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $guide = Action::new('guideTexts', 'guide_text.overview.action')
            ->linkToUrl(fn (): string => self::guideIndexUrl($this->adminUrlGenerator))
            ->createAsGlobalAction();

        return $actions->add(Crud::PAGE_INDEX, $guide);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $categories = [];
        foreach (self::LOCALES as $locale) {
            foreach ($this->registry->messageTemplates($locale) as $template) {
                $categories[$template['category']] = $template['category'];
            }
        }
        ksort($categories);

        return $filters->add(ChoiceFilter::new('category')->setChoices(['bot_text.category.guide' => GuideTextRules::CATEGORY] + $categories));
    }

    public function configureFields(string $pageName): iterable
    {
        $isEdit = Crud::PAGE_EDIT === $pageName;
        $instance = $this->getContext()?->getEntity()->getInstance();
        $isGuide = in_array($pageName, [Crud::PAGE_EDIT, Crud::PAGE_NEW], true)
            && $instance instanceof BotMessageTemplate && GuideTextRules::isGuideKey($instance->getKey());
        $modes = ['bot_text.parse_mode.html' => 'html', 'bot_text.parse_mode.markdown' => 'markdown', 'bot_text.parse_mode.plain' => 'plain'];
        $guideMode = ['bot_text.parse_mode.guide' => GuideTextRules::PARSE_MODE];

        $body = TextareaField::new('body')->hideOnIndex();
        if ($isGuide) {
            $body->setHelp('guide_text.help')->setFormTypeOption('attr', ['rows' => 14, 'data-guide-text' => '', 'dir' => 'auto']);
        }

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('key')->setFormTypeOption('disabled', $isEdit),
            ChoiceField::new('locale')->setChoices(['locale.fa' => 'fa', 'locale.en' => 'en'])->setFormTypeOption('disabled', $isEdit),
            TextField::new('category')->setFormTypeOption('disabled', $isEdit),
            TextField::new('title'),
            $body,
            // "Guide" only for the guide's keys: alone on their form; on a new row it can be picked (saving it on any
            // other key is refused), never on the edit page of another text.
            ChoiceField::new('parseMode')->setChoices($isGuide ? $guideMode : ($isEdit ? $modes : $modes + $guideMode)),
            TextareaField::new('variablesJson')
                ->setFormTypeOption('disabled', true)
                ->hideOnIndex(),
            BooleanField::new('isActive'),
            BooleanField::new('isSystem')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->onlyOnIndex(),
        ];
    }

    /** "Customize" of a guide text (?key=guide.<topic>.<part>&locale=fa|en): a new row with the shipped default. */
    public function createEntity(string $entityFqcn): BotMessageTemplate
    {
        $row = new BotMessageTemplate();
        $request = $this->getContext()?->getRequest();
        $key = (string) $request?->query->get('key', '');
        $locale = (string) $request?->query->get('locale', '');
        if (GuideTextRules::isGuideKey($key) && in_array($locale, self::LOCALES, true)) {
            $row->setKey($key)->setLocale($locale)->setTitle($key)->setCategory(GuideTextRules::CATEGORY)
                ->setParseMode(GuideTextRules::PARSE_MODE)->setIsSystem(false)
                ->setBody(trim((string) $this->texts->shippedText($key, $locale)));
        }

        return $row;
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof BotMessageTemplate) {
            $entityInstance->touch();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $pageName = $responseParameters->get('pageName');
        if (Crud::PAGE_INDEX === $pageName && $this->isGuideFilter()) {
            $responseParameters->set('guide_overview', $this->guideOverview());
        }
        if (in_array($pageName, [Crud::PAGE_EDIT, Crud::PAGE_NEW], true)) {
            $instance = $this->getContext()?->getEntity()->getInstance();
            if ($instance instanceof BotMessageTemplate && GuideTextRules::isGuideKey($instance->getKey())) {
                $responseParameters->set('guide_preview', [
                    'key' => $instance->getKey(),
                    'locale' => $instance->getLocale(),
                    'initial' => $this->preview->preview($instance->getKey(), $instance->getLocale(), $instance->getBody()),
                    'url' => $this->generateUrl('admin_bot_texts_guide_preview'),
                    'csrf' => GuideTextAdminController::CSRF,
                    'resetUrl' => null !== $instance->getId() ? $this->generateUrl('admin_bot_texts_guide_reset', ['id' => $instance->getId()]) : null,
                ]);
            }
        }

        return $responseParameters;
    }

    private function isGuideFilter(): bool
    {
        $filters = $this->getContext()?->getRequest()->query->all('filters') ?? [];
        $category = is_array($filters['category'] ?? null) ? $filters['category'] : [];

        return GuideTextRules::CATEGORY === ($category['value'] ?? null);
    }

    /**
     * Every guide text in both languages: its row (if the admin customized it) and the link to edit or customize it.
     *
     * @return list<array{key: string, topic: string, part: string, visible: bool, locales: array<string, array{row: ?BotMessageTemplate, url: string}>}>
     */
    private function guideOverview(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(BotMessageTemplate::class)->findBy(['key' => GuideTextRules::keys()]) as $row) {
            $rows[$row->getKey()][$row->getLocale()] = $row;
        }
        $state = $this->guideState->current();
        $overview = [];
        foreach (GuideTextRules::keys() as $key) {
            $topic = GuidePreview::topic($key);
            \assert($topic instanceof GuideTopic);
            $locales = [];
            foreach (self::LOCALES as $locale) {
                $row = $rows[$key][$locale] ?? null;
                $urls = $this->adminUrlGenerator->unsetAll()->setController(self::class);
                $locales[$locale] = [
                    'row' => $row,
                    'url' => null !== $row
                        ? $urls->setAction(Action::EDIT)->setEntityId($row->getId())->generateUrl()
                        : $urls->setAction(Action::NEW)->set('key', $key)->set('locale', $locale)->generateUrl(),
                ];
            }
            $overview[] = ['key' => $key, 'topic' => $topic->value, 'part' => (string) GuideTextRules::part($key), 'visible' => $topic->isVisible($state), 'locales' => $locales];
        }

        return $overview;
    }
}
