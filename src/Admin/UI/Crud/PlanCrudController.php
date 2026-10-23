<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\TranslatedFlashes;
use App\Admin\UI\Support\AdminFormat;
use App\Admin\UI\DashboardController;
use App\Entity\Plan;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\PlanInUse;
use App\Shop\Application\PlanPriceSanityChecker;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PlanCrudController extends AbstractCrudController
{
    use TranslatedFlashes;

    private const TOMAN_HELP = 'help.plan.toman';

    public function __construct(
        private readonly PlanPriceSanityChecker $priceSanityChecker,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly AdminFormat $format,
        private readonly TranslatorInterface $translator,
        private readonly PlanAdminService $plans,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Plan::class;
    }

    /**
     * Trial plans, trial claims and the bulk price adjustment are reached from here (not from the menu).
     */
    public function configureActions(Actions $actions): Actions
    {
        $trialPlans = Action::new('trialPlans', 'admin.trial_plans', 'fa fa-gift')
            ->linkToUrl(fn (): string => $this->indexUrl(TrialPlanCrudController::class))
            ->createAsGlobalAction();
        $trialClaims = Action::new('trialClaims', 'admin.trial_claims', 'fa fa-check-square-o')
            ->linkToUrl(fn (): string => $this->indexUrl(TrialClaimCrudController::class))
            ->createAsGlobalAction();
        $bulkPrices = Action::new('bulkPriceAdjustment', 'admin.bulk_plan_price_adjustment', 'fa fa-percent')
            ->linkToRoute('admin_bulk_plan_price_adjustment')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, $trialPlans)
            ->add(Crud::PAGE_INDEX, $trialClaims)
            ->add(Crud::PAGE_INDEX, $bulkPrices);
    }

    /**
     * @param class-string $crudController
     */
    private function indexUrl(string $crudController): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController($crudController)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }

    /**
     * List: title, duration, traffic, price (store currency), inbound, active. The price check and the custom-plan
     * settings are on the detail page.
     */
    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;
        $t = fn (string $key, array $parameters = []): string => AdminFormat::e($this->translator->trans($key, $parameters, 'admin'));

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            TextareaField::new('description')->hideOnIndex(),
            IntegerField::new('durationDays')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v, ?Plan $plan): string => true === $plan?->isUnlimitedDuration() ? $t('format.unlimited') : $t('format.days', ['%count%' => (int) $v])),
            IntegerField::new('trafficGb')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => '<bdi>'.number_format((int) $v).' GB</bdi>'),
            IntegerField::new('ipLimit')->hideOnIndex()->setHelp('help.plan.ip_limit'),
            // The price, flagged when it looks like the wrong unit (PlanPriceSanityChecker).
            IntegerField::new('price')->setHelp(self::TOMAN_HELP)->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(fn (mixed $v, ?Plan $plan): string => $f->money($v).($plan instanceof Plan ? $this->unitWarningBadge($plan) : '')),
            AssociationField::new('inbound'),
            BooleanField::new('isActive'),

            // Detail page and form.
            // EasyAdmin only formats fields bound to a readable property, so the price check is bound to title.
            TextField::new('title', 'field.unit_check')
                ->onlyOnDetail()
                ->setTemplatePath(AdminFormat::TEMPLATE)
                ->formatValue(fn (mixed $value, ?Plan $plan): string => $plan instanceof Plan ? $this->unitCheckHtml($plan) : ''),
            BooleanField::new('isCustomizable')->hideOnIndex()->setHelp('help.plan_custom_pricing'),
            IntegerField::new('minTrafficGb')->hideOnIndex(),
            IntegerField::new('maxTrafficGb')->hideOnIndex(),
            IntegerField::new('pricePerGb')->hideOnIndex()->setHelp(self::TOMAN_HELP),
            IntegerField::new('minDurationDays')->hideOnIndex(),
            IntegerField::new('maxDurationDays')->hideOnIndex(),
            IntegerField::new('pricePerDay')->hideOnIndex()->setHelp(self::TOMAN_HELP),
            BooleanField::new('allowCustomUsername')->hideOnIndex(),
            BooleanField::new('isUnlimitedDuration')->hideOnIndex()->setHelp('help.plan.is_unlimited_duration'),
            IntegerField::new('resellerFloorAmount')->hideOnIndex()->setHelp('help.plan.reseller_floor_amount'),
            DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),
        ];
    }

    /** Saving and deleting go through PlanAdminService, as from the bot and the Mini App. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof Plan ? $this->plans->save($entityInstance) : parent::persistEntity($entityManager, $entityInstance);
        $this->flashPriceWarnings($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof Plan ? $this->plans->save($entityInstance) : parent::updateEntity($entityManager, $entityInstance);
        $this->flashPriceWarnings($entityInstance);
    }

    /** A plan that orders refer to stays (PlanInUse): a message instead of the database's refusal. */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof Plan) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }
        try {
            $this->plans->delete($entityInstance);
        } catch (PlanInUse $e) {
            $this->flash('danger', 'flash.plan.in_use', ['%plan%' => $entityInstance->getTitle(), '%count%' => $e->orderCount]);
        }
    }

    private function flashPriceWarnings(object $plan): void
    {
        if (!$plan instanceof Plan) {
            return;
        }

        foreach ($this->priceSanityChecker->warnings($plan) as $warning) {
            // The checker's own wording as the detail (flash() escapes it and the admin-entered title).
            $this->flash('warning', 'flash.plan.price_warning', ['%plan%' => $plan->getTitle(), '%warning%' => $warning]);
        }
    }

    private function unitWarningBadge(Plan $plan): string
    {
        $warnings = $this->priceSanityChecker->warnings($plan);

        return [] === $warnings ? '' : sprintf(' <span class="badge badge-warning" data-unit-warning title="%s">⚠ %s</span>', AdminFormat::e(implode(' ', $warnings)), AdminFormat::e($this->translator->trans('plan.unit_check_warning', [], 'admin')));
    }

    private function unitCheckHtml(Plan $plan): string
    {
        $warnings = $this->priceSanityChecker->warnings($plan);
        if ([] === $warnings) {
            return '<span class="badge badge-success">'.AdminFormat::e($this->translator->trans('plan.unit_ok', [], 'admin')).'</span>';
        }

        // The checker's own wording (it names the field and the expected range).
        return sprintf('<span class="badge badge-warning">%s</span> <span dir="ltr">%s</span>', AdminFormat::e($this->translator->trans('plan.unit_check_warning', [], 'admin')), AdminFormat::e(implode(' ', $warnings)));
    }
}
