<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

/**
 * Removes credentials from any text that may reach a log, an exception, the admin UI or the console:
 * the exact secret values given, Authorization/Bearer headers, and token/password-shaped key=value pairs.
 */
final class SecretRedactor
{
    public const MASK = '[redacted]';

    /** Share-link schemes of the proxy protocols a panel hands out: the whole link is the credential. */
    private const CONFIG_SCHEMES = 'vless|vmess|trojan|ssr?|hysteria2?|hy2|tuic|wireguard|wg|socks5?|naive\+https|juicity';

    /**
     * Log fields that hold a Telegram user id (a customer's, in a private chat): masked in every log line (M2d B3).
     *
     * @var list<string>
     */
    public const TELEGRAM_ID_KEYS = ['chat', 'chat_id', 'chatId', 'actor_id', 'actorId', 'telegram_id', 'telegramId', 'telegram_user_id', 'telegramUserId', 'tg_id', 'tgId', 'from_id'];

    /** Telegram update objects whose "id" is a user's (or a chat's) id: "chat":{"id":N}, "from":{"id":N}, ... (M2e E4). */
    private const TELEGRAM_ID_OBJECTS = ['chat', 'from', 'user', 'sender_chat'];
    /** What forLog() writes instead of a line it could not process (a PCRE failure): never the raw line (M2e E3). */
    public const WITHHELD = '[log line withheld: it could not be redacted]';

    /**
     * What every log line goes through (SafeErrorLog, RedactingLogger; M2d B3, M2e E4): the customer's Telegram id is
     * never written. Masked (the key or the shape stays, the id goes):
     * - a panel client email tg_<telegram id>_order_<n> -> tg_[redacted]_order_<n>, and any tg_<5+ digits> (the same
     *   prefix cut short, e.g. the 15-character argument of a trace: 'tg_512345678_or...');
     * - a key=value / key: value / "key":value of TELEGRAM_ID_KEYS, also URL-encoded (chat_id%3D<id>,
     *   %22chat_id%22%3A<id>);
     * - a Telegram update's "chat" / "from" / "user" / "sender_chat" object: its "id";
     * - a stack trace (getTraceAsString(), (string) $exception with its "Next" chain, one frame per line or joined on
     *   one line): every frame keeps its number, file, line, class and function, and its whole argument list becomes
     *   "(...)" (M2e, CI): argument values never reach a log, whatever their form (strings, ints, Object(...), Array)
     *   and whatever zend.exception_ignore_args / zend.exception_string_param_max_len say, so a trace reads the same
     *   in every php.ini.
     * Nothing else changes: other ids, amounts and ports keep their values. A text PCRE cannot process is replaced by
     * WITHHELD (fail closed).
     */
    public static function forLog(string $text): string
    {
        $keys = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), self::TELEGRAM_ID_KEYS));
        $objects = implode('|', self::TELEGRAM_ID_OBJECTS);
        // quote, encoded quote, separators (=, :, encoded), spaces (also + and %20 in a URL-encoded body)
        $q = '(?:["\']|%22|%27)?';
        $sep = '(?:\s|\+|%20)*(?:[=:]|%3[dDaA])(?:\s|\+|%20)*';
        $text = preg_replace(
            [
                '/(?<![A-Za-z0-9])tg_\d+(?=_order_\d)/i',
                '/(?<![A-Za-z0-9])tg_-?\d{5,}(?!\d)/i',
                // the key starts a word, or follows a URL escape (%22chat_id, %26chat_id: "2", "6" are word characters)
                '/('.$q.'(?:(?<=%[0-9A-Fa-f]{2})|\b)(?:'.$keys.')\b'.$q.$sep.$q.')-?\d{4,}/',
                '/("(?:'.$objects.')"\s*:\s*\{[^{}]*?"id"\s*:\s*)-?\d{4,}/',
            ],
            ['tg_'.self::MASK, 'tg_'.self::MASK, '$1'.self::MASK, '$1'.self::MASK],
            $text,
        );
        if (null === $text) {
            return self::WITHHELD;
        }

        return self::withoutTraceArguments($text) ?? self::WITHHELD;
    }

    /**
     * Every stack trace frame "#<n> <file>(<line>): <function>(<arguments>)" (or "#<n> [internal function]: ...") with
     * its argument list replaced by "(...)". A frame ends at the end of its line or where the next frame starts on the
     * same line; the function may be a closure named with braces ({closure}, {closure:App\X::m():12}). Null on a PCRE
     * failure.
     */
    private static function withoutTraceArguments(string $text): ?string
    {
        if (!str_contains($text, '#')) {
            return $text;
        }

        return preg_replace(
            '/(#\d+ (?:\[internal function\]|[^\n]*?\(\d+\)): (?:[^\s({]|\{[^}\n]*\})+)\((?:[^\n]*?)\)(?=[ \t]+#\d+ |[ \t]*$)/m',
            '$1(...)',
            $text,
        );
    }

    /** tg_<telegram id>_order_<n> (a generated panel client email) without the Telegram id. */
    public static function maskClientEmail(string $text): string
    {
        return preg_replace('/(?<![A-Za-z0-9])tg_\d+(?=_order_\d)/i', 'tg_'.self::MASK, $text) ?? $text;
    }

    /**
     * @param list<string|null> $secrets exact values to remove (the panel's token, password)
     */
    public static function redact(string $text, array $secrets = [], int $maxLength = 500): string
    {
        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if (strlen($secret) >= 4) {
                $text = str_replace([$secret, rawurlencode($secret)], self::MASK, $text);
            }
        }
        $text = preg_replace('/(Authorization\s*[:=]\s*)("?)[^\s",}]+(\s+[^\s",}]+)?/i', '$1$2'.self::MASK, $text) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer '.self::MASK, $text) ?? $text;
        $text = preg_replace('/([?&](?:api[_-]?token|access[_-]?token|token|password|passwd|session)=)[^&#\s]*/i', '$1'.self::MASK, $text) ?? $text;
        $text = preg_replace('/("?(?:password|passwd|token|api[_-]?token|cookie|secret|authorization)"?\s*[:=]\s*)"[^"]*"/i', '$1"'.self::MASK.'"', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, $maxLength);
    }

    /**
     * Removes a customer's access credentials from free text whose service is unknown (a stored message, an exception
     * from a failed provisioning, a panel response): config links (vless://…) entirely, the path and query of every
     * URL (subscription URLs, /s/ page tokens, panel base paths; scheme and host stay for support), user:password@,
     * UUIDs, subId/uuid/password key-value pairs (a trojan or shadowsocks client's password is its access), the
     * customer's Telegram id in a panel client email (tg_<id>_order_<order> becomes tg_[redacted]_order_<order>) and the
     * values a failed query quotes (Doctrine's parameter list, MySQL's duplicate entry). Then redact().
     */
    public static function redactAccess(string $text, int $maxLength = 500): string
    {
        return self::redact(self::maskAccess($text), [], $maxLength);
    }

    /** redactAccess() without redact()'s whitespace folding and length limit: for text that is stored as it was sent. */
    public static function maskAccess(string $text): string
    {
        $text = preg_replace('/(\bwith params )\[.*?\](?=:|\s*$)/s', '$1['.self::MASK.']', $text) ?? $text;
        $text = preg_replace('/(\bDuplicate entry )\'.*?\'(?= for key)/s', '$1\''.self::MASK.'\'', $text) ?? $text;
        $text = preg_replace('#\b('.self::CONFIG_SCHEMES.')://[^\s"\'<>]+#i', '$1://'.self::MASK, $text) ?? $text;
        $text = preg_replace_callback(
            '#\b(https?://)(?:[^@/\s"\'<>]*@)?([^/\s"\'<>?\#]+)([^\s"\'<>]*)#i',
            // Telegram deep links name a bot or channel; their claim tokens are ClaimToken::redact()'s job.
            static fn (array $m): string => $m[1].$m[2].('' === $m[3] || '/' === $m[3] || 1 === preg_match('/^(t|telegram)\.me$/i', $m[2]) ? $m[3] : '/'.self::MASK),
            $text
        ) ?? $text;
        $text = preg_replace('#/s/[A-Za-z0-9_-]{43}#', '/s/'.self::MASK, $text) ?? $text;
        $text = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', self::MASK, $text) ?? $text;
        $text = self::maskClientEmail($text);

        // A value mask() already shortened ("••••wxyz") stays: it is the correlation hint a log line keeps on purpose.
        return preg_replace('/("?\b(?:sub_?id|client_?uuid|uuid|password|passwd)"?\s*[:=]\s*)(?!"?(?:••••|\[redacted\]))("[^"]*"|\'[^\']*\'|[^\s"\',;&}]+)/i', '$1"'.self::MASK.'"', $text) ?? $text;
    }

    /** "••••wxyz" (last 4 characters of a long value), "••••" for short ones, "" when empty. */
    public static function mask(?string $secret): string
    {
        $secret = trim((string) $secret);
        if ('' === $secret) {
            return '';
        }

        return strlen($secret) >= 16 ? '••••'.substr($secret, -4) : '••••';
    }
}
