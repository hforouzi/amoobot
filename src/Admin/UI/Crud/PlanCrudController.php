<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\TranslatedFlashes;
use App\Admin\UI\Support\AdminFormat;
use App\Admin\UI\DashboardController;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Shop\Application\Catalog\InvalidCatalogEntry;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\PlanInboundUsage;
use App\Shop\Application\Catalog\PlanInboundWarnings;
use App\Shop\Application\Catalog\PlanInUse;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Application\PlanPriceSanityChecker;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
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
        try {
            $this->plans->save($plan);
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
