<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * Which items of a panel's link list the bot accepts as a customer's config links (docs/design/multi-inbound-plans.md,
 * 9.1): a share link of a supported scheme, printable ASCII without spaces (the panel percent-encodes remarks), at most
 * MAX_LINK_LENGTH characters, at most MAX_LINKS of them. Anything else (another scheme such as javascript: or
 * socks://, an object instead of a string, control characters, a giant item) is dropped and counted, never shown.
 */
final class ShareLinkPolicy
{
    /** The schemes 3x-ui writes into a subscription (docs/config/share-links) that a customer's app imports. */
    public const SUPPORTED_SCHEMES = ['vless', 'vmess', 'trojan', 'ss', 'hysteria2', 'hy2', 'tuic', 'wireguard'];
    public const MAX_LINK_LENGTH = 8192;
    public const MAX_LINKS = 500;

    /**
     * @param mixed $obj the panel's answer: a list of links, or one text with a link per line
     *
     * @return array{links: list<string>, rejected: int, rejectedSchemes: array<string, int>} the dropped items are
     *         counted per scheme (scheme(), "not_a_string", "not_a_list"), for logs that never show an item
     */
    public static function filter(mixed $obj): array
    {
        if (null === $obj) {
            return ['links' => [], 'rejected' => 0, 'rejectedSchemes' => []];
        }
        if (is_string($obj)) {
            $obj = preg_split('/\R/', $obj) ?: [];
        }
        if (!is_array($obj) || !array_is_list($obj)) {
            return ['links' => [], 'rejected' => 1, 'rejectedSchemes' => ['not_a_list' => 1]];
        }
        $links = [];
        $rejected = 0;
        $schemes = [];
        foreach ($obj as $item) {
            // Surrounding whitespace only: trim() would also strip NUL and other control bytes, which make an item unusable.
            $link = is_string($item) ? trim($item, " \t\r\n") : null;
            // A repeated item is dropped before the cap: duplicates must not push distinct links out of it.
            if ('' === $link || (null !== $link && isset($links[$link]))) {
                continue;
            }
            if (null === $link || !self::isAcceptable($link) || count($links) >= self::MAX_LINKS) {
                ++$rejected;
                $scheme = null === $link ? 'not_a_string' : self::scheme($link);
                $schemes[$scheme] = ($schemes[$scheme] ?? 0) + 1;
                continue;
            }
            $links[$link] = true;
        }
        ksort($schemes);

        return ['links' => array_map('strval', array_keys($links)), 'rejected' => $rejected, 'rejectedSchemes' => $schemes];
    }

    public static function isAcceptable(string $link): bool
    {
        if ('' === $link || strlen($link) > self::MAX_LINK_LENGTH || 1 !== preg_match('#^([a-z][a-z0-9+.-]*)://[\x21-\x7E]+$#D', $link, $m)) {
            return false;
        }

        return in_array(strtolower($m[1]), self::SUPPORTED_SCHEMES, true);
    }

    /** The scheme only ("vless"), for logs: never the credential or address in a link. */
    public static function scheme(string $link): string
    {
        return 1 === preg_match('#^([a-z][a-z0-9+.-]{0,15})://#i', $link, $m) ? strtolower($m[1]) : 'invalid';
    }
}
