<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\TranslatedFlashes;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Shop\Application\Catalog\PlanInboundDrift;
use App\Shop\Application\Catalog\PlanInboundWarnings;
use App\Shop\Application\Catalog\TrialPlanAdminService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class TrialPlanCrudController extends AbstractCrudController
{
    use TranslatedFlashes;

    public function __construct(
        private readonly TrialPlanAdminService $trialPlans,
        private readonly PlanInboundDrift $inboundDrift,
    ) {
    }

    /** Saving and deleting go through TrialPlanAdminService, as from the Mini App. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof TrialPlan ? $this->trialPlans->save($entityInstance) : parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof TrialPlan) {
            parent::updateEntity($entityManager, $entityInstance);

            return;
        }
        $before = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['inbound'] ?? null;
        $this->trialPlans->save($entityInstance);
        $this->flashDrift($entityInstance, $before instanceof VpnInbound ? $before->getId() : null);
    }

    /**
     * The trial's inbound changed: its existing services stay on the old one (design plan-inbound-change 3.3 (a),
     * trial wording). They belong to the trial's hidden backing plan; new trials get the trial plan's inbound.
     */
    private function flashDrift(TrialPlan $trial, ?int $inboundBefore): void
    {
        $inbound = $trial->getInbound();
        $backing = $trial->getBackingPlan();
        if (null === $inbound || null === $backing || $inbound->getId() === $inboundBefore) {
            return;
        }
        $drift = $this->inboundDrift->ofPlan($backing, $inbound);
        foreach (PlanInboundWarnings::drift($trial->getTitle(), $inbound->getTitle(), $drift, $this->inboundDrift->breakdown($drift), 'plan_inbound_drift.trial_plan_saved') as $warning) {
            $this->flash('warning', $warning['key'], $warning['parameters']);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof TrialPlan ? $this->trialPlans->delete($entityInstance) : parent::deleteEntity($entityManager, $entityInstance);
    }

    public static function getEntityFqcn(): string
    {
        return TrialPlan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            TextareaField::new('description')->hideOnIndex(),
            BooleanField::new('isActive'),
            IntegerField::new('sortOrder'),
            IntegerField::new('durationDays'),
            IntegerField::new('trafficGb'),
            IntegerField::new('ipLimit')
                ->setHelp('help.trial_plan.ip_limit'),
            AssociationField::new('inbound'),
            IntegerField::new('maxClaimsTotal')
                ->setHelp('help.trial_plan.max_claims_total')
                ->hideOnIndex(),
            IntegerField::new('maxClaimsPerUser'),
            IntegerField::new('cooldownHours')
                ->setHelp('help.trial_plan.cooldown_hours')
                ->hideOnIndex(),
            AssociationField::new('backingPlan')
                ->hideOnForm()
                ->hideOnIndex(),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
