<?php

declare(strict_types=1);

namespace App\Admin\UI\Field;

use App\Admin\UI\Support\AdminEntityLabel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

/**
 * Runs after EasyAdmin's own configurators (priority -10, config/services.yaml) and before a field's formatValue():
 * - a related record shows as AdminEntityLabel names it (cells and form selects), not its English __toString();
 * - dates are "2026-10-21 18:31" everywhere, with the same (Latin) digits as every other number in the admin.
 */
final class AdminValueConfigurator implements FieldConfiguratorInterface
{
    public function __construct(private readonly AdminEntityLabel $labels)
    {
    }

    public function supports(FieldDto $field, EntityDto $entityDto): bool
    {
        return in_array($field->getFieldFqcn(), [AssociationField::class, DateTimeField::class, DateField::class], true);
    }

    public function configure(FieldDto $field, EntityDto $entityDto, AdminContext $context): void
    {
        if (AssociationField::class === $field->getFieldFqcn()) {
            $field->setFormTypeOptionIfNotSet('choice_label', fn (object $entity): string => $this->labels->of($entity));
        }

        if (!in_array($context->getCrud()?->getCurrentPage(), [Crud::PAGE_INDEX, Crud::PAGE_DETAIL], true)) {
            return;
        }

        $value = $field->getValue();
        if (AssociationField::class === $field->getFieldFqcn() && is_object($value) && !$value instanceof \Countable) {
            $field->setFormattedValue($this->labels->of($value));
        } elseif ($value instanceof \DateTimeInterface) {
            $field->setFormattedValue($value->format(DateField::class === $field->getFieldFqcn() ? 'Y-m-d' : 'Y-m-d H:i'));
        }
    }
}
