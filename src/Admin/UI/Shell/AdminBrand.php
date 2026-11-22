<?php

declare(strict_types=1);

namespace App\Admin\UI\Shell;

use App\Branding\Branding;
use App\Branding\BrandingSettings;
use Twig\Attribute\AsTwigFunction;

/**
 * The brand in the modern admin (decisions 9 and 15): the Branding name in the header and the browser tab instead of
 * "Amoobot Admin", and the Branding colour as the one accent colour.
 *
 * The accent is written into the page as a CSS custom property (style attribute), so it is accepted only as a #rrggbb
 * hex colour: BrandingSettings already ignores anything else, and it is checked again here (anything that is not a
 * hex colour gives the default accent, never the stored text).
 */
final class AdminBrand
{
    /** Shown when no brand name is set. */
    public const FALLBACK_NAME = 'Amoobot';
    private const HEX = '/^#[0-9a-f]{6}$/D';

    public function __construct(private readonly BrandingSettings $branding)
    {
    }

    #[AsTwigFunction('admin_brand_name')]
    public function name(): string
    {
        return $this->branding->current()->name ?? self::FALLBACK_NAME;
    }

    #[AsTwigFunction('admin_accent')]
    public function accent(): string
    {
        return self::validAccent($this->branding->current()->accentColor);
    }

    /** Text colour on the accent (black or white, whichever reads better). */
    #[AsTwigFunction('admin_accent_text')]
    public function accentText(): string
    {
        return (new Branding(accentColor: $this->accent()))->accentText();
    }

    /** The colour if it is a #rrggbb hex colour (lower-cased), else the default accent. */
    public static function validAccent(string $value): string
    {
        $value = strtolower(trim($value));

        return 1 === preg_match(self::HEX, $value) ? $value : Branding::DEFAULT_ACCENT;
    }
}
