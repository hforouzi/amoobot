<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * The shop's brand as customer-facing pages show it (templates/public/layout.html.twig): a name, a logo (its stored
 * file name, served by /brand/logo/{name}) and an accent colour (#rrggbb). Each part is optional; the pages look the
 * same as without branding when none is set, apart from the shared layout itself.
 */
final class Branding
{
    /** The green of the payment pages before branding existed. */
    public const DEFAULT_ACCENT = '#166534';

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $logo = null,
        public readonly string $accentColor = self::DEFAULT_ACCENT,
    ) {
    }

    public function hasHeader(): bool
    {
        return null !== $this->logo || null !== $this->name;
    }

    /** White or near-black, whichever reads better on the accent colour (WCAG contrast ratio). */
    public function accentText(): string
    {
        $accent = self::luminance($this->accentColor);

        return (1.05 / ($accent + 0.05)) >= (($accent + 0.05) / (self::luminance('#111827') + 0.05)) ? '#ffffff' : '#111827';
    }

    /** WCAG relative luminance of a #rrggbb colour. */
    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1, 6), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
