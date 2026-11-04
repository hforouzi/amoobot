<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PR P4, D5 and 6.3 of docs/design/plan-inbound-change.md: whatever the panel answers or throws, the command's output,
 * the logs (after RedactingLogger), the stored errors, the audit entries and the admin alerts hold ids and titles only:
 * no owner, link, UUID, subId, client email, token or panel credential. With zend.exception_ignore_args 0 (arguments in
 * traces) and 1.
 */
final class InboundMoveRedactionTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    /** @return iterable<string, array{string}> */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    #[DataProvider('ignoreArgs')]
    public function testNothingSecretLeavesTheRunner(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $this->setUpMove();
            $answered = $this->movableService(1);
            $thrown = $this->movableService(2);
            $leak = static fn (string $email, string $uuid, string $sub): string => sprintf('client %s (%s, sub %s) at %s/secret/panel/api/clients/x/%s token %s vless://%s@h', $email, $uuid, $sub, Fake3xuiPanel::ORIGIN, $email, Fake3xuiPanel::TOKEN, $uuid);
            // The first client's attach fails with the panel quoting it back; the second one's read throws.
            $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, $leak('tg_p1_1', (string) $answered->getClientUuid(), (string) $answered->getSubId())));
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_2', static function () use ($leak, $thrown): MockResponse {
                throw new \RuntimeException($leak('tg_p1_2', (string) $thrown->getClientUuid(), (string) $thrown->getSubId()));
            });
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);
            $alerts = count($this->http->telegramTexts());
            $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');

            [$code, $output] = $this->command(['--apply' => true]);
            [, $json] = $this->command(['--json' => true]);

            self::assertSame(1, $code, $output);
            self::assertSame(['failed', 'failed'], array_column($this->moves(), 'state'), 'both really failed: the test proves something');
            $texts = [
                'output' => $output,
                'dry run' => $json,
                // The existing per-request log of ModernPanelHttpClient (sanaei_modern_request*, not P4's) names the
                // request path, which holds the client email (its Telegram id is masked by RedactingLogger): left as is.
                'logs' => implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => !str_starts_with((string) $r['message'], 'sanaei_modern_request')))),
                'stored errors' => implode("\n", array_column($this->moves(), 'last_error')),
                'audit' => (string) json_encode($this->audits(), JSON_UNESCAPED_SLASHES),
                'alerts' => implode("\n", array_slice($this->http->telegramTexts(), $alerts)),
            ];
            self::assertNotSame('', $texts['alerts'], 'given up: alerts were sent');
            foreach ($texts as $where => $text) {
                foreach ([...$this->secretsOf($answered), ...$this->secretsOf($thrown), 'Owner Kaveh', '8940001', '/secret/', 'clients/x/tg_'] as $secret) {
                    self::assertStringNotContainsString($secret, $text, $where);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }
}
