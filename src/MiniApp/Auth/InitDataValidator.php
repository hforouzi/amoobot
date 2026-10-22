<?php

declare(strict_types=1);

namespace App\MiniApp\Auth;

use Psr\Clock\ClockInterface;

/**
 * Validates Telegram.WebApp.initData exactly as Telegram specifies
 * (core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app):
 *
 *   data_check_string = every field except "hash", sorted by key, "key=value" joined by "\n"
 *   secret_key        = HMAC_SHA256(key: "WebAppData", message: bot_token)
 *   hash              = hex(HMAC_SHA256(key: secret_key, message: data_check_string))
 *
 * compared in constant time against each configured bot token, then auth_date must be at most $maxAgeSeconds old and
 * at most $futureSkewSeconds ahead. "signature" (Ed25519, for third-party validation) stays in the check string.
 */
final class InitDataValidator
{
    /** Telegram's initData is well under 1 KB; anything this long is not from Telegram. */
    private const MAX_LENGTH = 8192;

    /** @var list<string> */
    private readonly array $botTokens;

    /**
     * @param list<string> $botTokens the bots whose Mini App is served here (empty entries are ignored)
     */
    public function __construct(
        array $botTokens,
        private readonly ClockInterface $clock,
        private readonly int $maxAgeSeconds = 3600,
        private readonly int $futureSkewSeconds = 60,
    ) {
        $this->botTokens = array_values(array_filter(array_map('trim', $botTokens), static fn (string $t): bool => '' !== $t));
    }

    /**
     * @throws InitDataRejected
     */
    public function validate(string $initData): ValidatedInitData
    {
        if ([] === $this->botTokens) {
            throw new InitDataRejected(InitDataRejected::NOT_CONFIGURED);
        }

        $fields = $this->parse($initData);
        $hash = $fields['hash'] ?? '';
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new InitDataRejected(InitDataRejected::MISSING_HASH);
        }
        unset($fields['hash']);

        if (!$this->signedByAConfiguredBot($fields, $hash)) {
            throw new InitDataRejected(InitDataRejected::BAD_HASH);
        }

        $authDate = $fields['auth_date'] ?? '';
        if (1 !== preg_match('/^[1-9][0-9]{0,11}$/', $authDate)) {
            throw new InitDataRejected(InitDataRejected::MISSING_AUTH_DATE);
        }
        $now = $this->clock->now()->getTimestamp();
        if ((int) $authDate < $now - $this->maxAgeSeconds) {
            throw new InitDataRejected(InitDataRejected::EXPIRED);
        }
        if ((int) $authDate > $now + $this->futureSkewSeconds) {
            throw new InitDataRejected(InitDataRejected::FUTURE);
        }

        $user = json_decode($fields['user'] ?? '', true);
        if (!is_array($user) || !is_int($user['id'] ?? null) || $user['id'] <= 0) {
            throw new InitDataRejected(InitDataRejected::MISSING_USER);
        }

        $startParam = $fields['start_param'] ?? null;

        return new ValidatedInitData($user, (int) $authDate, null === $startParam || '' === $startParam ? null : $startParam);
    }

    /**
     * A URL query string, decoded field by field (parse_str would rename keys and build arrays). Duplicate keys are
     * refused: the data-check-string would be ambiguous.
     *
     * @return array<string, string>
     */
    private function parse(string $initData): array
    {
        if ('' === $initData || strlen($initData) > self::MAX_LENGTH) {
            throw new InitDataRejected(InitDataRejected::MALFORMED);
        }

        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            $parts = explode('=', $pair, 2);
            if (2 !== count($parts) || '' === $parts[0]) {
                throw new InitDataRejected(InitDataRejected::MALFORMED);
            }
            $key = urldecode($parts[0]);
            if (array_key_exists($key, $fields)) {
                throw new InitDataRejected(InitDataRejected::MALFORMED);
            }
            $fields[$key] = urldecode($parts[1]);
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields without "hash"
     */
    private function signedByAConfiguredBot(array $fields, string $hash): bool
    {
        ksort($fields, SORT_STRING);
        $lines = [];
        foreach ($fields as $key => $value) {
            $lines[] = $key.'='.$value;
        }
        $dataCheckString = implode("\n", $lines);

        $valid = false;
        foreach ($this->botTokens as $botToken) {
            $expected = hash_hmac('sha256', $dataCheckString, hash_hmac('sha256', $botToken, 'WebAppData', true));
            // Every token is checked, so the time does not tell which one matched.
            $valid = hash_equals($expected, $hash) || $valid;
        }

        return $valid;
    }
}
