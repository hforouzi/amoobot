<?php

declare(strict_types=1);

namespace App\Admin\UI;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Flash messages as translation keys (admin domain), translated when the page renders, in the admin's language.
 * EasyAdmin renders flash messages as raw HTML: parameter values (titles, panel or gateway errors) are escaped here.
 */
trait TranslatedFlashes
{
    /**
     * @param array<string, string|int|float|null> $parameters
     */
    protected function flash(string $type, string $key, array $parameters = []): void
    {
        $escaped = array_map(static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $parameters);
        $this->addFlash($type, new TranslatableMessage($key, $escaped, 'admin'));
    }
}
