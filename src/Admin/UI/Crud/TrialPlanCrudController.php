<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\TrialPlan;
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
    public function __construct(
        private readonly TrialPlanAdminService $trialPlans,
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
