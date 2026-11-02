<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use Twig\Markup;

/**
 * Turns guide text into HTML for the Mini App (docs/design/help-guide.md, section 3.1). The input is plain text from
 * any source (translations now, admin edits later): it is never trusted as HTML. Everything is escaped, and only these
 * line constructs become markup, built here from escaped pieces:
 *
 *   blank line                 new paragraph (single line breaks inside a paragraph stay)
 *   ## Title                   sub-heading (h3)
 *   - item  /  1. item         bulleted / numbered list
 *   **bold**                   strong
 *   [text](#slug)              link to another visible topic of the guide (else the text alone)
 *   [text](https://…)          external link, opened by Telegram (a[data-open-link]); any other URL: the text alone
 *   {{ payment_methods }}      on a line of its own: the labels of the payment methods customers can use
 *   ::: if <flag>  …  :::      lines shown only while that feature is on (GuideState::FLAGS; unknown flag: hidden)
 *
 * Control, format (bidi overrides, zero-width space, ...), private-use and unassigned characters are removed, except
 * the zero-width non-joiner and joiner (needed in Persian and emoji). Input is cut at MAX_CHARS, and every step is
 * linear in its length, so no input can make rendering slow. No placeholder carries anything of one customer.
 */
final class GuideRenderer
{
    public const MAX_CHARS = 8000;
    public const MAX_TITLE_CHARS = 80;
    private const FORBIDDEN = '/(?![\x{200C}\x{200D}\n])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\p{Co}\p{Cs}\p{Cn}]/u';
    private const LINK = '/\[([^\[\]\n]{1,200})\]\(([^()\s]{1,500})\)/u';
    private const PLACEHOLDER_PAYMENT_METHODS = '{{ payment_methods }}';

    /**
     * @param \Closure(string): ?string $topicUrl the URL of a visible topic's card, null for a hidden or unknown slug
     */
    public function render(string $text, GuideState $state, \Closure $topicUrl): Markup
    {
        $html = [];
        $paragraph = [];
        $list = null;
        $items = [];
        $conditions = [];

        foreach (explode("\n", self::clean($text, self::MAX_CHARS)) as $line) {
            $line = trim($line, ' ');
            if (1 === preg_match('/^:::\s*if\s+([a-z_]{1,32})$/D', $line, $m)) {
                $conditions[] = $state->flag($m[1]);
                continue;
            }
            if (':::' === $line) {
                array_pop($conditions);
                continue;
            }
            if (in_array(false, $conditions, true)) {
                continue;
            }

            if ('' === $line) {
                self::flushParagraph($paragraph, $html);
                self::flushList($list, $items, $html);
            } elseif (str_starts_with($line, '## ')) {
                self::flushParagraph($paragraph, $html);
                self::flushList($list, $items, $html);
                $html[] = '<h3>'.$this->inline(substr($line, 3), $topicUrl).'</h3>';
            } elseif (self::PLACEHOLDER_PAYMENT_METHODS === $line) {
                self::flushParagraph($paragraph, $html);
                self::flushList($list, $items, $html);
                if ([] !== $state->paymentMethods) {
                    $html[] = '<ul class="guide-methods">'.implode('', array_map(static fn (string $label): string => '<li>'.self::escape($label).'</li>', $state->paymentMethods)).'</ul>';
                }
            } elseif (1 === preg_match('/^(?:(-)|\d{1,3}\.) (.+)$/sD', $line, $m)) {
                self::flushParagraph($paragraph, $html);
                $kind = '-' === $m[1] ? 'ul' : 'ol';
                if ($kind !== $list) {
                    self::flushList($list, $items, $html);
                    $list = $kind;
                }
                $items[] = $this->inline($m[2], $topicUrl);
            } else {
                self::flushList($list, $items, $html);
                $paragraph[] = $this->inline($line, $topicUrl);
            }
        }
        self::flushParagraph($paragraph, $html);
        self::flushList($list, $items, $html);

        return new Markup(implode("\n", $html), 'UTF-8');
    }

    /**
     * @param list<string> $paragraph
     * @param list<string> $html
     */
    private static function flushParagraph(array &$paragraph, array &$html): void
    {
        if ([] !== $paragraph) {
            $html[] = '<p>'.implode('<br>', $paragraph).'</p>';
            $paragraph = [];
        }
    }

    /**
     * @param 'ul'|'ol'|null $list
     * @param list<string>   $items
     * @param list<string>   $html
     */
    private static function flushList(?string &$list, array &$items, array &$html): void
    {
        if (null !== $list) {
            $html[] = '<'.$list.'>'.implode('', array_map(static fn (string $item): string => '<li>'.$item.'</li>', $items)).'</'.$list.'>';
            $list = null;
            $items = [];
        }
    }

    /** A topic title: plain text, cleaned, cut at MAX_TITLE_CHARS (escaped by Twig when printed). */
    public function title(string $text): string
    {
        return trim(str_replace("\n", ' ', self::clean($text, self::MAX_TITLE_CHARS)));
    }

    /**
     * Text that must never be interpreted, e.g. the admin's support message (it may hold Telegram HTML): escaped as it
     * is, paragraphs on blank lines, line breaks kept. A tag in it is shown as text.
     */
    public function plain(string $text): Markup
    {
        $paragraphs = preg_split('/\n\s*\n/', trim(self::clean($text, self::MAX_CHARS))) ?: [];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            if ('' !== trim($paragraph)) {
                $html[] = '<p>'.implode('<br>', array_map(static fn (string $line): string => self::escape(trim($line)), explode("\n", trim($paragraph)))).'</p>';
            }
        }

        return new Markup(implode("\n", $html), 'UTF-8');
    }

    /** Valid UTF-8, LF line breaks, no control / format / unassigned characters, at most $max characters. */
    public static function clean(string $text, int $max): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = mb_substr($text, 0, $max);

        return preg_replace(self::FORBIDDEN, '', $text) ?? '';
    }

    /**
     * @param \Closure(string): ?string $topicUrl
     */
    private function inline(string $text, \Closure $topicUrl): string
    {
        $parts = preg_split(self::LINK, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return self::escape($text);
        }

        // [text, label, target, text, label, target, ..., text]
        $html = '';
        foreach ($parts as $i => $part) {
            $kind = $i % 3;
            if (0 === $kind) {
                $html .= self::bold($part);
            } elseif (1 === $kind) {
                $html .= $this->link($part, $parts[$i + 1], $topicUrl);
            }
        }

        return $html;
    }

    /**
     * @param \Closure(string): ?string $topicUrl
     */
    private function link(string $label, string $target, \Closure $topicUrl): string
    {
        $text = self::bold($label);
        if (1 === preg_match('/^#([a-z_]{1,32})$/D', $target, $m)) {
            $url = $topicUrl($m[1]);

            return null === $url ? $text : '<a href="'.self::escape($url).'" data-nav data-replace>'.$text.'</a>';
        }
        if (self::isSafeExternalUrl($target)) {
            return '<a href="'.self::escape($target).'" data-open-link rel="noopener noreferrer">'.$text.'</a>';
        }

        return $text;
    }

    /** https only, a host, no credentials; the URL is escaped again when it is written into the attribute. */
    private static function isSafeExternalUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && 'https' === strtolower($parts['scheme'] ?? '') && '' !== ($parts['host'] ?? '')
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    /** **bold** by splitting on the markers (linear; an unpaired marker stays as text). */
    private static function bold(string $text): string
    {
        $pieces = explode('**', $text);
        if (count($pieces) < 3) {
            return self::escape($text);
        }
        $html = '';
        $last = count($pieces) - 1;
        foreach ($pieces as $i => $piece) {
            if (0 === $i % 2) {
                $html .= self::escape($piece);
            } elseif ($i < $last) {
                $html .= '<strong>'.self::escape($piece).'</strong>';
            } else {
                // An opening marker without its pair: written back as text.
                $html .= '**'.self::escape($piece);
            }
        }

        return $html;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
