<?php

declare(strict_types=1);

namespace App\MiniApp\Admin\Broadcast;

use App\Entity\BroadcastMessage;
use App\MiniApp\Admin\AdminText;

/**
 * The Mini App broadcast form: puts the fields app.js sends onto a BroadcastMessage (the web form's fields: title,
 * body, text format, link preview, audience, the selected users or the "expiring within" days the audience needs),
 * the live character count, and a preview of the text. It only reads input; the rules are the entity's (title and
 * body required, at most TELEGRAM_MESSAGE_LIMIT characters, known audience and format), as on the web.
 */
final class BroadcastForm
{
    public const EXPIRING_DAYS_DEFAULT = 3;
    public const EXPIRING_DAYS_MAX = 365;
    /** Telegram's formatting tags shown as such in the preview (HTML format); any other markup is shown as text. */
    private const PREVIEW_TAGS = 'b|strong|i|em|u|ins|s|strike|del|code|pre|blockquote|tg-spoiler';

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string> field => error key (miniapp domain), for what could not be read
     */
    public static function apply(BroadcastMessage $broadcast, array $body): array
    {
        $text = static fn (string $field): string => is_scalar($body[$field] ?? null) ? trim((string) $body[$field]) : '';
        $errors = [];

        $broadcast->setTitle(mb_substr($text('title'), 0, 255));
        $broadcast->setBody(str_replace("\r\n", "\n", $text('body')));
        $broadcast->setParseMode('' === $text('parseMode') ? null : $text('parseMode'));
        $broadcast->setDisableWebPagePreview(in_array($text('disableWebPagePreview'), ['1', 'true', 'on'], true));
        $broadcast->setTargetType($text('targetType'));

        $broadcast->setTargetUserIds(null);
        if (BroadcastMessage::TARGET_SELECTED_USERS === $broadcast->getTargetType()) {
            $ids = self::userIds($text('targetUserIds'));
            if (null === $ids) {
                $errors['targetUserIds'] = 'admin.broadcast.error.user_ids';
            } else {
                $broadcast->setTargetUserIds($ids);
            }
        }

        $metadata = $broadcast->getMetadata() ?? [];
        unset($metadata['expiringDays']);
        if (BroadcastMessage::TARGET_USERS_WITH_EXPIRING_SERVICE === $broadcast->getTargetType()) {
            $days = '' === $text('expiringDays') ? self::EXPIRING_DAYS_DEFAULT : AdminText::integer($text('expiringDays'));
            if (null === $days || $days < 1 || $days > self::EXPIRING_DAYS_MAX) {
                $errors['expiringDays'] = 'admin.broadcast.error.expiring_days';
            } else {
                $metadata['expiringDays'] = $days;
            }
        }
        $broadcast->setMetadata([] === $metadata ? null : $metadata);

        return $errors;
    }

    /**
     * The form's values for a broadcast.
     *
     * @return array<string, string|bool>
     */
    public static function values(BroadcastMessage $broadcast): array
    {
        return [
            'title' => $broadcast->getTitle(),
            'body' => $broadcast->getBody(),
            'parseMode' => (string) $broadcast->getParseMode(),
            'disableWebPagePreview' => $broadcast->isDisableWebPagePreview(),
            'targetType' => $broadcast->getTargetType(),
            'targetUserIds' => implode(', ', $broadcast->getTargetUserIds() ?? []),
            'expiringDays' => (string) ($broadcast->getMetadata()['expiringDays'] ?? self::EXPIRING_DAYS_DEFAULT),
        ];
    }

    /** Characters as Telegram and BroadcastSender count them (Unicode code points). */
    public static function length(string $body): int
    {
        return mb_strlen($body);
    }

    /**
     * The text as HTML that is safe to show: everything escaped; for the HTML format Telegram's formatting tags (without
     * attributes) are put back, and a link shows its text only (no address is followed).
     */
    public static function preview(string $body, ?string $parseMode): string
    {
        $html = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ('HTML' === $parseMode) {
            $html = (string) preg_replace('#&lt;(/?)('.self::PREVIEW_TAGS.')&gt;#i', '<$1$2>', $html);
            $html = (string) preg_replace('#&lt;a\b.*?&gt;#is', '<span class="link">', $html);
            $html = str_ireplace('&lt;/a&gt;', '</span>', $html);
        }

        return nl2br($html, false);
    }

    /**
     * "12, 15 ۲۰" -> [12, 15, 20]; null when empty or anything else is in it.
     *
     * @return list<int>|null
     */
    private static function userIds(string $text): ?array
    {
        $parts = preg_split('/[\s,،]+/u', AdminText::latinDigits($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        foreach ($parts as $part) {
            if (1 !== preg_match('/^\d{1,9}$/', $part) || 0 === (int) $part) {
                return null;
            }
            $ids[(int) $part] = (int) $part;
        }

        return [] === $ids || count($ids) > 1000 ? null : array_values($ids);
    }
}
