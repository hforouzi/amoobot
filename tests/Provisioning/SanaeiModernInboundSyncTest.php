<?php

declare(strict_types=1);

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\HttpResult;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernHttpClientInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Api\ModernInboundApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Api\ModernInboundApiResponse;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Mapper\ModernInboundMapper;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Mapper\ModernInboundMappingException;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function runSanaeiModernInboundSyncTest(): void
{
    $validInbound = [
        'id' => 12,
        'remark' => 'Germany 1',
        'protocol' => 'vless',
        'port' => 2053,
        'enable' => true,
        'expiryTime' => 0,
        'listen' => '',
        'tag' => 'inbound-12',
        'total' => 1024,
        'up' => 10,
        'down' => 20,
        'settings' => ['clients' => [['email' => 'client-a']]],
        'streamSettings' => '{"network":"tcp"}',
        'sniffing' => null,
        'clientStats' => [['email' => 'client-a']],
        'futureField' => 'kept',
    ];

    $client = new InboundFakeModernHttpClient(new HttpResult(true, 'ok', [$validInbound], '{"success":true}', 200, [], 'rid-list', true));
    $response = (new ModernInboundApi($client))->list(new VpnPanel());
    inboundAssertSame('/panel/api/inbounds/list', $client->lastPath, 'Modern inbound API must call the official list endpoint.');
    inboundAssertSame('GET', $client->lastMethod, 'Modern inbound API must use GET.');
    inboundAssertSame(null, $client->lastBody, 'Modern inbound API must not send a request body.');
    inboundAssertSame('rid-list', $response->requestId, 'Modern inbound API must retain request ID.');
    inboundAssertSame([$validInbound], $response->payload, 'Modern inbound API must return envelope obj payload.');

    inboundExpectException(
        InvalidResponseException::class,
        fn () => (new ModernInboundApi(new InboundFakeModernHttpClient(new HttpResult(false, 'invalid token secret-token', null, '{"success":false}', 200, [], 'rid-false', true))))->list(new VpnPanel()),
        'success=false inbound responses must fail safely.'
    );

    inboundExpectException(
        InvalidResponseException::class,
        fn () => (new ModernInboundApi(new InboundFakeModernHttpClient(new HttpResult(true, 'ok', ['obj' => []], '{}', 200, [], 'rid-plain', false))))->list(new VpnPanel()),
        'Missing Sanaei envelope must fail.'
    );

    inboundExpectException(
        InvalidResponseException::class,
        fn () => (new ModernInboundApi(new InboundFakeModernHttpClient(new HttpResult(true, 'ok', 'not-array', '{}', 200, [], 'rid-payload', true))))->list(new VpnPanel()),
        'Non-array obj payload must fail.'
    );

    $mapper = new ModernInboundMapper();
    $mapped = $mapper->mapList($response->payload);
    inboundAssertSame(1, count($mapped), 'Mapper must return one DTO.');
    inboundAssertSame(12, $mapped[0]->remoteId, 'Mapper must map id.');
    inboundAssertSame('Germany 1', $mapped[0]->remark, 'Mapper must map remark.');
    inboundAssertSame('vless', $mapped[0]->protocol, 'Mapper must map protocol.');
    inboundAssertSame(2053, $mapped[0]->port, 'Mapper must validate and map port.');
    inboundAssertSame(['network' => 'tcp'], $mapped[0]->streamSettings, 'Mapper must decode nested JSON settings.');
    inboundAssertSame([['email' => 'client-a']], $mapped[0]->clientStats, 'Mapper must keep clientStats raw arrays.');
    inboundAssertSame('kept', $mapped[0]->raw['futureField'] ?? null, 'Mapper must preserve unknown fields in raw payload.');

    inboundAssertSame([], $mapper->mapList([]), 'Mapper must accept an empty list.');

    inboundExpectException(ModernInboundMappingException::class, fn () => $mapper->mapList([['protocol' => 'vless']]), 'Missing id must fail.');
    inboundExpectException(ModernInboundMappingException::class, fn () => $mapper->mapList([['id' => 'bad', 'protocol' => 'vless']]), 'Invalid id must fail.');
    inboundExpectException(ModernInboundMappingException::class, fn () => $mapper->mapList([['id' => 1, 'protocol' => '']]), 'Invalid protocol must fail.');
    inboundExpectException(ModernInboundMappingException::class, fn () => $mapper->mapList([['id' => 1, 'protocol' => 'vless', 'port' => 70000]]), 'Invalid port must fail.');
    inboundExpectException(ModernInboundMappingException::class, fn () => $mapper->mapList([['id' => 1, 'protocol' => 'vless', 'settings' => '{bad']]), 'Malformed nested JSON must fail.');

    $optional = $mapper->mapList([['id' => 2, 'protocol' => 'trojan', 'port' => null, 'settings' => null, 'clientStats' => null]])[0];
    inboundAssertSame(0, $optional->port, 'Optional null port must normalize safely.');
    inboundAssertSame(null, $optional->settings, 'Optional null settings must remain null.');
    inboundAssertSame([], $optional->clientStats, 'Optional null clientStats must normalize to an empty raw list.');

    echo "Sanaei Modern inbound sync API and mapper tests passed.\n";
}

final class InboundFakeModernHttpClient implements ModernHttpClientInterface
{
    public ?string $lastMethod = null;
    public ?string $lastPath = null;
    public mixed $lastBody = 'not-called';

    public function __construct(private HttpResult $result)
    {
    }

    public function request(VpnPanel $panel, string $method, string $path, mixed $body = null, array $headers = []): HttpResult
    {
        $this->lastMethod = $method;
        $this->lastPath = $path;
        $this->lastBody = $body;

        return $this->result;
    }
}

/**
 * @template T
 *
 * @param T $expected
 * @param T $actual
 */
function inboundAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.inboundDescribe($expected).', got '.inboundDescribe($actual).'.');
    }
}

/**
 * @param class-string<Throwable> $class
 */
function inboundExpectException(string $class, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            if (str_contains($exception->getMessage(), 'secret-token')) {
                throw new RuntimeException($message.' Exception leaked a secret.');
            }

            return;
        }

        throw new RuntimeException($message.' Expected '.$class.', got '.$exception::class.'.');
    }

    throw new RuntimeException($message.' Expected '.$class.' to be thrown.');
}

function inboundDescribe(mixed $value): string
{
    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}

runSanaeiModernInboundSyncTest();
