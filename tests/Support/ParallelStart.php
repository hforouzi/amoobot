<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A start instant for parallel helper processes that does not depend on how long their kernels take to boot (they
 * boot one at a time, and slowly on a loaded machine): each helper says it is ready and waits (bin/start-at.php); the
 * test sets the start once all of them are ready. Offsets between the processes are kept exactly.
 */
trait ParallelStart
{
    private ?string $startBarrier = null;

    /** A helper's "start at" argument: the common start + $offset seconds. */
    private function startAt(float $offset = 0.0): string
    {
        $this->startBarrier ??= sys_get_temp_dir().'/amoobot-start-'.bin2hex(random_bytes(6));

        return $this->startBarrier.'+'.sprintf('%.3F', $offset);
    }

    /**
     * Once each of $processes has booted and is waiting, sets the start $lead seconds from now and returns it. Null when
     * no start was asked for (startAt() not called).
     *
     * @param list<Process> $processes
     */
    private function releaseStart(array $processes, float $lead = 0.3): ?float
    {
        $barrier = $this->startBarrier;
        if (null === $barrier) {
            return null;
        }
        $until = microtime(true) + 180;
        while (count(glob($barrier.'.ready.*') ?: []) < count($processes)) {
            foreach ($processes as $process) {
                if (!$process->isRunning()) {
                    self::fail(sprintf("A process ended before the start.\n--- stdout ---\n%s\n--- stderr ---\n%s", mb_substr($process->getOutput(), -4000), mb_substr($process->getErrorOutput(), -4000)));
                }
            }
            if (microtime(true) > $until) {
                self::fail('The processes did not boot within 180 s.');
            }
            usleep(10_000);
        }
        $start = microtime(true) + $lead;
        file_put_contents($barrier.'.tmp', sprintf('%.6F', $start));
        rename($barrier.'.tmp', $barrier);

        return $start;
    }

    private function removeStart(): void
    {
        if (null !== $this->startBarrier) {
            foreach ([$this->startBarrier, ...(glob($this->startBarrier.'.ready.*') ?: [])] as $file) {
                @unlink($file);
            }
        }
        $this->startBarrier = null;
    }
}
