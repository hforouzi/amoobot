<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\TranslatedFlashes;
use App\Entity\TrialPlan;
use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\TrialPlanAdminService;
use App\Shop\Application\Catalog\TrialPlanDeletion;
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
        private readonly AdminAuditLogger $auditLogger,
    ) {
    }

    /** Saving and deleting go through TrialPlanAdminService, as from the Mini App. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof TrialPlan ? $this->trialPlans->save($entityInstance) : parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof TrialPlan ? $this->trialPlans->save($entityInstance) : parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * The trial plan and, when nothing refers to it, its backing plan (TrialPlanAdminService::delete()); the message says
     * which. The trial plan's own deletion is audited by AdminAuditEntitySubscriber, the backing plan's fate here.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof TrialPlan) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }
        $title = $entityInstance->getTitle();
        $deletion = $this->trialPlans->delete($entityInstance);
        if (null !== $deletion->backingPlanId) {
            $this->auditLogger->record('trial_plan.backing_plan_'.(TrialPlanDeletion::BACKING_KEPT === $deletion->outcome ? 'kept' : 'deleted'), 'Plan', $deletion->backingPlanId, ['trialPlan' => $title] + $deletion->auditDetails());
        }
        $this->flash(TrialPlanDeletion::BACKING_KEPT === $deletion->outcome ? 'warning' : 'success', 'flash.trial_plan.deleted.'.$deletion->outcome, [
            '%trial%' => $title,
            '%plan%' => (string) $deletion->backingPlanTitle,
            '%orders%' => $deletion->orders,
            '%services%' => $deletion->services,
        ]);
        if ([] !== $deletion->deactivatedDiscountCodes) {
            $this->flash('warning', 'flash.trial_plan.deleted.codes_deactivated', ['%codes%' => implode(', ', $deletion->deactivatedDiscountCodes)]);
        }
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
            // Its hidden plan, as text: it has no page in the Plans area (TrialBackingPlans).
            TextField::new('backingPlan')
                ->onlyOnDetail()
                ->setHelp('help.trial_plan.backing_plan'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
