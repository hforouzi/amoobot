<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelCallBudget;
use App\Shared\Infrastructure\SecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * M2e E2: inside a PanelCallBudget pass a request is sent only with at least 50 ms left, and the limits given to the
 * HTTP client are never below 1 ms (curl counts max_duration in whole milliseconds; 0 = no limit).
 */
final class PanelCallBudgetTest extends TestCase
{
    private MockClock $clock;
    private PanelCallBudget $budget;
    /** @var list<array{string, float, float}> method, max_duration, timeout of each request that was sent */
    private array $sent = [];
    /** @var list<MockResponse> */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-31 12:00:00');
        $this->budget = new PanelCallBudget($this->clock);
    }

    /** @return iterable<string, array{float, bool}> seconds left when the request is made, whether it is sent */
    public static function boundary(): iterable
    {
        yield 'used up' => [0.0, false];
        yield 'past the end' => [-2.0, false];
        yield '1 ms left' => [0.001, false];
        yield '49 ms left' => [0.049, false];
        yield '50 ms left' => [0.05, true];
        yield '2 s left' => [2.0, true];
    }

    #[DataProvider('boundary')]
    public function testARequestIsSentOnlyWithAtLeast50MsLeft(float $left, bool $sent): void
    {
        $outcome = $this->budget->within(15, function () use ($left): string {
            $this->clock->sleep(15 - $left);
            try {
                $this->client()->post($this->panel(), 'panel/api/clients/x/attach', ['inboundIds' => [2]]);

                return 'sent';
            } catch (PanelApiException $e) {
                return $e->getMessage();
            }
        });

        if (!$sent) {
            self::assertSame([], $this->sent, 'nothing reaches the HTTP client');
            self::assertStringContainsString('not sent: the time limit of this pass is used up', $outcome);

            return;
        }
        self::assertSame('sent', $outcome);
        self::assertCount(1, $this->sent);
        [, $maxDuration, $timeout] = $this->sent[0];
        self::assertEqualsWithDelta(min(ModernPanelHttpClient::MAX_DURATION_S, $left), $maxDuration, 1e-6);
        self::assertGreaterThanOrEqual(PanelCallBudget::MIN_REQUEST_SECONDS, $maxDuration);
        self::assertGreaterThanOrEqual(PanelCallBudget::MIN_REQUEST_SECONDS, $timeout);
        self::assertGreaterThanOrEqual(1, (int) (1000 * $maxDuration), 'never 0 ms for curl (= unlimited)');
    }

    public function testAGetIsNotRetriedWhenLessThan50MsWouldBeLeftAfterTheBackoff(): void
    {
        $this->answers = [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503])];
        $outcome = $this->budget->within(15, function (): string {
            // The first backoff of a transient GET failure (ModernPanelHttpClient::BACKOFF_MS[0]) would leave 40 ms.
            $this->clock->sleep(15 - 0.04 - $this->firstBackoffSeconds());
            try {
                $this->client()->get($this->panel(), 'panel/api/clients/get/x');

                return 'answered';
            } catch (PanelApiException $e) {
                return $e->failure->value;
            }
        });

        self::assertCount(1, $this->sent, 'the GET is not retried into the last 50 ms: '.$outcome);
    }

    public function testOutsideAPassNothingChanges(): void
    {
        $this->client()->get($this->panel(), 'panel/api/clients/get/x');

        self::assertSame([['GET', (float) ModernPanelHttpClient::MAX_DURATION_S, (float) ModernPanelHttpClient::CONNECT_TIMEOUT_S]], $this->sent);
        self::assertTrue($this->budget->canSend());
        self::assertNull($this->budget->deadline());
    }

    private function firstBackoffSeconds(): float
    {
        $delays = [];
        $client = new ModernPanelHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503])), new PanelHttpClientFactory(), $this->credentials(), sleeper: static function (int $ms) use (&$delays): void {
            $delays[] = $ms;
        });
        try {
            $client->get($this->panel(), 'panel/api/clients/get/x');
        } catch (PanelApiException) {
        }

        return ($delays[0] ?? 0) / 1000;
    }

    private function client(): ModernPanelHttpClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = [$method, (float) $options['max_duration'], (float) $options['timeout']];

            return array_shift($this->answers) ?? new MockResponse('{"success":true,"msg":"","obj":null}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        return new ModernPanelHttpClient($http, new PanelHttpClientFactory(), $this->credentials(), sleeper: function (int $ms): void {
            $this->clock->sleep($ms / 1000);
        }, budget: $this->budget);
    }

    private function credentials(): PanelCredentials
    {
        return new PanelCredentials(new SecretCipher('test-app-secret-for-panels'));
    }

    private function panel(): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('budget')->setBaseUrl('https://panel.example.test/secret/')->setPlainApiToken('tok-0123456789abcdef');
        $this->credentials()->encryptPending($panel);

        return $panel;
    }
}
