<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\InboundMoveAttachResult;
use App\Provisioning\Domain\InboundMoveDriverInterface;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\DummyVpnPanelDriver;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P4, docs/design/plan-inbound-change.md 4.3 step 2: the modern driver's attachForMove() against the fake 3x-ui, and
 * the remote id rewrite of step 3 (Sanaei3xuiRemoteIdParser::withInbound()).
 *
 * The service is on inbound 1 (A, as its remote id records it) and moves to inbound 2 (B).
 */
final class ModernInboundMoveAttachDriverTest extends TestCase
{
    use Fake3xuiWiring;

    private const EMAIL = 'tg_123456_order_42';
    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const SUB_ID = 'sub0123456789abc';

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;
    private VpnInbound $target;
    private string $remoteId;
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $logger = new class(function (array $record): void { $this->logs[] = $record; }) extends AbstractLogger {
            /** @param \Closure(array{level: mixed, message: string, context: array<mixed>}): void $sink */
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->sink)(['level' => $level, 'message' => (string) $message, 'context' => $context]);
            }
        };
        $this->driver = new Sanaei3xuiModernDriver(new ModernClientsApi($this->modernHttp()), new Sanaei3xuiRemoteIdParser(), $logger);
        $this->target = $this->fakeInbound($this->panel, 2)->setProtocol('vmess')->setFlow(null);
        $this->remoteId = (new Sanaei3xuiRemoteIdParser())->format(null, null, '1', self::UUID, self::EMAIL, self::SUB_ID);
        (new ModernClientsApi($this->modernHttp()))->addClient($this->panel, ['email' => self::EMAIL, 'id' => self::UUID, 'subId' => self::SUB_ID, 'totalGB' => 5368709120, 'expiryTime' => 1893456000000, 'limitIp' => 2, 'enable' => true], [1]);
        $this->fake->useTraffic(self::EMAIL, 987654321);
        $this->fake->requests = [];
    }

    public function testOnlyTheModernDriverCanMove(): void
    {
        self::assertInstanceOf(InboundMoveDriverInterface::class, $this->driver);
        self::assertNotContains(InboundMoveDriverInterface::class, (array) class_implements(Sanaei3xuiDriver::class), 'legacy: report-only (4.5)');
        self::assertNotContains(InboundMoveDriverInterface::class, (array) class_implements(DummyVpnPanelDriver::class), 'dummy: not a real panel');
    }

    public function testAttachesTheTargetReadsBackAndKeepsEverythingElse(): void
    {
        $record = $this->fake->clients[self::EMAIL];
        $traffic = $this->fake->traffic[self::EMAIL];

        $result = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);

        self::assertSame(InboundMoveAttachResult::ATTACHED, $result->outcome, (string) $result->message);
        self::assertTrue($result->onTarget());
        self::assertSame([1, 2], $result->inboundIds);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL, 'POST panel/api/clients/'.self::EMAIL.'/attach', 'GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
        self::assertSame(['inboundIds' => [2]], $this->fake->requests[1]['body'], 'B only');
        self::assertSame(array_diff_key($record, ['inboundIds' => 1]), array_diff_key($this->fake->clients[self::EMAIL], ['inboundIds' => 1]));
        self::assertSame($traffic, $this->fake->traffic[self::EMAIL]);
    }

    public function testAlreadyOnTheTargetSendsNothing(): void
    {
        $this->fake->clients[self::EMAIL]['inboundIds'] = [1, 2];

        $result = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);

        self::assertSame(InboundMoveAttachResult::ALREADY_ON_TARGET, $result->outcome);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());

        // Also when a hand edit already took it off A: B is listed, which is all a switch needs.
        $this->fake->clients[self::EMAIL]['inboundIds'] = [2];
        self::assertSame(InboundMoveAttachResult::ALREADY_ON_TARGET, $this->driver->attachForMove($this->remoteId, $this->target, $this->panel)->outcome);
    }

    public function testAClientOnNeitherAnorBFailsAndNothingIsSent(): void
    {
        $this->fake->clients[self::EMAIL]['inboundIds'] = [3];

        $result = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);

        self::assertSame(InboundMoveAttachResult::FAILED, $result->outcome);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_ON_EXPECTED, $result->reason);
        self::assertStringContainsString('not on the expected inbound', (string) $result->message);
        self::assertFalse($result->panelUnreadable);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines(), 'the read only');
        self::assertSame([3], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    /**
     * P6 (4.3 step 5): after the switch the remote id names B; when B went missing on the panel, the retire re-attaches
     * B only while the client is still on A (expectedFrom), and sends nothing for a client on neither.
     */
    public function testTheRetireReattachExpectsTheOldInboundInsteadOfTheOneTheRemoteIdNames(): void
    {
        $onB = (new Sanaei3xuiRemoteIdParser())->format(null, null, '2', self::UUID, self::EMAIL, self::SUB_ID);

        // Without expectedFrom the client must be on the inbound the remote id records (B): it is not, nothing is sent.
        $result = $this->driver->attachForMove($onB, $this->target, $this->panel, false);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_ON_EXPECTED, $result->reason);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());

        // With A as the expected inbound: B is attached again, read back.
        $this->fake->requests = [];
        $result = $this->driver->attachForMove($onB, $this->target, $this->panel, false, 1);
        self::assertSame(InboundMoveAttachResult::ATTACHED, $result->outcome);
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL, 'POST panel/api/clients/'.self::EMAIL.'/attach', 'GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());

        // On neither A nor B: nothing sent.
        $this->fake->clients[self::EMAIL]['inboundIds'] = [3];
        $this->fake->requests = [];
        $result = $this->driver->attachForMove($onB, $this->target, $this->panel, false, 1);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_ON_EXPECTED, $result->reason);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
    }

    /** P6: whether an inbound still exists on the panel (inbounds/list): a GET only; unreadable is null, never "gone". */
    public function testPanelHasInboundReadsTheInboundListOnly(): void
    {
        self::assertTrue($this->driver->panelHasInbound($this->panel, 2, false));
        unset($this->fake->inbounds[2]);
        self::assertFalse($this->driver->panelHasInbound($this->panel, 2, false));
        self::assertSame(['GET panel/api/inbounds/list', 'GET panel/api/inbounds/list'], $this->fake->requestLines());

        $this->fake->script('GET', 'panel/api/inbounds/list', Fake3xuiPanel::networkError('Could not resolve host'), 10);
        self::assertNull($this->driver->panelHasInbound($this->panel, 2, false));
        $this->fake->clearScripts();
        $this->fake->script('GET', 'panel/api/inbounds/list', Fake3xuiPanel::envelope(true, ['not' => 'a list']));
        self::assertNull($this->driver->panelHasInbound($this->panel, 2, false), 'a garbled answer is not "gone"');
        self::assertNull($this->driver->panelHasInbound($this->fakePanel()->setIntegrationMode(SanaeiIntegrationMode::LEGACY), 2), 'not modern: not asked');
    }

    public function testFailuresBeforeTheAttachSendNothing(): void
    {
        $this->fake->clients[self::EMAIL]['id'] = '11111111-2222-3333-4444-555555555555';
        $other = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_THIS_CLIENT, $other->reason);

        unset($this->fake->clients[self::EMAIL]);
        self::assertSame(InboundMoveAttachResult::FAILED_CLIENT_MISSING, $this->driver->attachForMove($this->remoteId, $this->target, $this->panel)->reason);

        $this->fake->script('GET', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 10);
        $down = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);
        self::assertSame(InboundMoveAttachResult::FAILED_UNREADABLE, $down->reason);
        self::assertTrue($down->panelUnreadable, 'counted towards the automatic pause');
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST')), 'never a write without a read');
    }

    public function testARefusedAttachFailsAndAStillMissingTargetFails(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/attach', Fake3xuiPanel::envelope(false, null, 'inbound 2 not found'));
        $refused = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_ATTACHED, $refused->reason);
        self::assertCount(2, $this->fake->requests, 'a refused attach is not read back');

        $this->fake->requests = [];
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/attach', Fake3xuiPanel::envelope(true));
        $ignored = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_ATTACHED, $ignored->reason, 'success:true but B not listed: never trusted');
        self::assertSame([1], $ignored->inboundIds);
    }

    public function testALostAnswerIsReadBackAndAnUnreadableReadAfterIsNotConfirmed(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/attach', new MockResponse('garbled', ['http_code' => 502]), 1, true);
        $lost = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);
        self::assertSame(InboundMoveAttachResult::ATTACHED, $lost->outcome, 'applied, only the answer was lost: the read decides');

        $this->fake->clients[self::EMAIL]['inboundIds'] = [1];
        $this->fake->requests = [];
        // The attach is applied; every read after it fails.
        $fake = $this->fake;
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/attach', static function () use ($fake): MockResponse {
            $fake->script('GET', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 10);

            return Fake3xuiPanel::envelope(true);
        }, 1, true);
        $notConfirmed = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);
        self::assertSame(InboundMoveAttachResult::FAILED, $notConfirmed->outcome);
        self::assertSame(InboundMoveAttachResult::FAILED_NOT_CONFIRMED, $notConfirmed->reason, 'not known: the next pass reads first');
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testGuardsBeforeAnyRequest(): void
    {
        $legacy = $this->fakePanel()->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        self::assertSame(InboundMoveAttachResult::REFUSED_NOT_MODERN, $this->driver->attachForMove($this->remoteId, $this->target, $legacy)->reason);
        self::assertSame(InboundMoveAttachResult::REFUSED_INVALID_SERVICE, $this->driver->attachForMove('garbage', $this->target, $this->panel)->reason);
        $foreign = $this->fakeInbound($this->modernPanel(), 2);
        $this->setId($foreign->getPanel(), 77);
        $this->setId($this->panel, 1);
        self::assertSame(InboundMoveAttachResult::REFUSED_INVALID_TARGET, $this->driver->attachForMove($this->remoteId, $foreign, $this->panel)->reason);
        self::assertSame([], $this->fake->requests, 'nothing sent, not even a read');
    }

    public function testNothingSecretInTheResultOrTheLogs(): void
    {
        $this->fake->script('GET', null, Fake3xuiPanel::envelope(false, null, 'client '.self::EMAIL.' uuid '.self::UUID.' at https://panel.example.test:2053/secret/x', 500), 10);

        $result = $this->driver->attachForMove($this->remoteId, $this->target, $this->panel, false);

        $text = (string) $result->message.json_encode($this->logs);
        foreach ([self::EMAIL, self::UUID, self::SUB_ID, '/secret', Fake3xuiPanel::TOKEN, '123456'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testTheRemoteIdRewriteChangesTheInboundPartsOnly(): void
    {
        $parser = new Sanaei3xuiRemoteIdParser();
        $before = 'sanaei_3xui|panel=7|inbound=11|remoteInbound=1|uuid=7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f|email=tg_123456_order_42|subId=sub%2Babc';

        self::assertSame('sanaei_3xui|panel=7|inbound=12|remoteInbound=2|uuid=7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f|email=tg_123456_order_42|subId=sub%2Babc', $parser->withInbound($before, 12, '2'));
        // Unknown parts and their order are kept byte for byte.
        self::assertSame('sanaei_3xui|uuid=u|panel=7|x=y|inbound=12|email=e|remoteInbound=2', $parser->withInbound('sanaei_3xui|uuid=u|panel=7|x=y|inbound=3|email=e|remoteInbound=9', 12, '2'));
        self::assertNull($parser->withInbound('sanaei_3xui:1:uuid:email', 12, '2'), 'the old colon form cannot be rewritten');
        self::assertNull($parser->withInbound('1|uuid|email', 12, '2'));
        self::assertNull($parser->withInbound('sanaei_3xui|panel=7|remoteInbound=1|uuid=u|email=e|subId=s', 12, '2'), 'no inbound= part');
        self::assertNull($parser->withInbound($before, 12, ' '));
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
