<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * A time limit for a whole pass of panel calls, not per call (M2d B1: the attach pass right after a sale). While one is
 * open, ModernPanelHttpClient shortens every request's max_duration and connect timeout to the time left, retries a GET
 * only within it, and sends nothing once it is used up (PanelApiException NETWORK_TIMEOUT, not counted on the panel).
 * Nested passes keep the earlier end. Outside a pass nothing changes (the usual 10 s per request).
 */
final class PanelCallBudget
{
    private ?float $deadline = null;

    public function __construct(private readonly ClockInterface $clock = new NativeClock())
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function within(float $seconds, \Closure $work): mixed
    {
        $previous = $this->deadline;
        $end = $this->now() + $seconds;
        $this->deadline = null === $previous ? $end : min($previous, $end);
        try {
            return $work();
        } finally {
            $this->deadline = $previous;
        }
    }

    /** The end of the open pass (seconds, as now()), or null outside one. */
    public function deadline(): ?float
    {
        return $this->deadline;
    }

    /** Seconds left in the open pass (0 or less: used up), or null outside one. */
    public function secondsLeft(): ?float
    {
        return null === $this->deadline ? null : $this->deadline - $this->now();
    }

    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
