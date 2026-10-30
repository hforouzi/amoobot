<?php

declare(strict_types=1);

namespace App\Branding;

use App\Shared\Domain\PlainText;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The brand of customer-facing pages: Setting rows edited on the web settings page, "branding" tab (SETTINGS_MANAGE,
 * SettingsAdministration::saveBranding()). brand.name is free text (PlainText rules, at most NAME_MAX characters),
 * brand.logo the stored file name (BrandLogoStorage), brand.accent_color a #rrggbb colour.
 *
 * Read with one query, once per request (brand() in Twig). The values are checked again here, so a row changed by hand
 * (the "advanced" tab) that breaks a rule is ignored: that part of the brand is simply not shown.
 */
final class BrandingSettings implements ResetInterface
{
    public const NAME = 'brand.name';
    public const LOGO = 'brand.logo';
    public const ACCENT = 'brand.accent_color';
    public const NAME_MAX = 60;
    public const ACCENT_PATTERN = '/^#[0-9a-fA-F]{6}$/D';

    private ?Branding $current = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[AsTwigFunction('brand')]
    public function current(): Branding
    {
        if (null !== $this->current) {
            return $this->current;
        }
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT key_name, value FROM setting WHERE key_name IN (?, ?, ?)',
            [self::NAME, self::LOGO, self::ACCENT],
        );
        $name = (string) ($rows[self::NAME] ?? '');
        $logo = (string) ($rows[self::LOGO] ?? '');
        $accent = (string) ($rows[self::ACCENT] ?? '');

        return $this->current = new Branding(
            '' !== $name && null === self::nameProblem($name) ? $name : null,
            1 === preg_match(BrandLogoStorage::STORED_NAME, $logo) ? $logo : null,
            1 === preg_match(self::ACCENT_PATTERN, $accent) ? strtolower($accent) : Branding::DEFAULT_ACCENT,
        );
    }

    /** Why a non-empty brand name is refused (PlainText codes), or null. */
    public static function nameProblem(string $name): ?string
    {
        return PlainText::problem($name, self::NAME_MAX);
    }

    /** Forget what was read (after a save, and between requests in a long-running worker). */
    public function reset(): void
    {
        $this->current = null;
    }
}
