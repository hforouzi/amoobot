<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelEndpoint;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Shared\Infrastructure\SecretCipher;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Transport rules of the modern client: timeouts, no redirects, GET-only bounded retries, TLS verification and
 * pinning, the loopback/private-only http:// rule, URL normalisation, the token encrypted at rest and never shown,
 * and panels marked degraded after repeated failures.
 */
final class ModernTransportSecurityTest extends TestCase
{
    use Fake3xuiWiring;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
    }

    public function testRequestOptions(): void
    {
        $seen = $this->captureOptions();
        $this->modernHttp()->get($this->fakePanel(), 'panel/api/clients/list');

        self::assertSame(3.0, (float) $seen->options['timeout'], 'connect/idle timeout');
        self::assertSame(10.0, (float) $seen->options['max_duration'], 'total per request');
        self::assertSame(0, $seen->options['max_redirects']);
        self::assertTrue($seen->options['verify_peer']);
        self::assertTrue($seen->options['verify_host']);
        self::assertEmpty($seen->options['peer_fingerprint'] ?? null);
        self::assertNull($seen->options['proxy'] ?? null);
    }

    public function testSelfSignedPanelIsTrustedOnlyThroughItsPinnedKey(): void
    {
        $pin = base64_encode(hash('sha256', 'public key', true));
        $seen = $this->captureOptions();
        $this->modernHttp()->get($this->fakePanel()->setTlsPinSha256('sha256//'.$pin), 'panel/api/clients/list');

        self::assertSame(['pin-sha256' => [$pin]], $seen->options['peer_fingerprint']);
        self::assertFalse($seen->options['verify_peer']);
    }

    public function testAPinMismatchIsNamedAsSuch(): void
    {
        $this->fake->script(null, null, Fake3xuiPanel::networkError('SSL: public key does not match pinned public key'));
        $e = $this->failure(fn () => $this->modernHttp()->get($this->fakePanel()->setTlsPinSha256(base64_encode(hash('sha256', 'k', true))), 'panel/api/clients/list'));

        self::assertSame(PanelFailure::TLS_CERTIFICATE, $e->failure);
        self::assertStringContainsString('pinned public key', $e->getMessage());
    }

    public function testAMalformedPinIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fakePanel()->setTlsPinSha256('AA:BB:CC');
    }

    public function testThePerPanelProxyIsHonoured(): void
    {
        $seen = $this->captureOptions();
        $factory = new PanelHttpClientFactory('true', 'socks5', '10.9.8.7', '1080');
        $this->modernHttp($factory)->get($this->fakePanel()->setUseProxy(true), 'panel/api/clients/list');

        self::assertSame('socks5://10.9.8.7:1080', $seen->options['proxy']);
    }

    public function testAProxyEnabledWithoutAProxyConfiguredIsNamed(): void
    {
        $e = $this->failure(fn () => $this->modernHttp()->get($this->fakePanel()->setUseProxy(true), 'panel/api/clients/list'));

        self::assertSame(PanelFailure::NOT_CONFIGURED, $e->failure);
        self::assertSame([], $this->fake->requests);
    }

    public function testTransientGetFailuresAreRetriedWithBackoff(): void
    {
        $this->fake->script('GET', 'panel/api/clients/list', Fake3xuiPanel::networkError('Failed to connect: Connection refused'), 2);
        $response = $this->modernHttp()->get($this->fakePanel(), 'panel/api/clients/list');

        self::assertSame([], $response->obj);
        self::assertCount(3, $this->fake->requests);
        self::assertSame([250, 500], $this->sleeps);
    }

    public function testRetriesAreBounded(): void
    {
        $this->fake->script(null, null, new MockResponse('', ['http_code' => 503]), 10);
        $e = $this->failure(fn () => $this->modernHttp()->get($this->fakePanel(), 'panel/api/clients/list'));

        self::assertSame(PanelFailure::HTTP_ERROR, $e->failure);
        self::assertCount(3, $this->fake->requests);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function notRetried(): iterable
    {
        yield 'DNS' => [Fake3xuiPanel::networkError('Could not resolve host: x')];
        yield 'TLS' => [Fake3xuiPanel::networkError('SSL certificate problem: unable to get local issuer certificate')];
        yield '401' => [Fake3xuiPanel::envelope(false, null, 'unauthorized', 401)];
        yield '404' => [new MockResponse('404 page not found', ['http_code' => 404])];
        yield 'success:false' => [Fake3xuiPanel::envelope(false, null, 'no')];
        yield '500' => [new MockResponse('', ['http_code' => 500])];
    }

    #[DataProvider('notRetried')]
    public function testPermanentFailuresAreNotRetried(MockResponse $response): void
    {
        $this->fake->script(null, null, $response, 5);
        $this->failure(fn () => $this->modernHttp()->get($this->fakePanel(), 'panel/api/clients/list'));

        self::assertCount(1, $this->fake->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testAPostIsNeverRetriedEvenOnATimeout(): void
    {
        $this->fake->script('POST', 'panel/api/clients/resetTraffic/a@b', Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds'), 5);
        $e = $this->failure(fn () => $this->modernHttp()->post($this->fakePanel(), 'panel/api/clients/resetTraffic/a@b'));

        self::assertSame(PanelFailure::NETWORK_TIMEOUT, $e->failure);
        self::assertCount(1, $this->fake->requests);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function plainHttpUrls(): iterable
    {
        yield 'loopback v4' => ['http://127.0.0.1:2096/', true];
        yield 'loopback v6' => ['http://[::1]:2096/xui', true];
        yield 'localhost' => ['http://localhost:2096', true];
        yield 'private 10/8' => ['http://10.0.0.5:2053/', true];
        yield 'private 192.168/16' => ['http://192.168.1.10:2053/', true];
        yield 'private 172.16/12' => ['http://172.20.0.2:2053/', true];
        yield 'ULA v6' => ['http://[fd12:3456::1]:2053/', true];
        yield 'public IP' => ['http://203.0.113.7:2053/', false];
        yield 'public v6' => ['http://[2606:4700:4700::1111]:2053/', false];
        yield 'host name' => ['http://panel.example.com:2053/', false];
        yield 'host name resolving privately' => ['http://panel.internal:2053/', false];
    }

    #[DataProvider('plainHttpUrls')]
    public function testPlainHttpOnlyToLoopbackOrPrivateAddresses(string $url, bool $allowed): void
    {
        $this->fake->origin = rtrim((string) preg_replace('#^(https?://[^/]+).*$#', '$1', $url), '/');
        $this->fake->basePath = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $panel = $this->fakePanel()->setBaseUrl($url);

        if ($allowed) {
            $this->modernHttp()->get($panel, 'panel/api/clients/list');
            self::assertCount(1, $this->fake->requests);

            return;
        }
        $e = $this->failure(fn () => $this->modernHttp()->get($panel, 'panel/api/clients/list'));
        self::assertSame(PanelFailure::NOT_CONFIGURED, $e->failure);
        self::assertStringContainsString('clear text', $e->getMessage());
        self::assertSame([], $this->fake->requests, 'the token is never sent');
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'plain' => ['https://panel.example.com:2053', null, 'https://panel.example.com:2053/'];
        yield 'trailing slash' => ['https://panel.example.com:2053/', null, 'https://panel.example.com:2053/'];
        yield 'web base path' => ['https://panel.example.com:2053/secret', null, 'https://panel.example.com:2053/secret/'];
        yield 'web base path, slashes' => ['https://panel.example.com:2053//secret//', null, 'https://panel.example.com:2053/secret/'];
        yield 'address bar URL' => ['https://panel.example.com:2053/secret/panel/inbounds', null, 'https://panel.example.com:2053/secret/'];
        yield 'panel root URL' => ['https://panel.example.com:2053/secret/panel/', null, 'https://panel.example.com:2053/secret/'];
        yield 'old separate base path' => ['https://panel.example.com:2053', '/xui', 'https://panel.example.com:2053/xui/'];
        yield 'base path given twice' => ['https://panel.example.com:2053/xui', 'xui', 'https://panel.example.com:2053/xui/'];
        yield 'IPv6 with port' => ['https://[2001:db8::10]:2053/abc', null, 'https://[2001:db8::10]:2053/abc/'];
        yield 'default port' => ['HTTPS://Panel.Example.com/', null, 'https://panel.example.com/'];
    }

    #[DataProvider('baseUrls')]
    public function testBaseUrlNormalisation(string $url, ?string $basePath, string $root): void
    {
        $endpoint = PanelEndpoint::fromParts($url, $basePath);

        self::assertSame($root, $endpoint->root());
        self::assertSame($root.'panel/api/clients/get/a%40b', $endpoint->url('panel/api/clients/get/'.rawurlencode('a@b')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBaseUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['panel.example.com:2053'];
        yield 'ftp' => ['ftp://panel.example.com'];
        yield 'credentials' => ['https://admin:pw@panel.example.com'];
        yield 'query' => ['https://panel.example.com/?token=x'];
    }

    #[DataProvider('badBaseUrls')]
    public function testUnusableBaseUrlsAreRefused(string $url): void
    {
        $e = $this->failure(static fn () => PanelEndpoint::fromParts($url));

        self::assertSame(PanelFailure::NOT_CONFIGURED, $e->failure);
        self::assertStringNotContainsString('pw', $e->getMessage());
    }

    public function testTheTokenIsStoredEncrypted(): void
    {
        $panel = $this->fakePanel();
        $stored = (string) $panel->getStoredApiToken();

        self::assertStringStartsWith(SecretCipher::PREFIX, $stored);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $stored);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, (string) json_encode($panel->getConfig()));
        self::assertNull($panel->getPlainApiToken());
        self::assertSame(Fake3xuiPanel::TOKEN, $this->credentials()->apiToken($panel));
    }

    public function testTokensStoredInClearTextByOlderVersionsAreReadThenEncrypted(): void
    {
        $panel = $this->fakePanel(withToken: false)->setConfig(['api_token' => Fake3xuiPanel::TOKEN, 'base_path' => null]);
        self::assertSame(Fake3xuiPanel::TOKEN, $this->credentials()->apiToken($panel));

        $this->credentials()->encryptPending($panel);

        self::assertStringStartsWith(SecretCipher::PREFIX, (string) $panel->getStoredApiToken());
        self::assertArrayNotHasKey('api_token', (array) $panel->getConfig());
        self::assertSame(Fake3xuiPanel::TOKEN, $this->credentials()->apiToken($panel));
    }

    public function testAnEmptyFormFieldKeepsTheStoredToken(): void
    {
        $panel = $this->fakePanel();
        $stored = $panel->getStoredApiToken();
        $panel->setPlainApiToken('');
        $this->credentials()->encryptPending($panel);

        self::assertSame($stored, $panel->getStoredApiToken());
    }

    public function testAChangedAppSecretFailsWithoutRevealingAnything(): void
    {
        $stored = (string) $this->fakePanel()->getStoredApiToken();
        try {
            (new SecretCipher('another-secret'))->decrypt($stored);
            self::fail('decrypted with the wrong key');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('APP_SECRET', $e->getMessage());
            self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $e->getMessage());
        }
    }

    public function testTheTokenNeverAppearsInExceptionsOrLogs(): void
    {
        $token = Fake3xuiPanel::TOKEN;
        $logger = new class extends AbstractLogger {
            public string $all = '';

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->all .= $message.json_encode($context)."\n";
            }
        };
        $responses = [
            Fake3xuiPanel::networkError('Failed to connect to https://panel.example.test:2053 with header Authorization: Bearer '.$token),
            Fake3xuiPanel::envelope(false, null, 'token '.$token.' is not allowed'),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/login?token='.$token]]),
        ];
        foreach ($responses as $response) {
            $this->fake->script(null, null, $response, 3);
            $e = $this->failure(fn () => $this->modernHttp(logger: $logger)->get($this->fakePanel(), 'panel/api/clients/list'));
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringNotContainsString($token, (string) $e->panelMessage);
            self::assertStringNotContainsString($token, (string) $e);
            // Arguments of the application's own frames (#[\SensitiveParameter] hides the token and the headers).
            foreach ($e->getTrace() as $frame) {
                if (str_starts_with((string) ($frame['class'] ?? ''), 'App\\Provisioning\\')) {
                    self::assertStringNotContainsString($token, print_r($frame['args'] ?? [], true), (string) $frame['function']);
                }
            }
        }
        self::assertStringNotContainsString($token, $logger->all);
        self::assertNotSame('', $logger->all);
    }

    public function testRepeatedFailuresMarkThePanelDegradedAndHideItsPlans(): void
    {
        $panel = $this->fakePanel();
        $plan = (new Plan())->setTitle('p')->setPrice(1)->setDurationDays(30)->setInbound((new VpnInbound())->setPanel($panel)->setRemoteInboundId('1'));
        self::assertTrue($plan->isAvailableForSale());

        $this->fake->script(null, null, Fake3xuiPanel::networkError('Could not resolve host: x'), 2);
        $this->failure(fn () => $this->modernHttp()->get($panel, 'panel/api/clients/list'));
        $this->failure(fn () => $this->modernHttp()->get($panel, 'panel/api/clients/list'));
        self::assertFalse($panel->isDegraded());

        $this->fake->script(null, null, Fake3xuiPanel::envelope(false, null, 'duplicate email'), 5);
        for ($i = 0; $i < 5; ++$i) {
            $this->failure(fn () => $this->modernHttp()->post($panel, 'panel/api/clients/add', []));
        }
        self::assertFalse($panel->isDegraded(), 'the panel refusing a request is not a panel failure');

        $this->fake->script(null, null, Fake3xuiPanel::envelope(false, null, 'unauthorized', 401));
        $this->failure(fn () => $this->modernHttp()->get($panel, 'panel/api/clients/list'));
        self::assertTrue($panel->isDegraded());
        self::assertFalse($plan->isAvailableForSale());

        $this->modernHttp()->get($panel, 'panel/api/clients/list');
        self::assertFalse($panel->isDegraded(), 'one success clears it');
        self::assertSame(0, $panel->getConsecutiveFailures());
        self::assertTrue($plan->isAvailableForSale());
    }

    private function failure(\Closure $call): PanelApiException
    {
        try {
            $call();
        } catch (PanelApiException $e) {
            return $e;
        }
        self::fail('expected a PanelApiException');
    }

    private function captureOptions(): \stdClass
    {
        $seen = new \stdClass();
        $seen->options = [];
        $this->fake->script(null, null, static function (string $method, string $url, array $options) use ($seen): MockResponse {
            $seen->options = $options;

            return Fake3xuiPanel::envelope(true, []);
        });

        return $seen;
    }
}
