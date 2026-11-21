<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Compares output to a golden file under tests/. Only UPDATE_BOT_SNAPSHOTS=1 writes files: a missing snapshot fails the
 * test, so CI (which never sets the switch) can neither create a snapshot nor pass against one it just wrote.
 * See tests/Bot/__snapshots__/README.md.
 */
final class SnapshotFile
{
    /**
     * @param string $path relative to tests/, e.g. "Bot/__snapshots__/checkout-trial.txt"
     */
    public static function assertMatches(string $path, string $actual): void
    {
        $file = dirname(__DIR__).'/'.$path;
        if ('1' === getenv('UPDATE_BOT_SNAPSHOTS')) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $actual);
        } elseif (!is_file($file)) {
            Assert::fail('snapshot missing: tests/'.$path.'; record it with UPDATE_BOT_SNAPSHOTS=1');
        }
        // Line endings depend on the checkout (core.autocrlf), not on the code.
        Assert::assertSame(str_replace("\r\n", "\n", (string) file_get_contents($file)), $actual, 'differs from tests/'.$path);
    }
}
