<?php

declare(strict_types=1);

namespace App\Admin\UI\Dashboard;

use Twig\Attribute\AsTwigFilter;

/** Dashboard days only; storage and the application timezone remain UTC. */
final class AdminCalendar
{
    public const TIMEZONE = 'Asia/Tehran';

    public function midnight(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
    }

    #[AsTwigFilter('dashboard_number')]
    public function number(int|float|string $value, string $locale = 'fa'): string
    {
        return str_starts_with($locale, 'fa') ? strtr((string) $value, array_combine(str_split('0123456789'), ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'])) : (string) $value;
    }

    #[AsTwigFilter('dashboard_date')]
    public function date(\DateTimeInterface $date, string $locale = 'fa'): string
    {
        $formatter = new \IntlDateFormatter(str_starts_with($locale, 'fa') ? 'fa_IR@calendar=persian' : 'en_US', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::TIMEZONE, \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');

        return $this->number((string) $formatter->format($date), $locale);
    }
}
