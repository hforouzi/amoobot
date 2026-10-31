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

    /**
     * What every log line goes through (SafeErrorLog, RedactingLogger; M2d B3): the customer's Telegram id is never
     * written. A panel client email tg_<telegram id>_order_<n> becomes tg_[redacted]_order_<n> (the order id stays for
     * support), and a key=value / key: value / "key":value pair of TELEGRAM_ID_KEYS keeps its key and loses its value.
     * Nothing else changes, so the log lines keep their format and their other ids.
     */
    public static function forLog(string $text): string
    {
        $text = self::maskClientEmail($text);
        $keys = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), self::TELEGRAM_ID_KEYS));

        return preg_replace('/(["\']?\b(?:'.$keys.')\b["\']?\s*[=:]\s*["\']?)-?\d{4,}/', '$1'.self::MASK, $text) ?? $text;
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
