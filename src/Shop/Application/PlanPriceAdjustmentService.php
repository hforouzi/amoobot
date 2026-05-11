<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;

final class PlanPriceAdjustmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{plans:int,changes:list<array{plan:Plan,field:string,before:int,after:int}>}
     */
    public function preview(string $direction, string $field, ?float $percent, ?int $amount): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->entityManager->getRepository(Plan::class)->findBy([], ['id' => 'ASC']);
        $fields = 'all' === $field ? ['price', 'pricePerGb', 'pricePerDay'] : [$field];
        $changes = [];

        foreach ($plans as $plan) {
            foreach ($fields as $targetField) {
                $before = $this->readField($plan, $targetField);
                if (null === $before) {
                    continue;
                }
                $after = $this->calculateAdjustedValue($before, $direction, $percent, $amount);
                if ($after === $before) {
                    continue;
                }

                $changes[] = [
                    'plan' => $plan,
                    'field' => $targetField,
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return [
            'plans' => count($plans),
            'changes' => $changes,
        ];
    }

    /**
     * @param list<array{plan:Plan,field:string,before:int,after:int}> $changes
     */
    public function apply(array $changes): int
    {
        $applied = 0;
        foreach ($changes as $change) {
            $plan = $change['plan'];
            $this->writeField($plan, (string) $change['field'], (int) $change['after']);
            $plan->setUpdatedAt(new \DateTimeImmutable());
            ++$applied;
        }

        if ($applied > 0) {
            $this->entityManager->flush();
        }

        return $applied;
    }

    private function calculateAdjustedValue(int $before, string $direction, ?float $percent, ?int $amount): int
    {
        $delta = null !== $percent
            ? (int) floor(($before * $percent) / 100)
            : max(0, (int) ($amount ?? 0));

        $after = 'increase' === $direction ? ($before + $delta) : ($before - $delta);

        return max(0, $after);
    }

    private function readField(Plan $plan, string $field): ?int
    {
        return match ($field) {
            'price' => $plan->getPrice(),
            'pricePerGb' => $plan->getPricePerGb(),
            'pricePerDay' => $plan->getPricePerDay(),
            default => null,
        };
    }

    private function writeField(Plan $plan, string $field, int $value): void
    {
        switch ($field) {
            case 'price':
                $plan->setPrice($value);
                break;
            case 'pricePerGb':
                $plan->setPricePerGb($value);
                break;
            case 'pricePerDay':
                $plan->setPricePerDay($value);
                break;
        }
    }
}
