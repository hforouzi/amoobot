<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Shared\Domain\PlainText;

/**
 * What an admin may save as a guide text (Bot Content rows guide.<slug>.title / guide.<slug>.body, parse mode
 * "guide"; docs/design/help-guide.md, sections 2.2 and 3.1). GuideRenderer escapes everything anyway; these rules make
 * a mistake visible at save time instead of silently showing it as text:
 *
 *  - only the keys of the guide's topics (GuideTopic), and parse mode "guide" only for them;
 *  - at most TITLE_MAX characters for a title (one line), BODY_MAX for a body;
 *  - PlainText's character rules: valid UTF-8, no control or invisible characters (bidi overrides, zero-width space,
 *    BOM, tabs, ...; the zero-width non-joiner and joiner are fine), no private-use or unassigned code points; line
 *    breaks only in a body;
 *  - placeholders: only {{ payment_methods }} (body, on a line of its own) and the shop-wide bot variables
 *    (BOT_VARIABLES); an unknown one, or a stray "{{" / "}}", is refused;
 *  - feature blocks: "::: if <feature>" with a feature of GuideState::FLAGS, closed by ":::"; balanced; any other line
 *    starting with ":::" is refused;
 *  - links: [text](#slug) to a topic of the guide, or [text](https://host/...) without credentials; anything else
 *    (javascript:, data:, http:, a relative path, ...) is refused.
 *
 * Each problem is a message key of the "validators" domain with its parameters.
 */
final class GuideTextRules
{
    public const PARSE_MODE = 'guide';
    public const CATEGORY = 'guide';
    public const TITLE_MAX = 80;
    public const BODY_MAX = 4000;
    /** The {{ bot.* }} variables BotTextResolver fills in: the same for every customer. */
    public const BOT_VARIABLES = ['bot.brandName', 'bot.footerText', 'bot.supportText'];
    /** PlainText::CONTROL_IN_NOTE split in two for clearer messages: control characters (line breaks allowed) ... */
    private const CONTROL = '/(?![\n\r])\p{Cc}/u';
    /** ... and invisible ones (bidi overrides and isolates, zero-width space, BOM, line separators; not ZWNJ / ZWJ). */
    private const INVISIBLE = '/(?![\x{200C}\x{200D}])[\p{Cf}\p{Zl}\p{Zp}]/u';
    /** A link as written, its target up to the first ")" (looser than GuideRenderer::LINK, so a bad target is seen). */
    private const LINK_AS_WRITTEN = '/\[[^\[\]\n]{1,200}\]\(([^)\s]*)/u';
    private const KEY ='/^guide\.([a-z_]{1,32})\.(title|body)$/D';

    /** Whether $key is one of the guide's texts (guide.<topic>.title or guide.<topic>.body). */
    public static function isGuideKey(string $key): bool
    {
        return null !== self::part($key);
    }

    /**
     * Every guide key, in the topics' order: guide.start.title, guide.start.body, ...
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (GuideTopic::cases() as $topic) {
            $keys[] = $topic->titleKey();
            $keys[] = $topic->bodyKey();
        }

        return $keys;
    }

    /** 'title' or 'body' for a guide key, else null. */
    public static function part(string $key): ?string
    {
        if (1 !== preg_match(self::KEY, $key, $m) || null === GuideTopic::tryFrom($m[1])) {
            return null;
        }

        return $m[2];
    }

    /**
     * The problems of $text as the guide text $key ([] when it may be saved).
     *
     * @return list<array{message: string, parameters: array<string, string>}>
     */
    public static function problems(string $key, string $text): array
    {
        $part = self::part($key);
        if (null === $part) {
            return [self::problem('guide_text.key')];
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return [self::problem('guide_text.characters')];
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $title = 'title' === $part;

        $problems = [];
        $max = $title ? self::TITLE_MAX : self::BODY_MAX;
        if (mb_strlen($text) > $max) {
            $problems[] = self::problem('guide_text.too_long', ['%max%' => (string) $max, '%length%' => (string) mb_strlen($text)]);
        }
        if ($title && str_contains($text, "\n")) {
            $problems[] = self::problem('guide_text.title_one_line');
        }
        if (0 !== preg_match(self::CONTROL, $text)) {
            $problems[] = self::problem('guide_text.control');
        }
        if (0 !== preg_match(self::INVISIBLE, $text) || 0 !== preg_match(PlainText::FORBIDDEN, $text)) {
            $problems[] = self::problem('guide_text.characters');
        }

        return [...$problems, ...self::placeholderProblems($text, $title), ...self::blockProblems($text, $title), ...self::linkProblems($text)];
    }

    /**
     * @return list<array{message: string, parameters: array<string, string>}>
     */
    private static function placeholderProblems(string $text, bool $title): array
    {
        $problems = [];
        $allowed = $title ? self::BOT_VARIABLES : [...GuideRenderer::PLACEHOLDERS, ...self::BOT_VARIABLES];
        preg_match_all('/\{\{(.*?)\}\}/s', $text, $matches);
        foreach (array_unique($matches[1]) as $inner) {
            $name = trim($inner);
            if (!in_array($name, $allowed, true)) {
                $problems[] = self::problem('guide_text.placeholder', ['%name%' => self::shown($name), '%allowed%' => implode(', ', array_map(static fn (string $n): string => '{{ '.$n.' }}', $allowed))]);
            }
        }
        $rest = (string) preg_replace('/\{\{.*?\}\}/s', '', $text);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            $problems[] = self::problem('guide_text.placeholder_unclosed');
        }
        foreach (GuideRenderer::PLACEHOLDERS as $name) {
            foreach (explode("\n", $text) as $line) {
                if (1 === preg_match('/\{\{\s*'.preg_quote($name, '/').'\s*\}\}/', $line) && 1 !== preg_match('/^\s*\{\{\s*'.preg_quote($name, '/').'\s*\}\}\s*$/D', $line)) {
                    $problems[] = self::problem('guide_text.placeholder_own_line', ['%name%' => '{{ '.$name.' }}']);
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<array{message: string, parameters: array<string, string>}>
     */
    private static function blockProblems(string $text, bool $title): array
    {
        $problems = [];
        $depth = 0;
        foreach (explode("\n", $text) as $number => $line) {
            $line = trim($line, ' ');
            if (!str_starts_with($line, ':::')) {
                continue;
            }
            $at = ['%line%' => (string) ($number + 1)];
            if ($title) {
                $problems[] = self::problem('guide_text.block_in_title');
                break;
            }
            if (':::' === $line) {
                if (0 === $depth) {
                    $problems[] = self::problem('guide_text.block_stray_close', $at);
                } else {
                    --$depth;
                }
            } elseif (1 === preg_match('/^:::\s*if\s+(\S+)$/D', $line, $m)) {
                if (!in_array($m[1], GuideState::FLAGS, true)) {
                    $problems[] = self::problem('guide_text.block_feature', $at + ['%name%' => self::shown($m[1]), '%allowed%' => implode(', ', GuideState::FLAGS)]);
                }
                ++$depth;
            } else {
                $problems[] = self::problem('guide_text.block_line', $at + ['%text%' => self::shown($line)]);
            }
        }
        if ($depth > 0) {
            $problems[] = self::problem('guide_text.block_unclosed', ['%count%' => (string) $depth]);
        }

        return $problems;
    }

    /**
     * @return list<array{message: string, parameters: array<string, string>}>
     */
    private static function linkProblems(string $text): array
    {
        $problems = [];
        preg_match_all(self::LINK_AS_WRITTEN, $text, $matches);
        foreach (array_unique($matches[1]) as $target) {
            if (1 === preg_match('/^#(.*)$/sD', $target, $m)) {
                if (null === GuideTopic::tryFrom($m[1])) {
                    $problems[] = self::problem('guide_text.link_topic', ['%name%' => self::shown($m[1]), '%allowed%' => implode(', ', array_map(static fn (GuideTopic $t): string => '#'.$t->value, GuideTopic::cases()))]);
                }
            } elseif (!GuideRenderer::isSafeExternalUrl($target) || 1 !== preg_match(GuideRenderer::LINK, '[x]('.$target.')')) {
                $problems[] = self::problem('guide_text.link_target', ['%target%' => self::shown($target)]);
            }
        }

        return $problems;
    }

    /** A piece of the admin's text inside an error message: short, and without control characters. */
    private static function shown(string $value): string
    {
        $value = (string) preg_replace(PlainText::CONTROL, '', $value);

        return mb_strlen($value) > 40 ? mb_substr($value, 0, 40).'…' : $value;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array{message: string, parameters: array<string, string>}
     */
    private static function problem(string $message, array $parameters = []): array
    {
        return ['message' => $message, 'parameters' => $parameters];
    }
}
