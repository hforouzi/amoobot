<?php

declare(strict_types=1);

namespace App\Admin\UI\Field;

use App\Admin\UI\Support\AdminJsonFormatter;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Read-only display of an array/JSON property: pretty-printed, escaped, sensitive keys masked (AdminJsonFormatter).
 * Unlike TextField/TextareaField it accepts arrays, so the detail page cannot fail on them; it is never in a form or
 * on the index.
 */
final class JsonField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, TranslatableInterface|string|bool|null $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('admin/field/json.html.twig')
            ->formatValue(static fn (mixed $value): string => AdminJsonFormatter::toPrettyHtml($value))
            ->hideOnForm()
            ->hideOnIndex();
    }
}
