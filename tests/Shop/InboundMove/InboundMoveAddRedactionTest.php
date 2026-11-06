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
 * PR P11b (docs/design/plan-inbound-add-remove.md 9.4): whatever a panel answers or an exception quotes, nothing secret
 * leaves an add or its undo: not to the output, the logs, the stored errors, the held row, the audit or the alerts, with
 * zend.exception_ignore_args 0 and 1.
 */
final class InboundMoveAddRedactionTest extends IntegrationTestCase
{
    use InboundAddFixtures;

    /** @return iterable<string, array{string}> */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    #[DataProvider('ignoreArgs')]
    public function testNothingSecretLeavesAnAdd(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $this->setUpAdd();
            $answered = $this->goldService(1);
            $thrown = $this->goldService(2);
            $leak = self::leak(...);
            $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, $leak('tg_p1_1', (string) $answered->getClientUuid(), (string) $answered->getSubId())));
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_2', static function () use ($leak, $thrown): MockResponse {
                throw new \RuntimeException($leak('tg_p1_2', (string) $thrown->getClientUuid(), (string) $thrown->getSubId()));
            });
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);
            $alerts = count($this->http->telegramTexts());
            $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');

            [$code, $output] = $this->add(['--apply' => true]);
            [, $json] = $this->add(['--json' => true]);

            self::assertSame(1, $code, $output);
            self::assertSame(['failed', 'failed'], array_column($this->moves(), 'state'), 'both really failed: the test proves something');
            $texts = $this->texts($output."\n".$json, $logger, $logs, $alerts);
            self::assertNotSame('', $texts['alerts'], 'given up: alerts were sent');
            $this->assertClean($texts, [$answered, $thrown]);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    #[DataProvider('ignoreArgs')]
    public function testNothingSecretLeavesAnUndo(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $this->setUpAdd();
            $answered = $this->goldService(1);
            $thrown = $this->goldService(2);
            $leak = self::leak(...);
            [$code, $applied] = $this->add(['--apply' => true]);
            self::assertSame(0, $code, $applied);
            $runId = (int) $this->runs()[0]['id'];
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);
            $alerts = count($this->http->telegramTexts());
            $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, $leak('tg_p1_1', (string) $answered->getClientUuid(), (string) $answered->getSubId())));
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_2', static function () use ($leak, $thrown): MockResponse {
                throw new \RuntimeException($leak('tg_p1_2', (string) $thrown->getClientUuid(), (string) $thrown->getSubId()));
            });

            [$code, $undo] = $this->command(['--undo' => (string) $runId]);

            self::assertSame(1, $code, $undo);
            self::assertSame(['failed', 'failed'], array_column($this->moves(), 'state'));
            $held = implode("\n", array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT last_error FROM vpn_service_inbound')));
            $this->assertClean($this->texts($undo."\n".$held, $logger, $logs, $alerts), [$answered, $thrown]);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    private static function leak(string $email, string $uuid, string $sub): string
    {
        return sprintf('client %s (%s, sub %s) at %s/secret/panel/api/clients/x/%s token %s vless://%s@h', $email, $uuid, $sub, Fake3xuiPanel::ORIGIN, $email, Fake3xuiPanel::TOKEN, $uuid);
    }

    /** @return array<string, string> */
    private function texts(string $output, RecordingLogger $logger, int $logs, int $alerts): array
    {
        return [
            'output' => $output,
            // The existing per-request log of ModernPanelHttpClient (sanaei_modern_request*): left as is (P4).
            'logs' => implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => !str_starts_with((string) $r['message'], 'sanaei_modern_request')))),
            'stored errors' => implode("\n", array_column($this->moves(), 'last_error')),
            'audit' => (string) json_encode($this->audits(), JSON_UNESCAPED_SLASHES),
            'alerts' => implode("\n", array_slice($this->http->telegramTexts(), $alerts)),
        ];
    }

    /**
     * @param array<string, string>            $texts
     * @param list<\App\Entity\VpnService>     $services
     */
    private function assertClean(array $texts, array $services): void
    {
        $secrets = ['Owner Kaveh', '8940001', '/secret/', 'clients/x/tg_'];
        foreach ($services as $service) {
            array_push($secrets, ...$this->secretsOf($service));
        }
        foreach ($texts as $where => $text) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $text, $where);
            }
        }
    }
}
