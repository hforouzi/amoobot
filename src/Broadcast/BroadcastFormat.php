<?php

declare(strict_types=1);

namespace App\Broadcast;

/**
 * A broadcast's text against Telegram's formatting rules, checked here without calling Telegram: MarkdownV2 (every
 * reserved character outside an entity escaped with "\", entities closed; the rules of Telegram's own parser, TDLib's
 * parse_markdown_v2) and HTML (Telegram's tags only, each closed in order, a "<" that is no tag written &lt;). Plain
 * text (no parse mode) is sent as it is and never has a problem. Also the "escape automatically" helper that makes
 * plain text valid MarkdownV2, and the recognition of Telegram's "can't parse entities" answer.
 */
final class BroadcastFormat
{
    public const MARKDOWN_V2 = 'MarkdownV2';
    public const HTML = 'HTML';

    /** Must be escaped with "\" outside an entity (and "\" itself, to be shown). */
    public const MARKDOWN_RESERVED = '_*[]()~`>#+-=|{}.!';

    /** Telegram's HTML tags (https://core.telegram.org/bots/api#html-style). */
    private const HTML_TAGS = ['a', 'b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'span', 'tg-spoiler', 'tg-emoji', 'code', 'pre', 'blockquote'];

    public static function problem(string $text, ?string $parseMode): ?BroadcastFormatProblem
    {
        return match ($parseMode) {
            self::MARKDOWN_V2 => self::markdownProblem($text),
            self::HTML => self::htmlProblem($text),
            default => null,
        };
    }

    /** "Escape automatically" is offered for MarkdownV2 text that would not parse: it is then sent as plain text. */
    public static function canEscape(string $text, ?string $parseMode): bool
    {
        return self::MARKDOWN_V2 === $parseMode && null !== self::markdownProblem($text);
    }

    /** Plain text as MarkdownV2 that Telegram shows exactly as it was: "\" and every reserved character escaped. */
    public static function escapeMarkdownV2(string $text): string
    {
        return (string) preg_replace('/[\\\\'.preg_quote(self::MARKDOWN_RESERVED, '/').']/', '\\\\$0', $text);
    }

    /** Telegram's answer to a text it could not parse in its parse mode (the same for every recipient). */
    public static function isParseError(?string $error): bool
    {
        return null !== $error && 1 === preg_match("/can't parse entities/i", $error);
    }

    private static function markdownProblem(string $text): ?BroadcastFormatProblem
    {
        $chars = mb_str_split($text);
        $count = count($chars);
        $at = static fn (int $i): string => $chars[$i] ?? '';
        /** @var list<array{string, int, string}> $open type, where it started, its marker */
        $open = [];
        $expandable = false;

        for ($i = 0; $i < $count; ++$i) {
            $c = $chars[$i];
            $lineStart = 0 === $i || "\n" === $chars[$i - 1];
            if ($lineStart && '>' !== $c) {
                // An expandable block quote goes on while its lines start with ">".
                $expandable = false;
            }
            // "\" before any ASCII character: that character as it is.
            if ('\\' === $c && 1 === strlen($at($i + 1)) && ord($at($i + 1)) >= 1 && ord($at($i + 1)) <= 126) {
                ++$i;
                continue;
            }
            $top = [] === $open ? null : $open[count($open) - 1][0];
            $inCode = 'code' === $top || 'pre' === $top;
            if ($lineStart && !$inCode) {
                // A block quote (">") or an expandable one ("**>") at the start of a line.
                if ('>' === $c) {
                    continue;
                }
                if ('*' === $c && '*' === $at($i + 1) && '>' === $at($i + 2)) {
                    $i += 2;
                    $expandable = true;
                    continue;
                }
            }
            if (!str_contains($inCode ? '`' : self::MARKDOWN_RESERVED, $c)) {
                continue;
            }
            // An expandable block quote ends with "||" at the end of its last line.
            if ($expandable && '|' === $c && '|' === $at($i + 1) && in_array($at($i + 2), ['', "\n"], true)) {
                ++$i;
                continue;
            }

            $isEnd = match ($top) {
                'bold' => '*' === $c,
                'italic' => '_' === $c && '_' !== $at($i + 1),
                'underline' => '_' === $c && '_' === $at($i + 1),
                'code', 'pre' => '`' === $c,
                'strike' => '~' === $c,
                'spoiler' => '|' === $c && '|' === $at($i + 1),
                'link', 'emoji' => ']' === $c,
                default => false,
            };

            if (!$isEnd) {
                $start = $i;
                switch ($c) {
                    case '_':
                        $type = '_' === $at($i + 1) ? 'underline' : 'italic';
                        $i += 'underline' === $type ? 1 : 0;
                        break;
                    case '*':
                        $type = 'bold';
                        break;
                    case '~':
                        $type = 'strike';
                        break;
                    case '|':
                        if ('|' !== $at($i + 1)) {
                            return self::reserved($chars, $i);
                        }
                        ++$i;
                        $type = 'spoiler';
                        break;
                    case '[':
                        $type = 'link';
                        break;
                    case '`':
                        if ('`' === $at($i + 1) && '`' === $at($i + 2)) {
                            $type = 'pre';
                            $i += 3;
                            // The language name, up to a space or "`".
                            while ($i < $count && !ctype_space($chars[$i]) && '`' !== $chars[$i]) {
                                ++$i;
                            }
                            --$i;
                        } else {
                            $type = 'code';
                        }
                        break;
                    case '!':
                        if ('[' !== $at($i + 1)) {
                            return self::reserved($chars, $i);
                        }
                        ++$i;
                        $type = 'emoji';
                        break;
                    default:
                        return self::reserved($chars, $i);
                }
                $open[] = [$type, $start, 'pre' === $type ? '```' : ('emoji' === $type ? '![' : implode('', array_slice($chars, $start, $i - $start + 1)))];
                continue;
            }

            [$type, $start] = array_pop($open);
            switch ($type) {
                case 'underline':
                    ++$i;
                    break;
                case 'pre':
                    if ('`' !== $at($i + 1) || '`' !== $at($i + 2)) {
                        return new BroadcastFormatProblem('broadcast.format.markdown.unclosed', ['{{ char }}' => '```', '{{ line }}' => self::line($chars, $start)]);
                    }
                    $i += 2;
                    break;
                case 'link':
                case 'emoji':
                    if ('(' !== $at($i + 1)) {
                        if ('emoji' === $type) {
                            return new BroadcastFormatProblem('broadcast.format.markdown.link', ['{{ line }}' => self::line($chars, $start)]);
                        }
                        break;
                    }
                    // The address, up to ")"; "\" escapes inside it.
                    for ($i += 2; $i < $count && ')' !== $chars[$i]; ++$i) {
                        if ('\\' === $chars[$i] && 1 === strlen($at($i + 1)) && ord($at($i + 1)) >= 1 && ord($at($i + 1)) <= 126) {
                            ++$i;
                        }
                    }
                    if ($i >= $count) {
                        return new BroadcastFormatProblem('broadcast.format.markdown.link', ['{{ line }}' => self::line($chars, $start)]);
                    }
                    break;
                case 'spoiler':
                    ++$i;
                    break;
            }
        }

        if ([] !== $open) {
            [, $start, $marker] = $open[count($open) - 1];

            return new BroadcastFormatProblem('broadcast.format.markdown.unclosed', ['{{ char }}' => $marker, '{{ line }}' => self::line($chars, $start)]);
        }

        return null;
    }

    private static function htmlProblem(string $text): ?BroadcastFormatProblem
    {
        $chars = mb_str_split($text);
        $count = count($chars);
        /** @var list<string> $open */
        $open = [];

        for ($i = 0; $i < $count; ++$i) {
            if ('<' !== $chars[$i]) {
                continue;
            }
            $start = $i;
            $end = '/' === ($chars[$i + 1] ?? '');
            $i += $end ? 2 : 1;
            $name = '';
            while ($i < $count && 1 === preg_match('/^[A-Za-z0-9-]$/', $chars[$i])) {
                $name .= strtolower($chars[$i++]);
            }
            if ('' === $name) {
                return new BroadcastFormatProblem('broadcast.format.html.bare_lt', ['{{ line }}' => self::line($chars, $start)]);
            }

            if ($end) {
                while ($i < $count && ctype_space($chars[$i])) {
                    ++$i;
                }
                if ($i >= $count || '>' !== $chars[$i]) {
                    return new BroadcastFormatProblem('broadcast.format.html.unclosed_start', ['{{ line }}' => self::line($chars, $start)]);
                }
                if ([] === $open) {
                    return new BroadcastFormatProblem('broadcast.format.html.unexpected_end', ['{{ tag }}' => $name, '{{ line }}' => self::line($chars, $start)]);
                }
                $expected = array_pop($open);
                if ($expected !== $name) {
                    return new BroadcastFormatProblem('broadcast.format.html.mismatched', ['{{ tag }}' => $name, '{{ expected }}' => $expected, '{{ line }}' => self::line($chars, $start)]);
                }
                continue;
            }

            if (!in_array($name, self::HTML_TAGS, true)) {
                return new BroadcastFormatProblem('broadcast.format.html.unsupported', ['{{ tag }}' => $name, '{{ line }}' => self::line($chars, $start)]);
            }
            // Attributes: name, name=value, name="value" or name='value', up to ">".
            $attributes = [];
            while (true) {
                while ($i < $count && ctype_space($chars[$i])) {
                    ++$i;
                }
                if ($i >= $count || '>' === $chars[$i]) {
                    break;
                }
                $attribute = '';
                while ($i < $count && !ctype_space($chars[$i]) && !in_array($chars[$i], ['=', '>'], true)) {
                    $attribute .= strtolower($chars[$i++]);
                }
                $value = '';
                if ('=' === ($chars[$i] ?? '')) {
                    ++$i;
                    $quote = in_array($chars[$i] ?? '', ['"', "'"], true) ? $chars[$i++] : null;
                    while ($i < $count && (null === $quote ? !ctype_space($chars[$i]) && '>' !== $chars[$i] : $quote !== $chars[$i])) {
                        $value .= $chars[$i++];
                    }
                    if (null !== $quote) {
                        if ($i >= $count) {
                            break;
                        }
                        ++$i;
                    }
                }
                $attributes[$attribute] = $value;
            }
            if ($i >= $count) {
                return new BroadcastFormatProblem('broadcast.format.html.unclosed_start', ['{{ line }}' => self::line($chars, $start)]);
            }
            if ('span' === $name && 'tg-spoiler' !== ($attributes['class'] ?? null)) {
                return new BroadcastFormatProblem('broadcast.format.html.span', ['{{ line }}' => self::line($chars, $start)]);
            }
            $open[] = $name;
        }

        if ([] !== $open) {
            return new BroadcastFormatProblem('broadcast.format.html.unclosed', ['{{ tag }}' => $open[count($open) - 1]]);
        }

        return null;
    }

    /**
     * @param list<string> $chars
     */
    private static function reserved(array $chars, int $i): BroadcastFormatProblem
    {
        return new BroadcastFormatProblem('broadcast.format.markdown.reserved', ['{{ char }}' => $chars[$i], '{{ line }}' => self::line($chars, $i)]);
    }

    /**
     * @param list<string> $chars
     */
    private static function line(array $chars, int $i): string
    {
        return (string) (1 + count(array_keys(array_slice($chars, 0, $i), "\n", true)));
    }
}
