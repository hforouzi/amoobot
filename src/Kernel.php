<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The application's timezone, whatever the host's php.ini says: DATETIME columns hold wall-clock values without a
     * zone, and every stored or compared timestamp has always been UTC (PHP's default when date.timezone is unset). Code
     * that needs "today" or a day boundary (e.g. the reseller daily spend cap) uses this constant.
     */
    public const TIMEZONE = 'UTC';

    /**
     * Sets TIMEZONE on every boot() call (web requests, console commands, workers, tests); deploy.sh also pins
     * date.timezone = UTC.
     */
    public function boot(): void
    {
        date_default_timezone_set(self::TIMEZONE);

        parent::boot();
    }
}
