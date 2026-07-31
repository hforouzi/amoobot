<?php

declare(strict_types=1);

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Auth\ModernPanelConfigurationFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection\ModernConnectionTestStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection\SanaeiModernConnectionTester;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthenticationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthorizationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidJsonException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\NetworkException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\PanelUnavailableException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\RateLimitException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\TimeoutException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\HttpResult;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernHttpClientInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\RetryPolicy;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function runSanaeiModernConnectionTesterTest(): void
{
    $configurationFactory = new ModernPanelConfigurationFactory(new PanelHttpClientFactory(), new RetryPolicy());

    $missingToken = modernPanel('https://panel.example.com', '');
    assertStatus(
        ModernConnectionTestStatus::MISSING_CONFIGURATION,
        tester(new FakeModernHttpClient(), $configurationFactory)->test($missingToken),
        'Modern panels with missing tokens must fail configuration validation.'
    );

$missingBaseUrl = modernPanel('', 'secret-token');
assertStatus(
    ModernConnectionTestStatus::MISSING_CONFIGURATION,
    tester(new FakeModernHttpClient(), $configurationFactory)->test($missingBaseUrl),
    'Modern panels with missing base URLs must fail configuration validation.'
);

$legacyPanel = modernPanel('https://panel.example.com', 'secret-token')->setSanaeiIntegrationMode(SanaeiIntegrationMode::LEGACY);
assertStatus(
    ModernConnectionTestStatus::MISSING_CONFIGURATION,
    tester(new FakeModernHttpClient(), $configurationFactory)->test($legacyPanel),
    'Legacy panels must not be accepted by the Modern tester.'
);

$successClient = new FakeModernHttpClient(new HttpResult(
    true,
    'ok',
    ['cpu' => 5, 'mem' => ['current' => 128], 'xray' => ['state' => 'running', 'version' => '1.8.24']],
    '{"success":true}',
    200,
    ['content-type' => ['application/json']],
    'rid-success',
    true
));
$success = tester($successClient, $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token'));
assertStatus(ModernConnectionTestStatus::SUCCESS, $success, 'Server status success envelope must pass.');
assertTrue($success->success, 'Successful result must mark success true.');
assertSame('/panel/api/server/status', $successClient->lastPath, 'Connection tester must call only the official server status endpoint.');
assertSame('GET', $successClient->lastMethod, 'Connection tester must use GET.');
assertSame(null, $successClient->lastBody, 'Connection tester must not send a request body.');
assertSame('rid-success', $success->requestId, 'Connection tester must retain request ID.');
assertTrue(null !== $success->responseTimeMs, 'Connection tester must retain response duration.');
assertSame('running', $success->xrayState, 'Xray state may be surfaced when genuinely available.');
assertSame('1.8.24', $success->xrayVersion, 'Xray version may be surfaced when genuinely available.');

assertStatus(
    ModernConnectionTestStatus::AUTHENTICATION_FAILED,
    tester(new FakeModernHttpClient(new AuthenticationFailedException('Bearer secret-token rejected', 401, 'rid-401')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    '401/authentication exceptions must be classified.'
);
assertSecretNotLeaked('secret-token', tester(new FakeModernHttpClient(new AuthenticationFailedException('Bearer secret-token rejected', 401, 'rid-401')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token'))->message, 'Authentication failure messages must redact tokens.');

assertStatus(
    ModernConnectionTestStatus::AUTHORIZATION_FAILED,
    tester(new FakeModernHttpClient(new AuthorizationFailedException('forbidden', 403, 'rid-403')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    '403/authorization exceptions must be classified.'
);
assertStatus(
    ModernConnectionTestStatus::TIMEOUT,
    tester(new FakeModernHttpClient(new TimeoutException('timed out', null, 'rid-timeout')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'Timeout exceptions must be classified.'
);
assertStatus(
    ModernConnectionTestStatus::UNAVAILABLE,
    tester(new FakeModernHttpClient(new NetworkException('Could not resolve host', null, 'rid-network')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'Network exceptions must be classified as unavailable.'
);
assertStatus(
    ModernConnectionTestStatus::UNAVAILABLE,
    tester(new FakeModernHttpClient(new PanelUnavailableException('down', 503, 'rid-503')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    '5xx/unavailable exceptions must be classified.'
);
assertStatus(
    ModernConnectionTestStatus::RATE_LIMITED,
    tester(new FakeModernHttpClient(new RateLimitException('rate limited', 429, 'rid-429')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    '429 exceptions must be classified.'
);
assertStatus(
    ModernConnectionTestStatus::INVALID_RESPONSE,
    tester(new FakeModernHttpClient(new InvalidJsonException('malformed JSON', 200, 'rid-json')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'Malformed JSON exceptions must be classified.'
);
assertStatus(
    ModernConnectionTestStatus::INVALID_RESPONSE,
    tester(new FakeModernHttpClient(new InvalidResponseException('bad response', 200, 'rid-response')), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'Malformed response exceptions must be classified.'
);
assertStatus(
    ModernConnectionTestStatus::INCOMPATIBLE_API,
    tester(new FakeModernHttpClient(new HttpResult(true, 'ok', ['items' => []], '{}', 200, [], 'rid-plain', false)), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'Missing Sanaei envelope must be classified as incompatible API.'
);
assertStatus(
    ModernConnectionTestStatus::INCOMPATIBLE_API,
    tester(new FakeModernHttpClient(new HttpResult(true, 'ok', ['items' => []], '{"success":true}', 200, [], 'rid-shape', true)), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'Envelope payload without server status fields must be classified as incompatible API.'
);
assertStatus(
    ModernConnectionTestStatus::INCOMPATIBLE_API,
    tester(new FakeModernHttpClient(new HttpResult(false, 'invalid token secret-token', null, '{"success":false}', 200, [], 'rid-false', true)), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token')),
    'success=false envelopes must be classified without raw response leakage.'
);
assertSecretNotLeaked('secret-token', tester(new FakeModernHttpClient(new HttpResult(false, 'invalid token secret-token', null, '{"success":false}', 200, [], 'rid-false', true)), $configurationFactory)->test(modernPanel('https://panel.example.com', 'secret-token'))->message, 'success=false messages must redact tokens.');

    echo "Sanaei Modern connection tester tests passed.\n";
}

function modernPanel(string $baseUrl, string $apiToken): VpnPanel
{
    return (new VpnPanel())
        ->setType('sanaei_3xui')
        ->setSanaeiIntegrationMode(SanaeiIntegrationMode::MODERN)
        ->setBaseUrl($baseUrl)
        ->setApiToken($apiToken)
        ->setUseProxy(false);
}

function tester(FakeModernHttpClient $client, ModernPanelConfigurationFactory $configurationFactory): SanaeiModernConnectionTester
{
    return new SanaeiModernConnectionTester($configurationFactory, $client);
}

final class FakeModernHttpClient implements ModernHttpClientInterface
{
    public ?string $lastMethod = null;
    public ?string $lastPath = null;
    public mixed $lastBody = 'not-called';

    public function __construct(private HttpResult|\Throwable|null $next = null)
    {
    }

    public function request(VpnPanel $panel, string $method, string $path, mixed $body = null, array $headers = []): HttpResult
    {
        $this->lastMethod = $method;
        $this->lastPath = $path;
        $this->lastBody = $body;

        if ($this->next instanceof \Throwable) {
            throw $this->next;
        }

        if ($this->next instanceof HttpResult) {
            return $this->next;
        }

        throw new \LogicException('No fake Modern HTTP result configured.');
    }
}

function assertStatus(ModernConnectionTestStatus $expected, object $actual, string $message): void
{
    if (!$actual instanceof App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection\ModernConnectionTestResult) {
        throw new RuntimeException($message.' Expected ModernConnectionTestResult.');
    }

    assertSame($expected, $actual->status, $message);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.describe($expected).', got '.describe($actual).'.');
    }
}

function assertTrue(bool $actual, string $message): void
{
    assertSame(true, $actual, $message);
}

function assertSecretNotLeaked(string $secret, string $text, string $message): void
{
    if (str_contains($text, $secret)) {
        throw new RuntimeException($message.' Found unexpected secret.');
    }
}

function describe(mixed $value): string
{
    if (is_object($value)) {
        return $value::class.'::'.($value instanceof UnitEnum ? $value->name : '');
    }

    return var_export($value, true);
}

runSanaeiModernConnectionTesterTest();
