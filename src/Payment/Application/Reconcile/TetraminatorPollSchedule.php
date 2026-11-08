<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

/**
 * When the Tetraminator timer asks about one invoice (docs/plugins/tetraminator-gateway/design.md, decision 4). Pure:
 * no clock, no database.
 *
 * Age is counted from the invoice's creation, which is Payment::expiresAt minus the invoice lifetime (createdAt is the
 * row's age, and a row can be older than its invoice). The timer runs every TICK_SECONDS; the run is placed on its tick
 * (now rounded down to the tick), so a run that starts a few seconds late sees the same ages as an on-time one.
 *
 * A payment is due when, since the previous tick, its age crossed a multiple of the step of its tier (no stored state):
 *
 *   age            open (pending)                    rejected unpaid (cancelled, expired, superseded)
 *   < 2 min        never                             never
 *   2 to 20 min    every tick (2 min)                every tick
 *   20 to 70 min   every 6 min                       every 6 min
 *   70 min         the first tick past it            -
 *   70 min to 24 h every 10 min (its answers so far   one sweep at 6 h
 *                  were errors: an unpaid answer at
 *                  70 min expires it)
 *   24 h and over  every tick (the batch expires it)  one sweep at 24 h, then never
 *
 * A skipped tick (a deploy) just skips that poll: the next due crossing asks again.
 */
final class TetraminatorPollSchedule
{
    public const TICK_SECONDS = 120;
    /** TetraminatorGatewayPlugin::INVOICE_LIFETIME_SECONDS. */
    public const INVOICE_LIFETIME_SECONDS = 3600;

    public const MIN_AGE = 120;
    public const FAST_UNTIL = 1200;
    public const FAST_STEP = 120;
    public const SLOW_STEP = 360;
    /** From here an open invoice is dead: a well-formed unpaid answer expires the payment. */
    public const OVERDUE_AGE = 4200;
    public const OVERDUE_STEP = 600;
    /** An open payment with still no well-formed answer is expired at this age, with an error. */
    public const GIVE_UP_AGE = 86400;
    /** The late sweeps of a rejected-unpaid payment. */
    public const LATE_SWEEPS = [21600, 86400];

    /** The tick a run at $now belongs to, as a Unix timestamp. */
    public static function tick(\DateTimeImmutable $now): int
    {
        return intdiv($now->getTimestamp(), self::TICK_SECONDS) * self::TICK_SECONDS;
    }

    /** The invoice's age in seconds at $tick, or null without an expiry (no invoice). */
    public static function age(?\DateTimeImmutable $expiresAt, int $tick): ?int
    {
        return null === $expiresAt ? null : $tick - ($expiresAt->getTimestamp() - self::INVOICE_LIFETIME_SECONDS);
    }

    public static function isDue(int $age, bool $open): bool
    {
        if ($age < self::MIN_AGE) {
            return false;
        }
        if ($age < self::FAST_UNTIL) {
            return true;
        }
        if ($age < self::OVERDUE_AGE) {
            return self::crossed($age, self::SLOW_STEP);
        }
        if (!$open) {
            foreach (self::LATE_SWEEPS as $sweep) {
                if ($age >= $sweep && $age - self::TICK_SECONDS < $sweep) {
                    return true;
                }
            }

            return false;
        }

        return $age >= self::GIVE_UP_AGE
            || $age - self::TICK_SECONDS < self::OVERDUE_AGE
            || self::crossed($age, self::OVERDUE_STEP);
    }

    /** A multiple of $step lies in (age - tick, age]. */
    private static function crossed(int $age, int $step): bool
    {
        return intdiv($age, $step) > intdiv($age - self::TICK_SECONDS, $step);
    }
}
