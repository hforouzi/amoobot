<?php

declare(strict_types=1);

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection\SanaeiModernConnectionTester;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\ApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthenticationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthorizationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidJsonException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\NetworkException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\PanelUnavailableException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\RateLimitException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\TimeoutException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ExceptionMapper;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\HttpResult;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\JsonResponseParser;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernHttpClientInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\RetryPolicy;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SanaeiEnvelopeValidator;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SanaeiModernHttpRequestBuilder;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SanaeiModernHttpTransport;
use Psr\Log\AbstractLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Component\Serializer\Attribute\Ignore;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function runSanaeiModernHttpCoreTest(): void
{
    $requestBuilder = new SanaeiModernHttpRequestBuilder(new PanelHttpClientFactory());
    $panel = panel('https://panel.example.com', 'secret-token');

    assertSame('https://panel.example.com/panel/api/probe', $requestBuilder->buildUrl($panel, '/probe'), 'Base URL without slash must join with /panel/api.');
    assertSame('https://panel.example.com/panel/api/probe', $requestBuilder->buildUrl(panel('https://panel.example.com/', 'secret-token'), 'probe'), 'Base URL with slash must join with /panel/api.');
    assertSame('https://panel.example.com/panel/api/probe', $requestBuilder->buildUrl(panel('https://panel.example.com/panel', 'secret-token'), '/panel/api/probe'), 'Base URL ending /panel must not duplicate /panel.');
    assertSame('https://panel.example.com/base/panel/api/probe', $requestBuilder->buildUrl(panel('https://panel.example.com/base', 'secret-token'), '/probe'), 'Reverse-proxy base path must be preserved.');
    assertSame('https://panel.example.com/base/panel/api/probe', $requestBuilder->buildUrl(panel('https://panel.example.com/base/', 'secret-token'), 'api/probe'), '/api paths must be normalized under /panel.');

    $request = $requestBuilder->build($panel, 'POST', '/probe', ['enabled' => true]);
    assertSame('POST', $request->method, 'Request builder must normalize method.');
    assertSame('Bearer secret-token', $request->headers['Authorization'] ?? null, 'Bearer token must be injected from panel configuration.');
    assertSame('application/json', $request->headers['Content-Type'] ?? null, 'JSON content type must be set.');
    assertSame('{"enabled":true}', $request->encodedBody, 'Payload must be JSON encoded.');
    assertTrue(isset($request->headers['X-Request-ID']), 'Request ID header must be present.');
    assertStringNotContains('secret-token', (string) $request, 'Request string representation must not expose token.');

    assertThrows(InvalidJsonException::class, static fn () => $requestBuilder->build($panel, 'POST', '/probe', ['nan' => NAN]), 'Invalid JSON payloads must be rejected.');
    assertThrows(AuthenticationFailedException::class, static fn () => $requestBuilder->build(panel('https://panel.example.com', ''), 'GET', '/probe'), 'Missing token must fail with auth exception.');
    $tokenPanel = panel('https://panel.example.com', 'stored-token');
    $tokenPanel->setApiToken('');
    assertSame('stored-token', $tokenPanel->getApiToken(), 'Blank token form submissions must preserve the stored token.');
    $tokenPanel->setApiToken(' replacement-token ');
    assertSame('replacement-token', $tokenPanel->getApiToken(), 'Non-blank token form submissions must replace the stored token.');
    assertStringNotContains('replacement-token', $tokenPanel->getConfigJson(), 'API token must not be mirrored into generic JSON config.');
    $legacyConfigTokenPanel = panel('https://panel.example.com', '')->setConfig(['api_token' => 'legacy-config-token']);
    $legacyConfigTokenPanel->setApiToken('');
    assertSame('legacy-config-token', $legacyConfigTokenPanel->getConfig()['api_token'] ?? null, 'Blank token form submissions must not erase legacy-only config tokens.');
    assertSame(null, $legacyConfigTokenPanel->getApiToken(), 'Modern token resolution must not read legacy config api_token fallback.');
    assertTrue([] !== (new ReflectionMethod(VpnPanel::class, 'getApiToken'))->getAttributes(Ignore::class), 'API token getter must be ignored by normal entity serialization.');
    assertTrue([] !== (new ReflectionMethod(VpnPanel::class, 'getPassword'))->getAttributes(Ignore::class), 'Password getter must be ignored by normal entity serialization.');

    $jsonParser = new JsonResponseParser();
    $envelopeValidator = new SanaeiEnvelopeValidator();
    $jsonResponse = $jsonParser->parse(200, ['content-type' => ['application/json']], '{"success":true,"msg":"ok","obj":{"id":1}}', 'rid');
    $result = $envelopeValidator->toHttpResult($jsonResponse);
    assertTrue($result->success, 'Envelope success must be parsed.');
    assertTrue($result->enveloped, 'Envelope marker must be true for Sanaei envelope responses.');
    assertSame('ok', $result->message, 'Envelope message must be parsed.');
    assertSame(['id' => 1], $result->payload, 'Envelope obj must become payload.');

    $plainResult = $envelopeValidator->toHttpResult($jsonParser->parse(200, ['content-type' => ['application/json']], '{"items":[]}', 'rid'));
    assertFalse($plainResult->enveloped, 'Non-envelope JSON must remain supported when an envelope is not required.');
    assertThrows(InvalidResponseException::class, static fn () => $envelopeValidator->toHttpResult($jsonParser->parse(200, ['content-type' => ['application/json']], '{"items":[]}', 'rid'), true), 'Required envelope mode must reject non-envelope JSON.');
    assertThrows(InvalidResponseException::class, static fn () => $envelopeValidator->toHttpResult($jsonParser->parse(200, ['content-type' => ['application/json']], '{"success":"true","msg":"ok","obj":{}}', 'rid')), 'Envelope success must be boolean.');
    assertThrows(InvalidResponseException::class, static fn () => $envelopeValidator->toHttpResult($jsonParser->parse(200, ['content-type' => ['application/json']], '{"success":true,"msg":{"bad":true},"obj":{}}', 'rid')), 'Envelope msg must be scalar or null.');
    assertThrows(InvalidJsonException::class, static fn () => $jsonParser->parse(200, ['content-type' => ['application/json']], '{', 'rid'), 'Malformed JSON must fail.');
    assertThrows(InvalidResponseException::class, static fn () => $jsonParser->parse(200, ['content-type' => ['text/html']], '{"success":true}', 'rid'), 'Unexpected content type must fail.');
    assertTrue($jsonParser->parse(200, ['Content-Type' => ['application/vnd.api+json; charset=utf-8']], '{"success":true}', 'rid')->decoded['success'], 'Content-Type validation must be case-insensitive and accept +json media types.');

    $manualResult = new HttpResult(true, 'ok', ['x' => 1], '{"success":true}', 200, ['content-type' => ['application/json']], 'rid', true);
    assertSame('rid', $manualResult->requestId, 'HttpResult must retain request ID.');
    assertTrue($manualResult->enveloped, 'HttpResult must retain envelope presence.');

    $mapper = new ExceptionMapper();
    assertInstanceOf(AuthenticationFailedException::class, $mapper->mapStatus(401, 'rid'), '401 must map to authentication failure.');
    assertInstanceOf(AuthorizationFailedException::class, $mapper->mapStatus(403, 'rid'), '403 must map to authorization failure.');
    assertInstanceOf(RateLimitException::class, $mapper->mapStatus(429, 'rid'), '429 must map to rate limit.');
    assertInstanceOf(PanelUnavailableException::class, $mapper->mapStatus(503, 'rid'), '503 must map to panel unavailable.');
    assertInstanceOf(ApiException::class, $mapper->mapStatus(422, 'rid'), '422 must map to API exception.');
    assertInstanceOf(TimeoutException::class, $mapper->mapTransport(transportException('Idle timeout reached'), 'rid'), 'Timeout transport failures must map to timeout exception.');
    assertInstanceOf(NetworkException::class, $mapper->mapTransport(transportException('Could not resolve host'), 'rid'), 'DNS/network failures must map to network exception.');
    assertSame($mapper->mapStatus(401, 'rid')::class, $mapper->mapStatus(401, 'rid')::class, 'Exception mapper must be stateless for repeated mappings.');
    assertStringNotContains('secret-token', $mapper->mapStatus(400, 'rid', 'Bearer secret-token failed')->getMessage(), 'Mapped exceptions must redact Bearer tokens.');

    $retryPolicy = new RetryPolicy();
    assertSame(2, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')), 'Default retry semantics must be one retry after the initial request.');
    assertSame(1, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')->setConfig(['modern_retry_count' => 0])), 'modern_retry_count=0 must mean no retries.');
    assertSame(4, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')->setConfig(['modern_retry_count' => 3])), 'modern_retry_count must mean retries after the initial request.');
    assertSame(2, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')->setConfig(['retry_count' => 3])), 'Legacy/generic retry_count must not be read.');
    assertSame(2, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')->setConfig(['modern_retry_count' => 'bad'])), 'Invalid modern retry config must use default.');
    assertSame(2, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')->setConfig(['modern_retry_count' => -2])), 'Negative modern retry config must use the safe default.');
    assertSame(6, $retryPolicy->maxAttempts(panel('https://panel.example.com', 'secret-token')->setConfig(['modern_retry_count' => 99])), 'Excessive modern retry config must be clamped.');
    assertTrue($retryPolicy->shouldRetry(new TimeoutException('timeout'), 1, 2), 'Timeouts must be retryable.');
    assertTrue($retryPolicy->shouldRetry(new NetworkException('dns'), 1, 2), 'Network failures must be retryable.');
    assertTrue($retryPolicy->shouldRetry(new PanelUnavailableException('down', 503), 1, 2), 'HTTP 503 must be retryable.');
    assertFalse($retryPolicy->shouldRetry(new AuthenticationFailedException('bad token', 401), 1, 2), '401 must not be retried.');
    assertFalse($retryPolicy->shouldRetry(new AuthorizationFailedException('forbidden', 403), 1, 2), '403 must not be retried.');
    assertFalse($retryPolicy->shouldRetry(new ApiException('validation', 422), 1, 2), '422 must not be retried.');

    $logger = new FakeLogger();
    $httpClient = new FakeHttpClient([
        transportException('Idle timeout reached'),
        new FakeResponse(200, ['content-type' => ['application/json']], '{"success":true,"msg":"ok","obj":{"pong":true}}'),
    ]);
    $transport = new SanaeiModernHttpTransport($httpClient, $requestBuilder, $jsonParser, $envelopeValidator, $mapper, $retryPolicy, $logger);
    assertInstanceOf(ModernHttpClientInterface::class, $transport, 'Future modules must be able to depend on ModernHttpClientInterface.');
    $retryPanel = panel('https://panel.example.com', 'secret-token')->setConfig(['modern_retry_count' => 1]);
    $transportResult = $transport->request($retryPanel, 'GET', '/probe');
    assertSame(2, $httpClient->requestCount, 'Transport must retry a timeout once when configured.');
    assertSame(['pong' => true], $transportResult->payload, 'Transport must return parsed result after retry.');
    assertSame('Bearer secret-token', $httpClient->lastOptions['headers']['Authorization'] ?? null, 'Transport must send Bearer header.');
    assertStringNotContains('secret-token', var_export($logger->records, true), 'Logs must not expose Bearer token.');

    $forbiddenClient = new FakeHttpClient([
        new FakeResponse(403, ['content-type' => ['application/json']], '{"success":false,"msg":"forbidden","obj":null}'),
    ]);
    assertThrows(AuthorizationFailedException::class, static fn () => (new SanaeiModernHttpTransport($forbiddenClient, $requestBuilder, $jsonParser, $envelopeValidator, $mapper, $retryPolicy, new FakeLogger()))->request($retryPanel, 'GET', '/probe'), 'Transport must not retry 403 responses.');
    assertSame(1, $forbiddenClient->requestCount, '403 must stop after the first attempt.');

    $validationClient = new FakeHttpClient([
        new FakeResponse(422, ['content-type' => ['application/json']], '{"success":false,"msg":"invalid","obj":null}'),
    ]);
    assertThrows(ApiException::class, static fn () => (new SanaeiModernHttpTransport($validationClient, $requestBuilder, $jsonParser, $envelopeValidator, $mapper, $retryPolicy, new FakeLogger()))->request($retryPanel, 'GET', '/probe'), 'Transport must not retry 422 responses.');
    assertSame(1, $validationClient->requestCount, '422 must stop after the first attempt.');

    $unavailableClient = new FakeHttpClient([
        new FakeResponse(503, ['content-type' => ['application/json']], '{"success":false,"msg":"down","obj":null}'),
        new FakeResponse(200, ['content-type' => ['application/json']], '{"success":true,"msg":"ok","obj":{"recovered":true}}'),
    ]);
    $unavailableResult = (new SanaeiModernHttpTransport($unavailableClient, $requestBuilder, $jsonParser, $envelopeValidator, $mapper, $retryPolicy, new FakeLogger()))->request($retryPanel, 'GET', '/probe');
    assertSame(2, $unavailableClient->requestCount, '503 must be retried when configured.');
    assertSame(['recovered' => true], $unavailableResult->payload, 'Transport must return the recovered 503 retry response.');

    assertFalse(class_exists(App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\RequestFactory::class), 'Old RequestFactory must not be autoloadable.');
    assertFalse(class_exists(App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\HttpTransportInterface::class), 'Old duplicate transport interface must not be autoloadable.');
    assertTrue(class_exists(SanaeiModernConnectionTester::class), 'This phase must provide the concrete Sanaei Modern connection tester.');

    echo "Sanaei Modern HTTP core tests passed.\n";
}

function panel(string $baseUrl, string $apiToken): VpnPanel
{
    return (new VpnPanel())
        ->setType('sanaei_3xui')
        ->setBaseUrl($baseUrl)
        ->setApiToken($apiToken)
        ->setUseProxy(false);
}

final class FakeResponse implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $content,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        return $this->content;
    }

    public function toArray(bool $throw = true): array
    {
        return json_decode($this->content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}

final class FakeHttpClient implements HttpClientInterface
{
    public int $requestCount = 0;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    public function __construct(private array $queue)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        ++$this->requestCount;
        $this->lastOptions = $options;
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof ResponseInterface) {
            return $next;
        }

        throw new \RuntimeException('No fake response queued.');
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('Not used by this test.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class FakeLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
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

function assertFalse(bool $actual, string $message): void
{
    assertSame(false, $actual, $message);
}

function assertStringNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message.' Found unexpected string.');
    }
}

function assertInstanceOf(string $expectedClass, object $actual, string $message): void
{
    if (!$actual instanceof $expectedClass) {
        throw new RuntimeException($message.' Expected '.$expectedClass.', got '.$actual::class.'.');
    }
}

/**
 * @param callable(): mixed $callback
 */
function assertThrows(string $expectedClass, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException($message.' Expected '.$expectedClass.', got '.$throwable::class.'.');
    }

    throw new RuntimeException($message.' Expected '.$expectedClass.' but nothing was thrown.');
}

function describe(mixed $value): string
{
    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}

function transportException(string $message): TransportExceptionInterface
{
    return new class($message) extends \RuntimeException implements TransportExceptionInterface {
    };
}

runSanaeiModernHttpCoreTest();
