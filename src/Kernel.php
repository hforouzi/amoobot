<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The application's timezone is UTC, whatever the host's php.ini says: DATETIME columns hold wall-clock values without
     * a zone, and every stored or compared timestamp has always been UTC (PHP's default when date.timezone is unset).
     * Set on every boot() call (web requests, console commands, workers, tests); deploy.sh also pins date.timezone = UTC.
     */
    public function boot(): void
    {
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
