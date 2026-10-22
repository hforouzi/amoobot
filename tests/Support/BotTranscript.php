<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Everything the bot sent to Telegram during a test, as a stable, readable transcript (method, chat, text, buttons),
 * compared to a snapshot under tests/Bot/__snapshots__/. Used to prove a refactoring changes nothing the user sees.
 *
 * Volatile values are replaced with names. Database ids are replaced by where they appear, never by value alone: ids of
 * different tables collide (on a fresh database the first plan, service and draft are all id 1), so a callback argument
 * or a text like "Order ID: 7" says which table the number belongs to, and only an id registered for that table is
 * renamed. Opaque strings (tracking codes, generated usernames, uuids) are replaced by value.
 * Record or update snapshots with UPDATE_BOT_SNAPSHOTS=1.
 */
final class BotTranscript
{
    /** Callback route => the table of each argument, in order (null: not an id). */
    private const CALLBACK_ARGUMENTS = [
        'select_plan' => ['PLAN'],
        'service_view' => ['SERVICE'],
        'service_renew' => ['SERVICE'],
        'service_add_traffic_order' => ['SERVICE'],
        'renewal_confirm' => ['SERVICE'],
        'add_traffic_confirm' => ['DRAFT'],
        'custom_order_confirm' => ['DRAFT'],
        'custom_order_cancel' => ['DRAFT'],
        'discount_enter' => ['DRAFT'],
        'discount_skip' => ['DRAFT'],
        'draft_back' => ['DRAFT'],
        'draft_cancel' => ['DRAFT'],
        'discount_enter_order' => ['ORDER'],
        'discount_skip_order' => ['ORDER'],
        'order_cancel' => ['ORDER'],
        'order_resume' => ['ORDER'],
        'order_summary' => ['ORDER'],
        'order_payment_methods' => ['ORDER'],
        'select_store_payment_method' => ['ORDER', 'PAYMENT_METHOD'],
        'payment_check' => ['PAYMENT'],
        'payment_submit_receipt' => ['PAYMENT'],
        'admin_confirm_payment' => ['PAYMENT'],
        'admin_reject_payment' => ['PAYMENT'],
    ];

    /** Text right before an id in a message => the id's table. */
    private const TEXT_CONTEXTS = [
        'Order ID: ' => 'ORDER',
        'Payment ID: ' => 'PAYMENT',
        'Service ID: ' => 'SERVICE',
        'شناسه سرویس: ' => 'SERVICE',
        'سرویس #' => 'SERVICE',
    ];

    /**
     * @param list<array{url: string, options: array<string, mixed>}> $requests
     * @param array<array-key, string>                                 $replace exact opaque value => placeholder (never a bare number)
     * @param array<string, array<int, string>>                        $ids     table (PLAN, SERVICE, DRAFT, ORDER, PAYMENT, PAYMENT_METHOD) => [id => placeholder]
     *
     * @return list<string>
     */
    public static function lines(array $requests, array $replace = [], array $ids = []): array
    {
        unset($replace['']);
        foreach (array_keys($replace) as $value) {
            if (is_int($value)) {
                throw new \LogicException(sprintf('"%d" is a bare number; register it in $ids under its table instead.', $value));
            }
        }
        /** @var array<string, string> $replace */
        // Longest first, so "abc" never eats part of "abcd".
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
                    $value = self::scalar($body[$field]);
                    $line .= "\n  ".$field.': '.(in_array($field, ['text', 'caption'], true) ? self::textIds($value, $ids) : $value);
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
                            $target = isset($button['callback_data']) ? self::callbackIds((string) $button['callback_data'], $ids) : ($button['url'] ?? (isset($button['web_app']) ? 'web_app:'.$button['web_app']['url'] : ''));
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

    /**
     * "route:12:3" => "route:ORDER:CARD_METHOD", using the tables CALLBACK_ARGUMENTS gives the route's arguments.
     *
     * @param array<string, array<int, string>> $ids
     */
    private static function callbackIds(string $data, array $ids): string
    {
        $parts = explode(':', $data);
        $route = array_shift($parts);
        foreach ($parts as $i => $argument) {
            if (1 !== preg_match('/^\d+$/', $argument)) {
                continue;
            }
            $table = self::CALLBACK_ARGUMENTS[$route][$i] ?? throw new \LogicException(sprintf('Callback "%s": argument %d is a number but BotTranscript::CALLBACK_ARGUMENTS does not say which table it belongs to.', $data, $i + 1));
            $parts[$i] = self::label($table, (int) $argument, $data, $ids);
        }

        return implode(':', [$route, ...$parts]);
    }

    /**
     * @param array<string, array<int, string>> $ids
     */
    private static function textIds(string $text, array $ids): string
    {
        foreach (self::TEXT_CONTEXTS as $before => $table) {
            $text = (string) preg_replace_callback('/'.preg_quote($before, '/').'(\d+)\b/u', static fn (array $m): string => $before.self::label($table, (int) $m[1], $m[0], $ids), $text);
        }

        return $text;
    }

    /**
     * @param array<string, array<int, string>> $ids
     */
    private static function label(string $table, int $id, string $context, array $ids): string
    {
        return $ids[$table][$id] ?? throw new \LogicException(sprintf('"%s": %s id %d is not registered, so the snapshot would depend on the database\'s autoincrement.', $context, $table, $id));
    }

    private static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
