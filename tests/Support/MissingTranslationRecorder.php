<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Test-only translator decorator: remembers every key of the app's own domains (admin, messages) that is translated
 * for a locale whose catalogue does not define it itself (a fallback to the other locale, or the raw key, would be
 * rendered). TranslationCompletenessTest renders the admin and asserts nothing was recorded.
 */
final class MissingTranslationRecorder implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface
{
    private const CHECKED_DOMAINS = ['admin', 'messages'];

    /** @var array<string, true> "locale|domain|key" */
    private array $missing = [];

    public function __construct(private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner)
    {
    }

    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $domain ??= 'messages';
        $locale ??= $this->inner->getLocale();
        if (null !== $id && '' !== trim($id) && in_array($domain, self::CHECKED_DOMAINS, true) && !$this->inner->getCatalogue($locale)->defines($id, $domain)) {
            $this->missing[$locale.'|'.$domain.'|'.$id] = true;
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        $keys = array_keys($this->missing);
        sort($keys);

        return $keys;
    }

    public function reset(): void
    {
        $this->missing = [];
    }
}
