<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Shared\Infrastructure\RedactingLogger;
use App\Shared\Infrastructure\SafeErrorLog;
use App\Tests\Support\CapturesErrorLog;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\RecordingLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * M2e E3: the logger path (RedactingLogger, SafeErrorLog, SecretRedactor::forLog()) never recurses without bound,
 * never exhausts memory, never throws, and never writes a line it could not redact.
 */
final class LogRedactionLimitsTest extends TestCase
{
    use CapturesErrorLog;

    private const ID = '512345678';

    private RecordingLogger $recorder;

    #[RunInSeparateProcess]
    public function testASelfReferencingArrayStopsAtTheDepthLimit(): void
    {
        ini_set('memory_limit', '256M');
        $context = ['a' => 1, 'chat_id' => (int) self::ID];
        $context['self'] = &$context;

        $this->logger()->error('loop', $context);

        $record = $this->records()[0][2];
        self::assertSame(SecretRedactor::MASK, $record['chat_id']);
        self::assertStringContainsString(RedactingLogger::DEPTH_LIMIT, print_r($record, true));
        self::assertLessThan(64 << 20, memory_get_peak_usage(), 'no runaway memory');
    }

    public function testDeepNestingIsCutAtTheDepthLimit(): void
    {
        $deep = ['tg' => 'tg_'.self::ID.'_order_1'];
        for ($i = 0; $i < 200; ++$i) {
            $deep = ['level' => $deep];
        }

        $this->logger()->error('deep', ['deep' => $deep]);

        $dump = print_r($this->records()[0][2], true);
        self::assertStringContainsString(RedactingLogger::DEPTH_LIMIT, $dump);
        self::assertSame(RedactingLogger::MAX_DEPTH, substr_count($dump, 'Array'));
        self::assertStringNotContainsString(self::ID, $dump);
    }

    public function testAHugeContextIsCutAtTheNodeLimit(): void
    {
        $this->logger()->error('wide', ['rows' => array_fill(0, 20000, 'chat_id='.self::ID)]);

        $rows = $this->records()[0][2]['rows'];
        self::assertLessThanOrEqual(RedactingLogger::MAX_NODES, count($rows));
        self::assertSame(RedactingLogger::NODE_LIMIT, end($rows));
        self::assertStringNotContainsString(self::ID, print_r($rows, true));
    }

    public function testAHugeStringIsCutAndStillRedacted(): void
    {
        $huge = 'chat_id='.self::ID.' '.str_repeat('x', 20 << 20);

        $this->logger()->error($huge, ['body' => $huge]);
        $written = $this->captureErrorLog(static fn () => SafeErrorLog::write($huge));

        [, $message, $context] = $this->records()[0];
        self::assertLessThan(RedactingLogger::MAX_STRING_BYTES + 100, strlen($message));
        self::assertLessThan(RedactingLogger::MAX_STRING_BYTES + 100, strlen($context['body']));
        self::assertStringStartsWith('chat_id=[redacted] xxx', $message);
        self::assertStringEndsWith('bytes cut]', $context['body']);
        self::assertStringNotContainsString(self::ID, $written);
        self::assertLessThan(RedactingLogger::MAX_STRING_BYTES + 200, strlen($written));
    }

    public function testAThrowingToStringIsUnprintableAndNothingThrows(): void
    {
        $bad = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('boom');
            }
        };
        $inner = new Logger('debug', 'php://memory');
        $logger = new RedactingLogger($inner);

        $logger->error('value {bad}', ['bad' => $bad]);
        $logger->error($bad, []);
        $this->logger()->error('value {bad}', ['bad' => $bad]);

        self::assertSame(RedactingLogger::UNPRINTABLE, $this->records()[0][2]['bad']);
        self::assertSame("[error] value [unprintable]\n[error] [unprintable]\n", self::written($inner));
    }

    public function testAnInnerLoggerThatThrowsDoesNotBreakTheCaller(): void
    {
        $logger = new RedactingLogger(new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \LogicException('handler down');
            }
        });

        $logger->critical('still fine', ['exception' => new \RuntimeException('x')]);
        $this->addToAssertionCount(1);
    }

    public function testClosedResourcesObjectsAndBinaryPassThrough(): void
    {
        $handle = fopen('php://memory', 'r');
        fclose($handle);
        $date = new \DateTimeImmutable('2026-10-31');
        $binary = random_bytes(512)."\xff chat_id=".self::ID;

        $this->logger()->error('mixed', ['fh' => $handle, 'date' => $date, 'raw' => $binary, 'n' => null, 'f' => 1.5]);

        $context = $this->records()[0][2];
        self::assertSame($handle, $context['fh']);
        self::assertSame($date, $context['date']);
        self::assertNull($context['n']);
        self::assertSame(1.5, $context['f']);
        self::assertStringEndsWith('chat_id=[redacted]', $context['raw']);
    }

    public function testALineThatCannotBeRedactedIsWithheldNotWrittenRaw(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $out = SecretRedactor::forLog('callback chat_id='.self::ID.' '.str_repeat('a ', 50));
            $written = $this->captureErrorLog(static fn () => SafeErrorLog::write('callback chat_id='.self::ID.' '.str_repeat('a ', 50)));
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        self::assertSame(SecretRedactor::WITHHELD, $out);
        self::assertStringContainsString(SecretRedactor::WITHHELD, $written);
        self::assertStringNotContainsString(self::ID, $written);
    }

    private function logger(): RedactingLogger
    {
        $this->recorder = new RecordingLogger(new NullLogger());

        return new RedactingLogger($this->recorder);
    }

    /** @return list<array{mixed, string, array<mixed>}> */
    private function records(): array
    {
        return array_map(static fn (array $r): array => [$r['level'], $r['message'], $r['context']], $this->recorder->records);
    }

    private static function written(Logger $logger): string
    {
        $handle = (new \ReflectionProperty($logger, 'handle'))->getValue($logger);
        rewind($handle);

        return (string) preg_replace('/^\S+ /m', '', (string) stream_get_contents($handle));
    }
}
