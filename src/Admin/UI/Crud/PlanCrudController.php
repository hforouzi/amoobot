<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\TranslatedFlashes;
use App\Admin\UI\Support\AdminFormat;
use App\Admin\UI\DashboardController;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Shop\Application\Catalog\InvalidCatalogEntry;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\PlanInboundDrift;
use App\Shop\Application\Catalog\PlanInboundUsage;
use App\Shop\Application\Catalog\PlanInboundWarnings;
use App\Shop\Application\Catalog\PlanInUse;
use App\Shop\Application\Catalog\TrialBackingPlanLocked;
use App\Shop\Application\Catalog\TrialBackingPlans;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Application\PlanPriceSanityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly MultiInboundSettings $multiInbound,
        private readonly PlanInboundUsage $inboundUsage,
        private readonly EntityManagerInterface $entityManager,
        private readonly TrialBackingPlans $trialBacking,
        private readonly PlanInboundDrift $inboundDrift,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Plan::class;
    }

    /**
     * The list, its search and its filters: never a trial's backing plan
     * (docs/design/trial-backing-plans.md). It is managed from the trial plans.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return TrialBackingPlans::exclude(parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters), 'entity');
    }

    /** A backing plan's detail page (a direct URL): the message and its trial plan instead. */
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        return $this->trialBackingRedirect($context) ?? parent::detail($context);
    }

    /** A backing plan's form, and the list's active toggle (a PATCH to this action): refused, nothing changes. */
    public function edit(AdminContext $context): KeyValueStore|Response
    {
        return $this->trialBackingRedirect($context) ?? parent::edit($context);
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        return $this->trialBackingRedirect($context) ?? parent::delete($context);
    }

    /**
     * @param AdminContext<object> $context
     */
    private function trialBackingRedirect(AdminContext $context): ?RedirectResponse
    {
        $plan = $context->getEntity()->getInstance();
        if (!$plan instanceof Plan || !$this->trialBacking->isBacking($plan)) {
            return null;
        }
        $trial = $this->trialBacking->trialPlanOf($plan);
        $this->flashTrialBacking($plan, $trial);
        $url = $this->adminUrlGenerator->unsetAll()->setDashboard(DashboardController::class)->setController(TrialPlanCrudController::class);

        return new RedirectResponse(null === $trial ? $url->setAction(Action::INDEX)->generateUrl() : $url->setAction(Action::DETAIL)->setEntityId($trial->getId())->generateUrl());
    }

    /** "This plan belongs to the trial plan ..." (or to a trial plan deleted since). */
    private function flashTrialBacking(Plan $plan, ?TrialPlan $trial = null): void
    {
        $trial ??= $this->trialBacking->trialPlanOf($plan);
        null === $trial
            ? $this->flash('warning', 'flash.plan.trial_backing_orphan', ['%plan%' => $plan->getTitle()])
            : $this->flash('warning', 'flash.plan.trial_backing', ['%plan%' => $plan->getTitle(), '%trial%' => $trial->getTitle()]);
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
            $this->inboundField($pageName),
            ...$this->driftFields($pageName),
            ...$this->multiInboundFields($pageName),
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
        if ($entityInstance instanceof Plan) {
            $this->savePlan($entityInstance);

            return;
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof Plan) {
            $this->savePlan($entityInstance);

            return;
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * With the switch off the form has no "additional inbounds" field; one sent anyway (a crafted POST) is refused and
     * nothing is saved, rather than silently ignored.
     */
    private function savePlan(Plan $plan): void
    {
        $sent = $this->getContext()?->getRequest()->request->all()['Plan'] ?? [];
        if (!$this->multiInbound->enabled() && is_array($sent) && array_key_exists('additionalInboundList', $sent)) {
            $this->flash('danger', 'flash.plan.additional_inbounds_off');
            // Nothing of this request is kept (a later flush would otherwise write the other fields).
            if (null !== $plan->getId()) {
                $this->entityManager->refresh($plan);
            } else {
                $this->entityManager->detach($plan);
            }

            return;
        }
        $isNew = null === $plan->getId();
        try {
            $this->plans->save($plan);
        } catch (TrialBackingPlanLocked) {
            $this->entityManager->refresh($plan);
            $this->flashTrialBacking($plan);

            return;
        } catch (InvalidCatalogEntry $e) {
            // The form already showed these inline (the same constraint); a rule only the service sees ends here.
            foreach ($e->violations as $messages) {
                foreach ($messages as $message) {
                    $this->flash('danger', 'flash.error', ['%error%' => $message]);
                }
            }

            return;
        }
        $this->flashPriceWarnings($plan);
        foreach (PlanInboundWarnings::of($plan, $this->multiInbound->enabled()) as $warning) {
            $this->flash('warning', $warning['key'], $warning['parameters']);
        }
        // Saving never moves existing services: said when the inbounds changed and some stay on another one (P1).
        $primary = $plan->getInbound();
        if (!$isNew && null !== $primary && ($plan->primaryInboundChanged() || $plan->additionalInboundsChanged())) {
            $drift = $this->inboundDrift->ofPlan($plan);
            foreach (PlanInboundWarnings::drift($plan->getTitle(), $primary->getTitle(), $drift, $this->inboundDrift->breakdown($drift)) as $warning) {
                $this->flash('warning', $warning['key'], $warning['parameters']);
            }
        }
    }

    /**
     * The primary inbound; on the edit page of a plan whose existing services are on another inbound, with the help
     * text that saving does not move them (docs/design/plan-inbound-change.md 3.3 (b)).
     */
    private function inboundField(string $pageName): AssociationField
    {
        $field = AssociationField::new('inbound');
        $plan = Crud::PAGE_EDIT === $pageName ? $this->getContext()?->getEntity()->getInstance() : null;
        if (!$plan instanceof Plan || null === $plan->getId()) {
            return $field;
        }
        $drift = $this->inboundDrift->ofPlan($plan);
        if ($drift->total <= 0) {
            return $field;
        }
        $text = $this->translator->trans('plan_inbound_drift.form', ['%count%' => $drift->total, '%breakdown%' => $this->inboundDrift->breakdown($drift)], 'admin');

        // EasyAdmin renders help as HTML: the text (inbound titles are admin-entered) is escaped here.
        return $field->setHelp(sprintf('<span data-inbound-drift="%d">%s</span>', $drift->total, AdminFormat::e($text)));
    }

    /**
     * The list's "N on old inbound" badge, only while some plan has services on another inbound than the one it sells
     * (one grouped query for the whole page: PlanInboundDrift::totalOf()).
     *
     * @return list<TextField>
     */
    private function driftFields(string $pageName): array
    {
        if (Crud::PAGE_INDEX !== $pageName || !$this->inboundDrift->anyPlan()) {
            return [];
        }
        $t = fn (string $key, array $parameters = []): string => AdminFormat::e($this->translator->trans($key, $parameters, 'admin'));

        // Bound to title: EasyAdmin formats only fields bound to a readable property.
        return [TextField::new('title', 'field.inbound_notice')
            ->onlyOnIndex()
            ->setSortable(false)
            ->setTemplatePath(AdminFormat::TEMPLATE)
            ->formatValue(function (mixed $v, ?Plan $plan) use ($t): string {
                $count = null === $plan?->getId() ? 0 : $this->inboundDrift->totalOf((int) $plan->getId());

                return 0 === $count ? '' : sprintf('<span class="badge badge-warning" data-inbound-drift="%d" title="%s">%s</span>', $count, $t('plan_inbound_drift.badge_title'), $t('plan_inbound_drift.badge', ['%count%' => $count]));
            })];
    }

    /**
     * The "additional inbounds" field and the list's "Inbounds" column, only while plans.multi_inbound_enabled is on
     * (with it off the plan pages are exactly as before).
     *
     * @return list<Field|TextField>
     */
    private function multiInboundFields(string $pageName): array
    {
        if (!$this->multiInbound->enabled()) {
            return [];
        }
        $plan = $this->getContext()?->getEntity()->getInstance();
        $plan = $plan instanceof Plan ? $plan : null;
        $t = fn (string $key, array $parameters = []): string => AdminFormat::e($this->translator->trans($key, $parameters, 'admin'));

        return [
            Field::new('additionalInboundList', 'field.additional_inbounds')
                ->onlyOnForms()
                ->setFormType(EntityType::class)
                ->setFormTypeOptions([
                    'class' => VpnInbound::class,
                    'multiple' => true,
                    'required' => false,
                    'choices' => $this->additionalChoices($plan),
                    'choice_label' => static fn (VpnInbound $i): string => sprintf('%s · %s · %s%s', $i->getTitle(), strtoupper((string) $i->getProtocol()), (string) ($i->getPort() ?? '?'), $i->isActive() ? '' : ' (inactive)'),
                    'group_by' => static fn (VpnInbound $i): string => $i->getPanel()->getTitle(),
                ])
                ->setHelp('help.plan.additional_inbounds'),
            // The count, with a badge when one is inactive or the plan is not sold for it (bound to title: a readable property).
            TextField::new('title', 'field.inbounds')
                ->hideOnForm()
                ->setTemplatePath(AdminFormat::TEMPLATE)
                ->formatValue(fn (mixed $v, ?Plan $p): string => $p instanceof Plan ? $this->inboundsCell($p, $t) : ''),
        ];
    }

    /**
     * Active inbounds of the primary's panel except the primary (a new plan: of every modern 3x-ui panel), plus the
     * ones already chosen so they can be seen and removed even when inactive now.
     *
     * @return list<VpnInbound>
     */
    private function additionalChoices(?Plan $plan): array
    {
        $panel = $plan?->getInbound()?->getPanel();
        $criteria = ['isActive' => true] + (null !== $panel ? ['panel' => $panel] : []);
        $inbounds = $this->entityManager->getRepository(VpnInbound::class)->findBy($criteria, ['id' => 'ASC']);
        $choices = [];
        foreach ([...$plan?->getAdditionalInbounds() ?? [], ...$inbounds] as $inbound) {
            $isPanelOk = null !== $panel || ('sanaei_3xui' === $inbound->getPanel()->getType() && $inbound->getPanel()->isModern());
            if ($inbound !== $plan?->getInbound() && ($isPanelOk || in_array($inbound, $plan?->getAdditionalInbounds() ?? [], true))) {
                $choices[spl_object_id($inbound)] = $inbound;
            }
        }

        return array_values($choices);
    }

    /**
     * @param \Closure(string, array<string, mixed>=): string $t
     */
    private function inboundsCell(Plan $plan, \Closure $t): string
    {
        $additional = null === $plan->getId() ? [] : $this->inboundUsage->additionalInboundsOf((int) $plan->getId());
        $cell = $t('plan_inbounds.count', ['%count%' => (null === $plan->getInbound() ? 0 : 1) + count($additional)]);
        if ([] === $additional) {
            return $cell;
        }
        $blocker = $this->inboundUsage->saleBlockerOf((int) $plan->getId());
        if (null !== $blocker) {
            $cell .= ' <span class="badge badge-secondary" data-not-sold="'.$blocker.'" title="'.$t('plan_inbounds.not_sold.'.$blocker).'">'.$t('plan_inbounds.not_sold_badge').'</span>';
        }
        $inactive = array_values(array_filter($additional, static fn (array $i): bool => !$i['active']));
        if ([] !== $inactive) {
            $cell .= ' <span class="badge badge-warning" data-inactive-inbound>⚠ '.$t('plan_inbounds.inactive_badge', ['%count%' => count($inactive)]).'</span>';
        }

        return $cell;
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
        } catch (TrialBackingPlanLocked) {
            // A batch delete that names one (the list never shows it).
            $this->flashTrialBacking($entityInstance);
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
