<?php

declare(strict_types=1);

namespace App\Admin\UI\Dashboard;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/** AD3 health lines: "how long ago", in the admin's language and digits. */
final class HealthFormat
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly AdminCalendar $calendar,
    ) {
    }

    #[AsTwigFilter('health_age')]
    public function age(int $timestamp, string $locale = 'fa'): string
    {
        $seconds = max(0, $this->clock->now()->getTimestamp() - $timestamp);
        [$key, $count] = match (true) {
            $seconds < 120 => ['ad3.age.seconds', $seconds],
            $seconds < 7200 => ['ad3.age.minutes', intdiv($seconds, 60)],
            $seconds < 172800 => ['ad3.age.hours', intdiv($seconds, 3600)],
            default => ['ad3.age.days', intdiv($seconds, 86400)],
        };

        return $this->translator->trans($key, ['%count%' => $this->calendar->number($count, $locale)], 'admin', $locale);
    }
}
