<?php

declare(strict_types=1);

namespace App\Subscription\UI;

use Twig\Attribute\AsTwigFilter;

/**
 * Formatting for the customer pages (templates/subscription/).
 */
final class SubscriptionFormat
{
    /** Traffic and file sizes in binary units, written the way customers know them (GB, MB). */
    #[AsTwigFilter('sub_bytes')]
    public static function bytes(?int $bytes): string
    {
        $bytes = max(0, (int) $bytes);
        foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 2, '.', ''), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' B';
    }

    /** A date for people: the Persian (Jalali) calendar in Persian when intl supports it, else Y-m-d. */
    #[AsTwigFilter('sub_date')]
    public static function date(?\DateTimeInterface $date, string $locale): string
    {
        if (null === $date) {
            return '';
        }
        if ('fa' === $locale && class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $date->getTimezone(), \IntlDateFormatter::TRADITIONAL);
            $formatted = $formatter->format($date);
            if (false !== $formatted) {
                return $formatted;
            }
        }

        return $date->format('Y-m-d');
    }
}
