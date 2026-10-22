<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Everything the bot sent to Telegram during a test, as a stable, readable transcript (method, chat, text, buttons),
 * compared to a snapshot under tests/Bot/__snapshots__/. Used to prove a refactoring changes nothing the user sees.
 *
 * Volatile values (database ids, tracking codes, generated usernames, gateway track ids) are replaced with names.
 * Record or update snapshots with UPDATE_BOT_SNAPSHOTS=1.
 */
final class BotTranscript
{
    /**
     * @param list<array{url: string, options: array<string, mixed>}> $requests
     * @param array<string, string>                                    $replace exact value => placeholder
     *
     * @return list<string>
     */
    public static function lines(array $requests, array $replace = []): array
    {
        // Longest first, so "123" never eats part of "1234".
        uksort($replace, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $lines = [];
        foreach ($requests as $request) {
            if (1 !== preg_match('#api\.telegram\.org/bot[^/]*/(\w+)#', $request['url'], $m)) {
                continue;
            }
            $body = $request['options']['json'] ?? $request['options']['body'] ?? [];
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                $body = is_array($decoded) ? $decoded : ['raw' => '(multipart)'];
            }
            if (!is_array($body)) {
                $body = ['raw' => '(stream)'];
            }
            $line = $m[1].' chat='.(string) ($body['chat_id'] ?? $body['callback_query_id'] ?? '-');
            foreach (['text', 'caption', 'show_alert', 'photo', 'parse_mode'] as $field) {
                if (array_key_exists($field, $body) && !is_array($body[$field])) {
                    $line .= "\n  ".$field.': '.self::scalar($body[$field]);
                }
            }
            $markup = $body['reply_markup'] ?? null;
            if (is_string($markup)) {
                $markup = json_decode($markup, true);
            }
            if (is_array($markup)) {
                foreach (['inline_keyboard', 'keyboard'] as $kind) {
                    foreach ($markup[$kind] ?? [] as $row) {
                        $cells = [];
                        foreach ($row as $button) {
                            $target = $button['callback_data'] ?? $button['url'] ?? (isset($button['web_app']) ? 'web_app:'.$button['web_app']['url'] : '');
                            $cells[] = (string) ($button['text'] ?? '').('' !== $target ? ' -> '.$target : '');
                        }
                        $line .= "\n  [".$kind.'] '.implode(' | ', $cells);
                    }
                }
            }
            $lines[] = strtr($line, $replace);
        }

        // Callback query ids are random per press.
        return array_map(static fn (string $l): string => (string) preg_replace('/^answerCallbackQuery chat=cb\d+/', 'answerCallbackQuery chat=cb', $l), $lines);
    }

    /**
     * @param list<string> $lines
     */
    public static function assertMatchesSnapshot(string $name, array $lines): void
    {
        $file = dirname(__DIR__).'/Bot/__snapshots__/'.$name.'.txt';
        $actual = implode("\n\n", $lines)."\n";
        if ('1' === getenv('UPDATE_BOT_SNAPSHOTS') || !is_file($file)) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $actual);
        }
        // Line endings depend on the checkout (core.autocrlf), not on the bot.
        \PHPUnit\Framework\Assert::assertSame(str_replace("\r\n", "\n", (string) file_get_contents($file)), $actual, 'bot transcript differs from tests/Bot/__snapshots__/'.$name.'.txt');
    }

    private static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
