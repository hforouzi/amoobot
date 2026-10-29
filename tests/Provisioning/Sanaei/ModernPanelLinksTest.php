<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\ShareLinkPolicy;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The panel's own links (Hosts phase H0, docs/design/multi-inbound-plans.md 9.1): the modern driver reads
 * clients/subLinks/{subId} only (links/{email} without a subId), keeps only acceptable share links, caps the answer's
 * size, never writes the subscription id into a log or an error, and reports the Hosts API from the answer alone.
 */
final class ModernPanelLinksTest extends TestCase
{
    use Fake3xuiWiring;

    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const EMAIL = 'tg_123456_order_42';
    private const SUB_ID = 'subSecret0123456';

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;
    /** @var AbstractLogger&object{all: string} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $this->logger = new class extends AbstractLogger {
            public string $all = '';

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->all .= $message.json_encode($context)."\n";
            }
        };
        $logger = $this->logger;
        $this->driver = new Sanaei3xuiModernDriver(new ModernClientsApi($this->modernHttp(null, $logger)), new Sanaei3xuiRemoteIdParser(), $logger);
    }

    public function testCreateStoresOneLinkPerUsableHostFromSubLinksOnly(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com'], ['remark' => 'CDN', 'security' => 'tls', 'sni' => 'front.example.com']);
        $this->fake->addHostGroup('off', [1], ['off.example.com'], ['isDisabled' => true]);
        $this->fake->addHostGroup('json-only', [1], ['json.example.com'], ['excludeFromSubTypes' => ['raw']]);

        $created = $this->driver->createService($this->request(), $this->panel);

        self::assertCount(2, $created->configLinks, 'one per address of the enabled raw host; disabled and raw-excluded hosts give none');
        self::assertStringContainsString('@cdn1.example.com:443?', $created->configLinks[0]);
        self::assertStringContainsString('@cdn2.example.com:443?', $created->configLinks[1]);
        self::assertSame($created->configLinks, array_values(array_unique($created->configLinks)), 'no duplicates');
        self::assertNotContains('GET panel/api/clients/links/'.self::EMAIL, $this->fake->requestLines(), 'links/{email} is not merged in');
        self::assertContains('GET panel/api/clients/subLinks/'.$created->subId, $this->fake->requestLines());
    }

    public function testLinksAndSubLinksAreNeverUnitedIntoDuplicates(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        // The panel picks a random SNI per request: links/{email} and subLinks would differ, their union duplicate.
        $calls = 0;
        $this->fake->script('GET', null, function () use (&$calls): MockResponse {
            ++$calls;

            return Fake3xuiPanel::envelope(true, ['vless://'.self::UUID.'@cdn.example.com:443?sni=pick'.$calls.'.example.com#a']);
        }, 5);

        $links = $this->driver->fetchLinks($this->panel, self::EMAIL, $this->fake->clients[self::EMAIL]['subId']);

        self::assertSame(['vless://'.self::UUID.'@cdn.example.com:443?sni=pick1.example.com#a'], $links);
        self::assertSame(1, $calls, 'one request');
    }

    public function testWithoutASubIdTheClientLinksAreUsed(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $this->fake->requests = [];

        $links = $this->driver->panelLinks($this->remoteId(null), null, $this->panel);

        self::assertSame('links', $links->source);
        self::assertNull($links->subscriptionUrl);
        self::assertCount(1, $links->links);
        self::assertSame(['GET panel/api/clients/links/'.self::EMAIL], $this->fake->requestLines());
    }

    public function testHostileItemsAreDroppedAndCounted(): void
    {
        $good = 'vless://'.self::UUID.'@cdn.example.com:443?type=ws&security=tls#CDN%20one';
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, Fake3xuiPanel::envelope(true, [
            $good,
            'javascript://alert(1)',
            'socks://127.0.0.1:1080#info',
            'http://evil.example.com/x',
            ['nested' => 'vless://'.self::UUID.'@x.example.com:1'],
            "vless://abc@host:1?a=1\n#x",
            "vless://abc@host:1?a=1\x00",
            'vless://abc@host:1?a=1 b',
            'vless://'.str_repeat('a', ShareLinkPolicy::MAX_LINK_LENGTH).'@h:1',
            12345,
            '',
            $good,
        ]));

        $links = $this->driver->panelLinks($this->remoteId(self::SUB_ID), self::SUB_ID, $this->panel);

        self::assertSame([$good], $links->links, 'only the acceptable link, once');
        self::assertSame(9, $links->rejected);
        self::assertSame('subLinks', $links->source);
        self::assertStringContainsString('sanaei_modern_links_rejected', $this->logger->all);
    }

    public function testAnswerThatIsNotAListGivesNoLinks(): void
    {
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, Fake3xuiPanel::envelope(true, ['a' => 'vless://x@h:1']));

        $links = $this->driver->panelLinks($this->remoteId(self::SUB_ID), self::SUB_ID, $this->panel);

        self::assertSame([], $links->links);
        self::assertSame(1, $links->rejected);
    }

    public function testAnOversizedAnswerFailsWithoutARetryAndWithoutTheSubId(): void
    {
        $big = (string) json_encode(['success' => true, 'msg' => '', 'obj' => array_fill(0, 20000, 'vless://'.self::UUID.'@cdn.example.com:443?type=tcp#'.str_repeat('x', 60))]);
        self::assertGreaterThan(ModernClientsApi::LINKS_MAX_BYTES, strlen($big));
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, new MockResponse($big, ['response_headers' => ['content-type' => 'application/json']]), 3);

        try {
            $this->driver->panelLinks($this->remoteId(self::SUB_ID), self::SUB_ID, $this->panel);
            self::fail('an oversized answer was accepted');
        } catch (PanelApiException $e) {
            self::assertSame(PanelFailure::UNEXPECTED_RESPONSE, $e->failure);
            self::assertStringContainsString('larger than', $e->getMessage());
            self::assertStringNotContainsString(self::SUB_ID, $e->getMessage());
        }
        self::assertCount(1, $this->fake->requests, 'not retried');
        self::assertStringNotContainsString(self::SUB_ID, $this->logger->all);
    }

    /**
     * @return iterable<string, array{MockResponse, PanelFailure}>
     */
    public static function failures(): iterable
    {
        yield '401' => [Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), PanelFailure::AUTH_REJECTED];
        yield '404 page' => [new MockResponse('404 page not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/plain']]), PanelFailure::WRONG_URL];
        yield 'timeout' => [Fake3xuiPanel::networkError('Operation timed out after 3001 milliseconds'), PanelFailure::NETWORK_TIMEOUT];
        yield 'idle timeout quoting the URL' => [new MockResponse([new \Symfony\Component\HttpClient\Exception\TransportException('Idle timeout reached for "'.Fake3xuiPanel::ORIGIN.'/secret/panel/api/clients/subLinks/'.self::SUB_ID.'".')]), PanelFailure::NETWORK_TIMEOUT];
        yield 'refused' => [Fake3xuiPanel::envelope(false, null, 'sub not found'), PanelFailure::PANEL_REJECTED];
    }

    /**
     * @dataProvider failures
     */
    public function testAFailedCallThrowsForTheSnapshotAndIsEmptyForCreation(MockResponse $response, PanelFailure $failure): void
    {
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, $response, 10);

        try {
            $this->driver->panelLinks($this->remoteId(self::SUB_ID), self::SUB_ID, $this->panel);
            self::fail('a failed call returned links');
        } catch (PanelApiException $e) {
            self::assertSame($failure, $e->failure);
            self::assertStringNotContainsString(self::SUB_ID, $e->getMessage());
            self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('/secret/', $e->getMessage(), 'the web base path is not shown');
        }
        self::assertSame([], $this->driver->fetchLinks($this->panel, self::EMAIL, self::SUB_ID), 'creation keeps working without links');
        $log = $this->logger->all;
        self::assertStringNotContainsString(self::SUB_ID, $log);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $log);
    }

    public function testRotationStoresTheSubscriptionLinksOfTheNewCredentials(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com']);
        $this->driver->createService($this->request(), $this->panel);
        $newUuid = '0a0a0a0a-1b1b-4c2c-8d3d-4e4e4e4e4e4e';

        $rotated = $this->driver->rotateCredentials($this->remoteId($this->fake->clients[self::EMAIL]['subId']), $newUuid, 'newsub123', $this->panel);

        self::assertCount(2, $rotated->configLinks);
        foreach ($rotated->configLinks as $link) {
            self::assertStringContainsString($newUuid, $link);
        }
        self::assertNotContains('GET panel/api/clients/links/'.self::EMAIL, $this->fake->requestLines());
    }

    public function testTheHostsApiIsDetectedFromTheAnswer(): void
    {
        $api = new ModernClientsApi($this->modernHttp());
        $this->fake->addHostGroup('cdn', [1, 2], ['cdn.example.com']);
        self::assertSame(['state' => 'yes', 'count' => 1, 'detail' => null], $api->hostsCapability($this->panel));
        self::assertCount(1, $api->hostsByInbound($this->panel, 2));
        self::assertSame([], $api->hostsByInbound($this->panel, 3));

        $this->fake->hostsApi = false;
        self::assertSame('no', $api->hostsCapability($this->panel)['state']);

        $this->fake->hostsApi = true;
        $this->fake->script('GET', 'panel/api/hosts/list', Fake3xuiPanel::envelope(false, null, 'forbidden', 403));
        self::assertSame('forbidden', $api->hostsCapability($this->panel)['state']);
    }

    private function request(): CreateVpnServiceRequest
    {
        return new CreateVpnServiceRequest(
            username: self::EMAIL,
            durationDays: 30,
            trafficLimitGb: 50,
            ipLimit: null,
            inbound: $this->fakeInbound($this->panel),
            remoteInboundId: '1',
            meta: ['orderId' => 42, 'telegramId' => '123456'],
            clientUuid: self::UUID,
        );
    }

    private function remoteId(?string $subId): string
    {
        return (new Sanaei3xuiRemoteIdParser())->format(null, null, '1', self::UUID, self::EMAIL, $subId);
    }
}
