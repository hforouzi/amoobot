<?php

declare(strict_types=1);

/*
 * Test helper for the parallel tests: waits for the instant the test chose for this process to start its work.
 * $at is either "<barrierFile>+<offsetSeconds>" (ParallelStart::startAt()): this process says it has booted
 * (<barrierFile>.ready.<pid>), waits until the test has written the start time into <barrierFile>, which it does once
 * every process has booted (at most 180 s), then sleeps until that time + offset; or a unix microtime to wait for.
 * Returns the instant it started at.
 */
function waitForStartAt(string $at): float
{
    $plus = strrpos($at, '+');
    if (is_numeric($at) || false === $plus) {
        $start = (float) $at;
    } else {
        $barrier = substr($at, 0, $plus);
        touch($barrier.'.ready.'.getmypid());
        $until = microtime(true) + 180;
        while (!is_file($barrier)) {
            if (microtime(true) > $until) {
                throw new RuntimeException('The test did not release the start within 180 s.');
            }
            usleep(10_000);
        }
        $start = (float) file_get_contents($barrier) + (float) substr($at, $plus + 1);
    }
    $wait = $start - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }

    return $start;
}
