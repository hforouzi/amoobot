<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Builds Telegram Mini App initData the way Telegram signs it (core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app):
 * secret = HMAC_SHA256("WebAppData", bot_token), hash = hex(HMAC_SHA256(data_check_string, secret)).
 */
final class TelegramInitData
{
    /**
     * @param array<string, mixed>  $user
     * @param array<string, string> $extra more fields (query_id, start_param, signature, ...)
     */
    public static function signed(string $botToken, array $user, ?int $authDate = null, array $extra = []): string
    {
        $fields = ['auth_date' => (string) ($authDate ?? time()), 'user' => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)] + $extra;
        $fields['hash'] = self::hash($botToken, $fields);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $fields without hash
     */
    public static function hash(string $botToken, array $fields): string
    {
        unset($fields['hash']);
        ksort($fields, SORT_STRING);
        $lines = [];
        foreach ($fields as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        return hash_hmac('sha256', implode("\n", $lines), hash_hmac('sha256', $botToken, 'WebAppData', true));
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(string $telegramId, string $firstName = 'Sara'): array
    {
        return ['id' => (int) $telegramId, 'first_name' => $firstName, 'last_name' => 'Test', 'username' => 'u'.$telegramId, 'language_code' => 'fa'];
    }
}
