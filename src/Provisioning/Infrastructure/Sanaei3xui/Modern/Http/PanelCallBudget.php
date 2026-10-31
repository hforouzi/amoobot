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
 *
 * A request is sent only with at least MIN_SECONDS_TO_SEND left (M2e E2): curl takes max_duration in whole
 * milliseconds and 0 means no limit at all, so a sliver of time left would have sent an unbounded request.
 */
final class PanelCallBudget
{
    /** Less than this left (50 ms): nothing more is sent in this pass. */
    public const MIN_SECONDS_TO_SEND = 0.05;
    /** The smallest max_duration / timeout ever given to the HTTP client (1 ms: curl's unit; never 0 = unlimited). */
    public const MIN_REQUEST_SECONDS = 0.001;

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

    /** Whether a request may still be sent in the open pass (always outside one). */
    public function canSend(): bool
    {
        $left = $this->secondsLeft();

        // In whole milliseconds (curl's unit): the deadline is an epoch float, so "50 ms" may come out as 49.99999 ms.
        return null === $left || round($left * 1000) >= round(self::MIN_SECONDS_TO_SEND * 1000);
    }

    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
