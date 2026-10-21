<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

use App\Payment\Domain\Money\AmountNormalizer;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Concise, translated HTML for admin list and detail cells (formatValue + admin/field/formatted.html.twig). Every
 * value is escaped here; empty values are "—". Amounts are in the store currency (AmountNormalizer::SHOP_CURRENCY),
 * as stored: nothing is converted.
 */
final class AdminFormat
{
    public const TEMPLATE = 'admin/field/formatted.html.twig';
    public const NONE = '<span class="admin-none">—</span>';
    private const GB = 1073741824;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * A read-only column whose cell is the HTML $format returns (escaped there), for a computed or reformatted value.
     *
     * @param callable(mixed, ?object): string $format
     */
    public static function field(string $property, callable $format, ?string $label = null): Field
    {
        return Field::new($property, $label)
            ->setTemplatePath(self::TEMPLATE)
            ->formatValue($format)
            ->setSortable(false)
            ->hideOnForm();
    }

    public function status(mixed $status): string
    {
        $status = (string) $status;
        if ('' === $status) {
            return self::NONE;
        }

        return sprintf(
            '<span class="badge badge-%s admin-status" data-status="%s">%s</span>',
            AdminStatusBadge::badgeMap()[$status] ?? 'secondary',
            self::e($status),
            self::e($this->trans('status.'.$status)),
        );
    }

    public function money(?int $amount): string
    {
        if (null === $amount) {
            return self::NONE;
        }

        return sprintf('<span class="admin-money"><bdi>%s</bdi> %s</span>', number_format($amount), self::e($this->trans('currency.'.AmountNormalizer::SHOP_CURRENCY)));
    }

    public function number(?int $value): string
    {
        return null === $value ? self::NONE : '<bdi>'.number_format($value).'</bdi>';
    }

    public function date(?\DateTimeInterface $at): string
    {
        return null === $at ? self::NONE : sprintf('<time datetime="%s"><bdi>%s</bdi></time>', $at->format(\DATE_ATOM), $at->format('Y-m-d H:i'));
    }

    /**
     * The date and how long until (or since) it: "2026-11-15 · 25 days left", "expired 3 days ago".
     */
    public function expiry(?\DateTimeInterface $at): string
    {
        if (null === $at) {
            return '<span class="admin-none">'.self::e($this->trans('format.unlimited')).'</span>';
        }
        // Calendar days in the app's time zone: tomorrow 08:00 is "1 day left", not "today".
        $now = $this->clock->now();
        $seconds = $at->getTimestamp() - $now->getTimestamp();
        $day = \DateTimeImmutable::createFromInterface($at)->setTimezone($now->getTimezone())->setTime(0, 0);
        $days = (int) $now->setTime(0, 0)->diff($day)->days;
        [$key, $class] = match (true) {
            $seconds < 0 => ['format.expired_days_ago', 'text-danger'],
            $days < 3 => ['format.days_left', 'text-warning'],
            default => ['format.days_left', 'text-muted'],
        };

        return sprintf('<span class="admin-expiry"><bdi>%s</bdi> <small class="%s">%s</small></span>', $at->format('Y-m-d'), $class, self::e($this->trans($key, ['%count%' => $days])));
    }

    /**
     * "5 minutes ago", "3 hours ago", "2 days ago" (the exact time on hover).
     */
    public function relative(?\DateTimeInterface $at): string
    {
        if (null === $at) {
            return self::NONE;
        }
        $seconds = max(0, $this->clock->now()->getTimestamp() - $at->getTimestamp());
        [$key, $count] = match (true) {
            $seconds < 60 => ['format.just_now', 0],
            $seconds < 3600 => ['format.minutes_ago', intdiv($seconds, 60)],
            $seconds < 86400 => ['format.hours_ago', intdiv($seconds, 3600)],
            default => ['format.days_ago', intdiv($seconds, 86400)],
        };

        return sprintf('<time datetime="%s" title="%s">%s</time>', $at->format(\DATE_ATOM), $at->format('Y-m-d H:i'), self::e($this->trans($key, ['%count%' => $count])));
    }

    /**
     * "12.4 / 50 GB" with a small progress bar; a limit of 0 is unlimited.
     */
    public function traffic(?int $usedBytes, ?int $limitBytes): string
    {
        $used = ($usedBytes ?? 0) / self::GB;
        $limit = ($limitBytes ?? 0) / self::GB;
        if ($limit <= 0) {
            return sprintf('<span class="admin-traffic"><bdi>%s GB</bdi> / %s</span>', self::gb($used), self::e($this->trans('format.unlimited')));
        }
        $percent = (int) min(100, round($used / $limit * 100));
        $level = $percent >= 95 ? 'danger' : ($percent >= 80 ? 'warning' : 'success');

        return sprintf(
            '<span class="admin-traffic"><bdi>%s / %s GB</bdi><span class="admin-progress" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100"><span class="admin-progress-bar bg-%s" style="width: %d%%"></span></span></span>',
            self::gb($used), self::gb($limit), $percent, $level, $percent,
        );
    }

    /**
     * Only the host (and a non-default port) of a URL: never its path, query or credentials, which can hold secrets
     * (a panel's secret path, tokens).
     */
    public function host(?string $url): string
    {
        $host = self::hostOf($url);

        return null === $host ? self::NONE : '<bdi class="admin-host">'.self::e($host).'</bdi>';
    }

    public static function hostOf(?string $url): ?string
    {
        $parts = parse_url(trim((string) $url));
        if (false === $parts || !isset($parts['host'])) {
            return null;
        }

        return $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * A long technical value cut to its start, the whole value in the tooltip and a copy button (assets/admin/admin.js).
     */
    public function copyable(?string $value, int $visible = 18): string
    {
        $value = (string) $value;
        if ('' === $value) {
            return self::NONE;
        }
        $short = mb_strlen($value) > $visible ? mb_substr($value, 0, $visible).'…' : $value;

        return sprintf(
            '<span class="admin-copyable"><code dir="ltr" title="%1$s">%2$s</code><button type="button" class="btn btn-link btn-sm admin-copy" data-copy="%1$s" title="%3$s" aria-label="%3$s"><i class="fa fa-copy"></i></button></span>',
            self::e($value), self::e($short), self::e($this->trans('format.copy')),
        );
    }

    public function yesNo(mixed $value): string
    {
        $yes = true === $value || '1' === (string) $value;

        return sprintf('<span class="badge badge-%s">%s</span>', $yes ? 'success' : 'secondary', self::e($this->trans($yes ? 'common.yes' : 'common.no')));
    }

    public function link(string $url, string $html): string
    {
        return sprintf('<a href="%s">%s</a>', self::e($url), $html);
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'admin');
    }

    private static function gb(float $gb): string
    {
        return rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.');
    }
}
