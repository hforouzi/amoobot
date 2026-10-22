<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A field help shown as text: EasyAdmin prints help as HTML (|raw), so a translation that shows markup as an example
 * (such as "<b>bold</b>") is escaped here. The key stays a translation key in its domain, as for any other help.
 */
final readonly class PlainTextHelp implements TranslatableInterface
{
    public function __construct(
        private string $key,
        private string $domain = 'admin',
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return htmlspecialchars($translator->trans($this->key, [], $this->domain, $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
