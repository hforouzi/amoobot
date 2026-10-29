<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceErrorText;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * No panel error text names the subscription id or the panel's base path (F2): they are removed from the text itself,
 * whatever form the URL takes in it (default port dropped, upper-case host, user info, query string, encoded path), and
 * from the panel's own messages and redirect targets.
 */
final class PanelErrorRedactionTest extends TestCase
{
    use Fake3xuiWiring;

    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const SUB_ID = 'subSecret0123456';
    private const EMAIL = 'tg_redact_1';

    /** @var AbstractLogger&object{all: string} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->logger = new class extends AbstractLogger {
            public string $all = '';

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->all .= $message.json_encode($context)."\n";
            }
        };
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function answersQuotingTheRequest(): iterable
    {
        $tail = '/secret/panel/api/clients/subLinks/'.self::SUB_ID;
        yield 'transport, default port dropped' => [Fake3xuiPanel::networkError('Idle timeout reached for "https://panel.example.test'.$tail.'".')];
        yield 'transport, explicit :443' => [Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 443: refused for "https://panel.example.test:443'.$tail.'"')];
        yield 'transport, upper-case host' => [Fake3xuiPanel::networkError('Operation timed out for "https://PANEL.EXAMPLE.TEST:2053'.$tail.'"')];
        yield 'transport, user info' => [Fake3xuiPanel::networkError('Could not resolve host for "https://user:pw@panel.example.test:2053'.$tail.'"')];
        yield 'transport, query string' => [Fake3xuiPanel::networkError('SSL connect error for "https://panel.example.test:2053'.$tail.'?x=1&sub='.self::SUB_ID.'"')];
        yield 'transport, encoded, unquoted' => [Fake3xuiPanel::networkError('recv failure on /secret/panel/api/clients/subLinks/'.rawurlencode(self::SUB_ID).' (errno 104)')];
        yield 'panel refuses, quoting the subId' => [Fake3xuiPanel::envelope(false, null, 'no client with subId '.self::SUB_ID.' under /secret')];
        yield '404 envelope quoting the subId' => [Fake3xuiPanel::envelope(false, null, 'record '.self::SUB_ID.' not found', 404)];
        yield 'redirect to the base path' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://panel.example.test:2053/secret/login?next=/secret/sub/'.self::SUB_ID]])];
        yield 'redirect, relative' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/secret/login?sub='.self::SUB_ID]])];
    }

    #[DataProvider('answersQuotingTheRequest')]
    public function testNoErrorOrLogNamesTheSubIdOrTheBasePath(MockResponse $answer): void
    {
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, $answer, 5);
        $panel = $this->modernPanel();
        $driver = new Sanaei3xuiModernDriver(new ModernClientsApi($this->modernHttp(null, $this->logger)), new Sanaei3xuiRemoteIdParser(), $this->logger);

        try {
            $driver->panelLinks($this->remoteId($panel), self::SUB_ID, $panel);
            self::fail('the failure was not reported');
        } catch (PanelApiException $e) {
            $this->assertClean($e->getMessage());
            self::assertStringContainsString('subLinks/{subId}', $e->getMessage(), 'the label still says which call failed');
        }
        $this->assertClean($this->logger->all);
        self::assertSame([], $driver->fetchLinks($panel, self::EMAIL, self::SUB_ID));
        $this->assertClean($this->logger->all);
    }

    /** The real curl transport: http://127.0.0.1:80/... is quoted by Symfony without ":80". */
    public function testARealTransportErrorOnAnExplicitDefaultPortNamesNeither(): void
    {
        $socket = @fsockopen('127.0.0.1', 80, $errno, $errstr, 0.2);
        if (false !== $socket) {
            fclose($socket);
            self::markTestSkipped('something listens on 127.0.0.1:80');
        }
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('local')->setBaseUrl('http://127.0.0.1:80/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN);
        $http = new ModernPanelHttpClient(new CurlHttpClient(), new PanelHttpClientFactory(), $this->credentials(), $this->logger, static function (int $ms): void {
        });

        try {
            (new ModernClientsApi($http))->subLinks($panel, self::SUB_ID);
            self::fail('no server listens there');
        } catch (PanelApiException $e) {
            $this->assertClean($e->getMessage());
        }
        $this->assertClean($this->logger->all);
    }

    public function testAnErrorAboutAServiceLosesItsSubIdUuidAndBasePath(): void
    {
        $panel = $this->modernPanel();
        $service = (new VpnService())->setPanel($panel)->setSubId(self::SUB_ID)->setClientUuid(self::UUID)->setRemoteId($this->remoteId($panel));
        $dbError = 'An exception occurred while executing a query: UPDATE vpn_service SET config_links = ? WITH params ["[\"vless:\\/\\/'.self::UUID.'@h:1\"]", "'.self::SUB_ID.'"] at https://panel.example.test:2053/secret/x';

        $text = ServiceErrorText::redact($service, $dbError);

        $this->assertClean($text);
        self::assertStringNotContainsString(self::UUID, $text);
        self::assertStringNotContainsString(strtoupper(self::UUID), ServiceErrorText::redact($service, strtoupper(self::UUID)));
    }

    private function assertClean(string $text): void
    {
        self::assertStringNotContainsString(self::SUB_ID, $text);
        self::assertStringNotContainsString(rawurlencode(self::SUB_ID), $text);
        self::assertStringNotContainsString('secret', strtolower($text), 'the base path');
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $text);
    }

    private function remoteId(VpnPanel $panel): string
    {
        return (new Sanaei3xuiRemoteIdParser())->format($panel->getId(), 1, '1', self::UUID, self::EMAIL, self::SUB_ID);
    }
}
