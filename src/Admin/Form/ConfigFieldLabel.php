<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The label of one gateway config field on the schema form (PaymentGatewayCrudController), resolved in the admin's
 * language when the form renders. A plugin's configSchema may carry its own label, {"en": "...", "fa": "..."} or one
 * string; a core gateway has none. In order:
 *
 *  1. the field's own label in the current language;
 *  2. the app's translation "payment_gateway.config.<name>" (admin domain), when there is one;
 *  3. the field's own label in English, then in any other language it has (or its single string);
 *  4. the name made readable ("merchant_id" -> "Merchant id").
 *
 * So a plugin that labels its fields in English only shows those labels on the Persian admin instead of the raw
 * translation key, and no plugin has to change.
 */
final readonly class ConfigFieldLabel implements TranslatableInterface
{
    public const KEY_PREFIX = 'payment_gateway.config.';
    private const DOMAIN = 'admin';

    public function __construct(
        private string $name,
        private mixed $schemaLabel = null,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $locale ??= $translator->getLocale();
        $own = $this->ownLabels();
        if (isset($own[$locale])) {
            return $own[$locale];
        }

        $key = self::KEY_PREFIX.$this->name;
        if ($translator instanceof TranslatorBagInterface && $translator->getCatalogue($locale)->has($key, self::DOMAIN)) {
            return $translator->trans($key, [], self::DOMAIN, $locale);
        }

        return $own['en'] ?? ([] !== $own ? reset($own) : self::humanize($this->name));
    }

    public static function humanize(string $name): string
    {
        $words = trim((string) preg_replace('/[\s_.\-]+/', ' ', $name));

        return '' === $words ? $name : ucfirst($words);
    }

    /**
     * The schema's own labels by language (a single string counts as "en"); empty or non-text entries are left out.
     *
     * @return array<string, string>
     */
    private function ownLabels(): array
    {
        $labels = is_string($this->schemaLabel) ? ['en' => $this->schemaLabel] : (is_array($this->schemaLabel) ? $this->schemaLabel : []);
        $own = [];
        foreach ($labels as $language => $label) {
            if (is_string($language) && is_string($label) && '' !== trim($label)) {
                $own[strtolower($language)] = $label;
            }
        }

        return $own;
    }
}
