<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\TelegramInitData;
use Psr\Log\AbstractLogger;

/**
 * Failed Mini App logins are logged with the reason and the address, never the initData, its hash or a token.
 */
final class MiniAppAuthLogTest extends IntegrationTestCase
{
    use MiniAppTestTrait;

    public function testRejectedLoginsAreLoggedWithoutTheInitData(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        self::getContainer()->set('logger', $logger);
        $this->startMiniAppClient();

        $forged = TelegramInitData::signed('42:another-bot', TelegramInitData::user('8201', 'Forged'));
        $this->postAuth($forged);
        $this->postAuth($this->initData('8201', time() - 7200));

        $rejections = array_values(array_filter($logger->records, static fn (array $r): bool => 'Mini App auth rejected' === $r[1]));
        self::assertCount(2, $rejections);
        self::assertSame(['bad_hash', 'expired'], array_map(static fn (array $r): string => $r[2]['reason'], $rejections));
        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        parse_str($forged, $fields);
        foreach ([(string) $fields['hash'], '8201', 'Forged', 'auth_date', $this->botToken()] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }
}
