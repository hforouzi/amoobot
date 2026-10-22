<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * A plan, trial plan or discount code that breaks the catalog rules (the entity's constraints): nothing was saved.
 * The violations are by field, already translated, for inline errors.
 */
final class InvalidCatalogEntry extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $violations field (property path) => messages
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The catalog entry breaks its rules: '.implode(', ', array_keys($violations)));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function byField(ConstraintViolationListInterface $list): array
    {
        $violations = [];
        foreach ($list as $violation) {
            $violations[$violation->getPropertyPath() ?: '_'][] = (string) $violation->getMessage();
        }

        return $violations;
    }
}
