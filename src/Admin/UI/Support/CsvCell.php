<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

/**
 * One CSV cell that is safe to open in a spreadsheet (OWASP "CSV injection"): text a spreadsheet would read as a
 * formula gets a leading ' (shown as text). Every CSV export of the admin area writes its cells through this.
 *
 * Neutralized when the raw text starts with = + - @ TAB or CR, or when its first character after leading spaces, line
 * feeds, carriage returns, tabs and no-break spaces (U+00A0) is = + - @ (spreadsheets skip that whitespace). Nothing is
 * trimmed or changed otherwise; numbers are written as they are.
 */
final class CsvCell
{
    private const WHITESPACE = '/^[ \n\r\t\x{00A0}]+/u';

    public static function of(mixed $value): string
    {
        $text = (string) $value;
        if (!is_string($value) || '' === $text) {
            return $text;
        }
        // Invalid UTF-8 (preg_replace with /u gives null): only the raw first character is checked.
        $trimmed = preg_replace(self::WHITESPACE, '', $text) ?? $text;

        return 1 === preg_match('/^[=+\-@\t\r]/', $text) || 1 === preg_match('/^[=+\-@]/', $trimmed) ? "'".$text : $text;
    }
}
