<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Tests\Support\SnapshotFile;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * A missing golden file fails the test and is not created: only UPDATE_BOT_SNAPSHOTS=1 writes snapshots.
 */
final class SnapshotFileTest extends TestCase
{
    private string|false $update;
    private string $path;

    protected function setUp(): void
    {
        $this->update = getenv('UPDATE_BOT_SNAPSHOTS');
        putenv('UPDATE_BOT_SNAPSHOTS');
        $this->path = 'Bot/__snapshots__/missing-'.bin2hex(random_bytes(6)).'.txt';
    }

    protected function tearDown(): void
    {
        $file = dirname(__DIR__).'/'.$this->path;
        if (is_file($file)) {
            unlink($file);
        }
        putenv(false === $this->update ? 'UPDATE_BOT_SNAPSHOTS' : 'UPDATE_BOT_SNAPSHOTS='.$this->update);
    }

    public function testAMissingSnapshotFailsAndIsNotWritten(): void
    {
        try {
            SnapshotFile::assertMatches($this->path, "anything\n");
            self::fail('a missing snapshot passed');
        } catch (AssertionFailedError $e) {
            self::assertSame('snapshot missing: tests/'.$this->path.'; record it with UPDATE_BOT_SNAPSHOTS=1', $e->getMessage());
        }

        self::assertFileDoesNotExist(dirname(__DIR__).'/'.$this->path);
    }
}
