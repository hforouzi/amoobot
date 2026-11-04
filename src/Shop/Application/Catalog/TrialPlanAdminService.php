<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Plan;
use App\Entity\TrialPlan;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * An admin's changes to trial plans, the same from the web panel (TrialPlanCrudController) and the Mini App: validate
 * (the entity's rules), save, activate / deactivate, delete. Callers check the permission (PLANS_MANAGE) and audit.
 *
 * A trial plan's backing plan (TrialBackingPlans, docs/design/trial-backing-plans.md) lives and dies with it: a
 * deactivated trial plan is not offered to customers any more (TrialCheckout lists and claims active ones only); a
 * deleted one takes its backing plan along when nothing refers to it, else leaves it inactive and hidden (delete()).
 */
final class TrialPlanAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, list<string>> field => messages; empty when the trial plan keeps the rules
     */
    public function violations(TrialPlan $trialPlan): array
    {
        return InvalidCatalogEntry::byField($this->validator->validate($trialPlan));
    }

    /**
     * @throws InvalidCatalogEntry nothing saved
     */
    public function save(TrialPlan $trialPlan): void
    {
        $violations = $this->violations($trialPlan);
        if ([] !== $violations) {
            throw new InvalidCatalogEntry($violations);
        }
        if (null === $trialPlan->getId()) {
            $this->entityManager->persist($trialPlan);
        } else {
            $trialPlan->setUpdatedAt(new \DateTimeImmutable());
        }
        $this->entityManager->flush();
    }

    /** @return bool false when the trial plan already was in that state (nothing written) */
    public function setActive(TrialPlan $trialPlan, bool $active): bool
    {
        if ($trialPlan->isActive() === $active) {
            return false;
        }
        $trialPlan->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /**
     * Deletes the trial plan (its claims go with it: trial_claim.trial_plan_id is ON DELETE CASCADE, as before) and
     * decides about its backing plan, in one transaction:
     * - orders refer to it (every claim made one; services and claims hang off them): it is kept, inactive and at
     *   price 0, hidden from the sales area by those trial orders (TrialBackingPlans) and never sold;
     * - nothing does: it is deleted too (with its wholesale rules; a discount code limited to it is deactivated, not
     *   widened to every plan by the foreign key's SET NULL).
     *
     * Lock order, the same as a claim (TrialAccountService::claim()): the trial_plan row, then the backing plan row. A
     * claim that started first has committed its order before the count below sees it; one that starts later finds no
     * trial plan. No orders can appear otherwise: renewals and extra traffic need a service, which needs an order.
     */
    public function delete(TrialPlan $trialPlan): TrialPlanDeletion
    {
        $id = $trialPlan->getId();
        if (null === $id) {
            return new TrialPlanDeletion(TrialPlanDeletion::ALREADY_GONE);
        }
        $backing = $trialPlan->getBackingPlan();

        $result = $this->entityManager->getConnection()->transactional(static function (Connection $db) use ($id): TrialPlanDeletion {
            $row = $db->fetchAssociative('SELECT id, backing_plan_id FROM trial_plan WHERE id = ? FOR UPDATE', [$id]);
            if (false === $row) {
                return new TrialPlanDeletion(TrialPlanDeletion::ALREADY_GONE);
            }
            $claims = (int) $db->fetchOne('SELECT COUNT(*) FROM trial_claim WHERE trial_plan_id = ?', [$id]);
            $planId = null === $row['backing_plan_id'] ? null : (int) $row['backing_plan_id'];
            $title = null === $planId ? false : $db->fetchOne('SELECT title FROM plan WHERE id = ? FOR UPDATE', [$planId]);
            if (null === $planId || false === $title) {
                $db->executeStatement('DELETE FROM trial_plan WHERE id = ?', [$id]);

                return new TrialPlanDeletion(TrialPlanDeletion::NO_BACKING, claims: $claims);
            }
            $orders = (int) $db->fetchOne('SELECT COUNT(*) FROM `order` WHERE plan_id = ?', [$planId]);
            $services = (int) $db->fetchOne('SELECT COUNT(*) FROM vpn_service s JOIN `order` o ON o.id = s.order_id WHERE o.plan_id = ?', [$planId]);
            $db->executeStatement('DELETE FROM trial_plan WHERE id = ?', [$id]);

            if ($orders > 0) {
                $db->executeStatement('UPDATE plan SET is_active = 0, price = 0, updated_at = ? WHERE id = ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $planId]);

                return new TrialPlanDeletion(TrialPlanDeletion::BACKING_KEPT, $planId, (string) $title, $orders, $services, $claims);
            }

            $codes = array_map('strval', $db->fetchFirstColumn('SELECT code FROM discount_code WHERE plan_id = ? ORDER BY id', [$planId]));
            if ([] !== $codes) {
                $db->executeStatement('UPDATE discount_code SET is_active = 0 WHERE plan_id = ?', [$planId]);
            }
            $db->executeStatement('DELETE FROM reseller_plan_price WHERE plan_id = ?', [$planId]);
            // Order drafts and additional inbound rows go with it (ON DELETE CASCADE).
            $db->executeStatement('DELETE FROM plan WHERE id = ?', [$planId]);

            return new TrialPlanDeletion(TrialPlanDeletion::BACKING_DELETED, $planId, (string) $title, claims: $claims, deactivatedDiscountCodes: $codes);
        });

        // Written past the ORM: these entities are not to be flushed again.
        $this->forget($trialPlan);
        if ($backing instanceof Plan) {
            $this->forget($backing);
        }

        return $result;
    }

    private function forget(object $entity): void
    {
        if ($this->entityManager->contains($entity)) {
            $this->entityManager->detach($entity);
        }
    }
}
