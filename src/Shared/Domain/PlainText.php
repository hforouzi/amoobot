<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The rules for a free-text value typed by an admin or a reseller and shown to customers: checked exactly and stored
 * as typed, nothing trimmed or rewritten. A value that does not match is refused, never silently changed.
 *
 * Refused: invalid UTF-8; control and invisible characters (Unicode categories Cc, Cf: bidi marks and overrides,
 * zero-width space, BOM, ...; Zl and Zp: line / paragraph separator), except the zero-width non-joiner (U+200C, needed
 * to write Persian correctly) and the zero-width joiner (U+200D, emoji sequences), plus line breaks where $multiline;
 * private use, surrogate and unassigned code points; a space or line break at either end; more than $max characters.
 */
final class PlainText
{
    public const CONTROL = '/(?![\x{200C}\x{200D}])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';
    public const CONTROL_IN_NOTE = '/(?![\x{200C}\x{200D}\n\r])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';
    /** Private use, surrogates, unassigned. */
    public const FORBIDDEN = '/[\p{Co}\p{Cs}\p{Cn}]/u';

    /**
     * Why $value is refused (characters, too_long, control, spaces), or null when it is accepted as is.
     */
    public static function problem(string $value, int $max, bool $multiline = false): ?string
    {
        return match (true) {
            !mb_check_encoding($value, 'UTF-8') => 'characters',
            mb_strlen($value) > $max => 'too_long',
            0 !== preg_match($multiline ? self::CONTROL_IN_NOTE : self::CONTROL, $value) => 'control',
            0 !== preg_match(self::FORBIDDEN, $value) => 'characters',
            1 !== preg_match('/^\S(?:.*\S)?$/suD', $value) => 'spaces',
            default => null,
        };
    }
}
