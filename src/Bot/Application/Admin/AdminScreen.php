<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\BotTextResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Security\AdminAuditLogger;

/**
 * What every bot admin screen needs: templated messages, the confirmation step, prompts with a cancel button and a
 * timeout, paging, number input, and the audit entry of a change (source bot, the linked admin).
 */
final class AdminScreen
{
    public const PAGE_SIZE = 10;

    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly BotTextResolver $texts,
        private readonly AdminKeyboards $keyboards,
        private readonly AdminPromptStore $prompts,
        private readonly AdminAuditLogger $auditLogger,
        private readonly AdminBotReplies $replies,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function text(string $key, array $variables = []): string
    {
        return $this->texts->message($key, $variables);
    }

    public function label(string $key): string
    {
        return $this->texts->button($key);
    }

    /**
     * Answers the button and sends a message.
     *
     * @param array<string, mixed>|null $keyboard
     */
    public function send(AdminRequest $request, string $text, ?array $keyboard = null): void
    {
        $this->replies->ack($request->callbackId);
        $this->telegramApiClient->sendMessage($request->chatId, mb_substr($text, 0, 4000), $keyboard);
    }

    /** A short message with a back button. */
    public function notice(AdminRequest $request, string $key, array $variables = [], string $backCallback = 'admin.store_menu'): void
    {
        $this->send($request, $this->text($key, $variables), $this->backKeyboard($backCallback));
    }

    /**
     * The confirmation step of a change: "<action> — continue?" with confirm / cancel.
     */
    public function confirm(AdminRequest $request, string $action, string $confirmCallback, string $cancelCallback): void
    {
        $this->send($request, $this->text('admin.confirm', ['action' => $action]), ['inline_keyboard' => [[
            $this->keyboards->button('button.admin.confirm', $confirmCallback),
            $this->keyboards->button('button.admin.cancel', $cancelCallback),
        ]]]);
    }

    /**
     * Waits for a typed value (answered through AdminBotHandler::answerPrompt) and shows the question with a cancel
     * button.
     */
    public function prompt(AdminRequest $request, string $kind, string $target, string $question): void
    {
        $this->prompts->start((string) $request->admin->telegramUserId, $kind, $target);
        $this->send($request, $question."\n\n".$this->text('admin.prompt_hint', ['minutes' => intdiv(AdminPromptStore::TIMEOUT_SECONDS, 60)]), ['inline_keyboard' => [[
            $this->keyboards->button('button.admin.cancel', 'admin.x'),
        ]]]);
    }

    public function endPrompt(AdminRequest $request): void
    {
        $this->prompts->clear((string) $request->admin->telegramUserId);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function audit(AdminRequest $request, string $action, string $targetType, string|int|null $targetId, array $details = []): void
    {
        $this->auditLogger->record($action, $targetType, $targetId, $details, $request->admin);
    }

    /**
     * [page, pages] with the page clamped to the existing ones (0-based page; at least one page).
     *
     * @return array{int, int}
     */
    public function page(int $requested, int $total): array
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return [min(max(0, $requested), $pages - 1), $pages];
    }

    /**
     * "« previous" / "next »" buttons; $callback is sprintf'ed with the page number.
     *
     * @return list<array{text: string, callback_data: string}>
     */
    public function pagerRow(int $page, int $pages, string $callback): array
    {
        $row = [];
        if ($page > 0) {
            $row[] = $this->keyboards->button('button.admin.prev', sprintf($callback, $page - 1));
        }
        if ($page < $pages - 1) {
            $row[] = $this->keyboards->button('button.admin.next', sprintf($callback, $page + 1));
        }

        return $row;
    }

    public function pageInfo(int $page, int $pages): string
    {
        return $this->text('admin.page', ['page' => $page + 1, 'pages' => $pages]);
    }

    /**
     * Rows of buttons plus the pager and a back button.
     *
     * @param list<list<array{text: string, callback_data: string}>> $rows
     * @param list<array{text: string, callback_data: string}>       $pager
     */
    public function listKeyboard(array $rows, array $pager = [], string $backCallback = 'admin.store_menu'): array
    {
        if ([] !== $pager) {
            $rows[] = $pager;
        }
        $rows[] = [$this->keyboards->button('button.admin.back', $backCallback)];

        return ['inline_keyboard' => $rows];
    }

    public function backKeyboard(string $backCallback = 'admin.store_menu'): array
    {
        return ['inline_keyboard' => [[$this->keyboards->button('button.admin.back', $backCallback)]]];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    public function button(string $labelKey, string $callback): array
    {
        return $this->keyboards->button($labelKey, $callback);
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    public function rawButton(string $text, string $callback): array
    {
        return ['text' => mb_substr($text, 0, 60), 'callback_data' => $callback];
    }

    /**
     * A whole number typed by the admin (Persian/Arabic digits and thousands separators accepted), within range.
     */
    public static function parseInt(string $text, int $min, int $max): ?int
    {
        $normalized = self::normalizeNumber($text);
        if (1 !== preg_match('/^-?\d{1,12}$/', $normalized)) {
            return null;
        }
        $value = (int) $normalized;

        return $value < $min || $value > $max ? null : $value;
    }

    /** A number with up to two decimals, within range. */
    public static function parseDecimal(string $text, float $min, float $max): ?float
    {
        $normalized = self::normalizeNumber($text);
        if (1 !== preg_match('/^[+-]?\d{1,6}(\.\d{1,2})?$/', $normalized)) {
            return null;
        }
        $value = (float) $normalized;

        return $value < $min || $value > $max ? null : $value;
    }

    public static function money(int $amount): string
    {
        return number_format($amount);
    }

    private static function normalizeNumber(string $text): string
    {
        $text = strtr(trim($text), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', ',' => '', '٬' => '', ' ' => '', '٪' => '', '%' => '',
        ]);

        return $text;
    }
}
