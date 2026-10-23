<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * Checks a finished test subprocess (tests/Support/bin/*, bin/console): it exited with 0 and printed none of the marks
 * of a real problem (PHP warnings/notices/deprecations/fatal errors, uncaught exceptions, stack traces, SQL errors) on
 * stdout or stderr. Ordinary log lines are not problems: what a subprocess logs depends on the environment (php.ini's
 * error_log, SHELL_VERBOSITY), so a test never requires empty output.
 */
trait SubprocessAssertions
{
    /** @var list<string> */
    private static array $subprocessProblems = ['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Uncaught', 'Stack trace', 'SQLSTATE'];

    /**
     * @return string the subprocess's stdout, trimmed (the helpers print their outcome there)
     */
    protected static function assertSubprocessClean(Process $process, string $label = 'subprocess'): string
    {
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $shown = sprintf("%s\n--- stdout ---\n%s\n--- stderr ---\n%s", $label, mb_substr($output, 0, 4000), mb_substr($errorOutput, 0, 4000));

        Assert::assertSame(0, $process->getExitCode(), $label.' exited with '.var_export($process->getExitCode(), true).': '.$shown);
        foreach (self::$subprocessProblems as $problem) {
            Assert::assertStringNotContainsString($problem, $output.$errorOutput, sprintf('%s printed "%s": %s', $label, $problem, $shown));
        }

        return trim($output);
    }
}
