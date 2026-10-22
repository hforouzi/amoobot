<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use Twig\Attribute\AsTwigFilter;

/**
 * Twig filters of the Mini App admin forms.
 */
final class AdminFormFilters
{
    /** "pricePerGb" -> "price_per_gb": the web form's label key is field.<snake>. */
    #[AsTwigFilter('snake')]
    public static function snake(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }
}
