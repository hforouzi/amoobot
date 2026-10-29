<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * The comment of a panel client after its service changed owner (ClaimPanelSync): the bot's marker ("amoobot order #N"
 * or "amoobot order #N, claimed", what createService() wrote at first) without losing what an admin wrote there.
 *
 * - empty, or only a marker of ours: replaced by the new marker;
 * - an admin's text (with or without a marker of ours after it): the admin's text is kept, then " | ", then the marker;
 * - the result would be longer than MAX characters: the admin's text is kept as it is, without a marker (it is never
 *   cut; the database alone says who the owner is, the marker is only a convenience on the panel).
 */
final class ClientOwnerComment
{
    public const MAX = 255;
    public const SEPARATOR = ' | ';
    private const MARKER = '/^amoobot(?: order #\d+)?(?:, claimed)?$/D';

    public static function compose(?string $current, string $marker): string
    {
        $current = trim((string) $current);
        if ('' === $current || self::isMarker($current)) {
            return $marker;
        }
        $at = strrpos($current, self::SEPARATOR);
        if (false !== $at && self::isMarker(substr($current, $at + strlen(self::SEPARATOR)))) {
            $current = rtrim(substr($current, 0, $at));
        }
        $composed = $current.self::SEPARATOR.$marker;

        return mb_strlen($composed) <= self::MAX ? $composed : $current;
    }

    public static function isMarker(string $text): bool
    {
        return 1 === preg_match(self::MARKER, trim($text));
    }
}
