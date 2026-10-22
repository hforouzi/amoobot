<?php

declare(strict_types=1);

namespace App\MiniApp\Admin\Catalog;

use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\MiniApp\Admin\AdminText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts a Mini App catalog form (the JSON app.js sends: text fields, numbers as typed, Persian digits included, ticked
 * boxes as "1", unticked ones absent) onto a plan, trial plan or discount code. It only converts input; the rules are
 * the entity's (checked by the catalog admin services). Returns the fields it could not read, as translation keys.
 *
 * The field lists are the web forms' (PlanCrudController, TrialPlanCrudController, DiscountCodeCrudController).
 */
final class CatalogInput
{
    public const PLAN_FIELDS = [
        'title' => 'text', 'description' => 'text?', 'durationDays' => 'int', 'trafficGb' => 'int?', 'ipLimit' => 'int?', 'price' => 'int',
        'inbound' => 'inbound', 'isActive' => 'bool', 'isCustomizable' => 'bool', 'minTrafficGb' => 'int?', 'maxTrafficGb' => 'int?',
        'pricePerGb' => 'int?', 'minDurationDays' => 'int?', 'maxDurationDays' => 'int?', 'pricePerDay' => 'int?',
        'allowCustomUsername' => 'bool', 'isUnlimitedDuration' => 'bool',
    ];
    public const TRIAL_FIELDS = [
        'title' => 'text', 'description' => 'text?', 'isActive' => 'bool', 'sortOrder' => 'int', 'durationDays' => 'int', 'trafficGb' => 'int?',
        'ipLimit' => 'int?', 'inbound' => 'inbound', 'maxClaimsTotal' => 'int?', 'maxClaimsPerUser' => 'int', 'cooldownHours' => 'int?',
    ];
    public const DISCOUNT_FIELDS = [
        'code' => 'text', 'title' => 'text?', 'type' => 'text', 'value' => 'int', 'isActive' => 'bool', 'startsAt' => 'date?', 'endsAt' => 'date?',
        'maxUses' => 'int?', 'maxUsesPerUser' => 'int?', 'firstPurchaseOnly' => 'bool', 'appliesTo' => 'text', 'plan' => 'plan', 'minAmount' => 'int?',
    ];
    /** The price fields: a change to them is confirmed (old -> new) and audited with its values. */
    public const PRICE_FIELDS = ['price', 'pricePerGb', 'pricePerDay'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string> field => error key
     */
    public function toPlan(Plan $plan, array $body): array
    {
        return $this->apply($plan, self::PLAN_FIELDS, $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    public function toTrialPlan(TrialPlan $trialPlan, array $body): array
    {
        return $this->apply($trialPlan, self::TRIAL_FIELDS, $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    public function toDiscount(DiscountCode $code, array $body): array
    {
        return $this->apply($code, self::DISCOUNT_FIELDS, $body);
    }

    /**
     * The values of these fields as the forms show them (for the audit's "what changed").
     *
     * @param array<string, string> $fields
     *
     * @return array<string, scalar|null>
     */
    public static function snapshot(object $entity, array $fields): array
    {
        $values = [];
        foreach (array_keys($fields) as $field) {
            $value = self::read($entity, $field);
            $values[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i'),
                is_object($value) && method_exists($value, 'getId') => $value->getId(),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed>  $body
     *
     * @return array<string, string>
     */
    private function apply(object $entity, array $fields, array $body): array
    {
        $errors = [];
        foreach ($fields as $field => $kind) {
            $raw = $body[$field] ?? null;
            $text = is_scalar($raw) ? trim((string) $raw) : '';
            $optional = str_ends_with($kind, '?');
            $type = rtrim($kind, '?');

            if ('bool' === $type) {
                $this->write($entity, $field, in_array($text, ['1', 'true', 'on'], true));
                continue;
            }
            if ('' === $text) {
                if (in_array($type, ['inbound', 'plan'], true) || $optional) {
                    $this->write($entity, $field, null);
                } elseif ('text' === $type) {
                    // Empty text is the entity's rule to refuse (NotBlank), with its own message.
                    $this->write($entity, $field, '');
                } else {
                    $errors[$field] = 'admin.form.required';
                }
                continue;
            }

            switch ($type) {
                case 'text':
                    $this->write($entity, $field, mb_substr($text, 0, 5000));
                    break;
                case 'int':
                    $digits = AdminText::latinDigits(str_replace([',', '٬', '،', ' '], '', $text));
                    if (1 !== preg_match('/^-?\d{1,12}$/', $digits)) {
                        $errors[$field] = 'admin.form.number';
                        break;
                    }
                    $this->write($entity, $field, (int) $digits);
                    break;
                case 'date':
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', AdminText::latinDigits($text)) ?: \DateTimeImmutable::createFromFormat('!Y-m-d', AdminText::latinDigits($text));
                    if (false === $date) {
                        $errors[$field] = 'admin.form.date';
                        break;
                    }
                    $this->write($entity, $field, $date);
                    break;
                case 'inbound':
                case 'plan':
                    $related = ctype_digit($text) ? $this->entityManager->find('inbound' === $type ? VpnInbound::class : Plan::class, (int) $text) : null;
                    if (null === $related) {
                        $errors[$field] = 'admin.form.choice';
                        break;
                    }
                    $this->write($entity, $field, $related);
                    break;
            }
        }

        return $errors;
    }

    private function write(object $entity, string $field, mixed $value): void
    {
        $setter = 'set'.ucfirst($field);
        if (!method_exists($entity, $setter)) {
            throw new \LogicException(sprintf('%s has no %s().', $entity::class, $setter));
        }
        $entity->{$setter}($value);
    }

    private static function read(object $entity, string $field): mixed
    {
        foreach (['get'.ucfirst($field), $field, 'is'.ucfirst($field)] as $getter) {
            if (method_exists($entity, $getter)) {
                return $entity->{$getter}();
            }
        }

        return null;
    }
}
