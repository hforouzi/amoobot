<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Payment\Application\Reconcile\TetraminatorPollSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The timer's per-invoice schedule (design.md decision 4) at every tier edge. Ages are seconds since the invoice was
 * created, at the run's tick.
 */
final class TetraminatorPollScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function edges(): iterable
    {
        foreach (['open' => true, 'rejected' => false] as $kind => $open) {
            yield "$kind 1:59 never" => [119, $open, false];
            yield "$kind 2:00 first poll" => [120, $open, true];
            yield "$kind 2:01 every tick" => [121, $open, true];
            yield "$kind 19:59 every tick" => [1199, $open, true];
            yield "$kind 20:00 slower now (6 min steps, next at 24:00)" => [1200, $open, false];
            yield "$kind 20:01" => [1201, $open, false];
            yield "$kind 24:00 a 6-minute step" => [1440, $open, true];
            yield "$kind 25:59 the same step, seen one tick later" => [1559, $open, true];
            yield "$kind 26:00 past it" => [1560, $open, false];
            yield "$kind 69:59" => [4199, $open, false];
        }
        yield 'open 70:00 the first tick past the invoice' => [4200, true, true];
        yield 'open 71:59 still that tick' => [4319, true, true];
        yield 'open 72:00 then every 10 min (errors so far)' => [4320, true, false];
        yield 'open 80:00 a 10-minute step' => [4800, true, true];
        yield 'open 6 h a 10-minute step' => [21600, true, true];
        yield 'open 23:58 a 10-minute step' => [86280, true, false];
        yield 'open 24 h given up: every tick until expired' => [86400, true, true];
        yield 'open 25 h' => [90000, true, true];
        yield 'rejected 70:00 nothing until the sweep' => [4200, false, false];
        yield 'rejected 80:00' => [4800, false, false];
        yield 'rejected 5:59 h' => [21599, false, false];
        yield 'rejected 6 h sweep' => [21600, false, true];
        yield 'rejected 6 h + 1:59 the same sweep, one tick' => [21719, false, true];
        yield 'rejected 6 h + 2:00 past it' => [21720, false, false];
        yield 'rejected 24 h sweep' => [86400, false, true];
        yield 'rejected 24 h + 2:00 never again' => [86520, false, false];
        yield 'rejected 30 h' => [108000, false, false];
    }

    #[DataProvider('edges')]
    public function testTheTierEdges(int $age, bool $open, bool $due): void
    {
        self::assertSame($due, TetraminatorPollSchedule::isDue($age, $open));
    }

    public function testASkippedTickSkipsThatPollOnly(): void
    {
        // Due at 24:00. The run of that tick did not happen (a deploy): the run of 26:00 does not make it up...
        self::assertTrue(TetraminatorPollSchedule::isDue(1440, true));
        self::assertFalse(TetraminatorPollSchedule::isDue(1560, true));
        // ...the next step does, at 30:00.
        self::assertTrue(TetraminatorPollSchedule::isDue(1800, true));
        // The same for the overdue tier: the 70-minute poll missed, the next is at 80:00.
        self::assertFalse(TetraminatorPollSchedule::isDue(4320, true));
        self::assertTrue(TetraminatorPollSchedule::isDue(4800, true));
    }

    public function testEachAgeIsDueInExactlyOneOfTwoConsecutiveTicksInTheSlowTiers(): void
    {
        // Every 6-minute step between 20 and 70 minutes is seen by exactly one tick, wherever the invoice was created.
        foreach ([0, 1, 59, 60, 119] as $offset) {
            $polls = 0;
            for ($age = 1200 + $offset; $age < 4200; $age += TetraminatorPollSchedule::TICK_SECONDS) {
                $polls += TetraminatorPollSchedule::isDue($age, true) ? 1 : 0;
            }
            self::assertSame(8, $polls, 'the eight 6-minute steps from 24:00 to 66:00, offset '.$offset);
        }
    }

    public function testARunIsPlacedOnItsTick(): void
    {
        $tick = TetraminatorPollSchedule::tick(new \DateTimeImmutable('@1800000000'));

        self::assertSame(1800000000 - 1800000000 % 120, $tick);
        self::assertSame($tick, TetraminatorPollSchedule::tick(new \DateTimeImmutable('@'.($tick + 119))), 'a run that starts late sees the same ages');
        self::assertSame(120, TetraminatorPollSchedule::age(new \DateTimeImmutable('@'.($tick - 120 + 3600)), $tick), 'age from expiresAt minus the invoice lifetime');
        self::assertNull(TetraminatorPollSchedule::age(null, $tick));
    }
}
