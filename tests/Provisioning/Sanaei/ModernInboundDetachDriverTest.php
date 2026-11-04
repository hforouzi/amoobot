<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\InboundDetachResult;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\InboundDetachDriverInterface;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\DummyVpnPanelDriver;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelCallBudget;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2 of docs/design/plan-inbound-change.md (4.3 step 5, 4.4; tests "driver unit tests" and T5 of 8.2): the modern
 * driver's detachInbounds() against the fake 3x-ui (v3.11.0 detach semantics: skips, keeps traffic, allows zero
 * inbounds, so our guards are what is tested). Every outcome; the guards (never the last inbound, never the primary,
 * never an inbound not listed, never without a read first); idempotency; timeouts and partial answers (never a
 * success); the time limit of a pass (PanelCallBudget); nothing secret in the result or the logs.
 *
 * The service moved to inbound 2 (its primary B, as the remote id records it) and leaves inbound 1 (A).
 */
final class ModernInboundDetachDriverTest extends TestCase
{
    use Fake3xuiWiring;

    private const EMAIL = 'tg_123456_order_42';
    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const SUB_ID = 'sub0123456789abc';

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;
    private VpnInbound $old;
    private VpnInbound $primary;
    private VpnInbound $third;
    private string $remoteId;
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $this->driver = $this->driverWith($this->modernHttp());
        $this->old = $this->fakeInbound($this->panel, 1);
        $this->primary = $this->fakeInbound($this->panel, 2)->setProtocol('vmess')->setFlow(null);
        $this->third = $this->fakeInbound($this->panel, 3)->setProtocol('trojan')->setFlow(null);
        $this->remoteId = (new Sanaei3xuiRemoteIdParser())->format(null, null, '2', self::UUID, self::EMAIL, self::SUB_ID);
        (new ModernClientsApi($this->modernHttp()))->addClient($this->panel, ['email' => self::EMAIL, 'id' => self::UUID, 'subId' => self::SUB_ID, 'totalGB' => 5368709120, 'expiryTime' => 1893456000000, 'limitIp' => 2, 'enable' => true], [1, 2]);
        $this->fake->useTraffic(self::EMAIL, 987654321);
        $this->fake->requests = [];
    }

    public function testOnlyTheModernDriverCanDetach(): void
    {
        self::assertInstanceOf(InboundDetachDriverInterface::class, $this->driver);
        self::assertNotContains(InboundDetachDriverInterface::class, (array) class_implements(Sanaei3xuiDriver::class), 'legacy: report-only (4.5)');
        self::assertNotContains(InboundDetachDriverInterface::class, (array) class_implements(DummyVpnPanelDriver::class), 'dummy: not a real panel');
    }

    public function testDetachesAListedInboundReadsBackAndKeepsEverythingElse(): void
    {
        $record = $this->fake->clients[self::EMAIL];
        $traffic = $this->fake->traffic[self::EMAIL];

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::DONE, $result->outcome, (string) $result->message);
        self::assertTrue($result->succeeded());
        self::assertSame([1], $result->detached);
        self::assertSame([2], $result->inboundIdsAfter);
        self::assertSame([2], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL, 'POST panel/api/clients/'.self::EMAIL.'/detach', 'GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines(), 'a read, one detach, a read: at most three calls');
        self::assertSame(['inboundIds' => [1]], $this->fake->requests[1]['body']);
        self::assertSame(array_diff_key($record, ['inboundIds' => 1]), array_diff_key($this->fake->clients[self::EMAIL], ['inboundIds' => 1]), 'UUID, email, subId, expiry, totalGB, limitIp, enable unchanged');
        self::assertSame($traffic, $this->fake->traffic[self::EMAIL]);
    }

    public function testDetachingAgainSendsNothingAndIsASuccess(): void
    {
        self::assertSame(InboundDetachResult::DONE, $this->detach([$this->old])->outcome);
        $this->fake->requests = [];

        $again = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::ALREADY_DETACHED, $again->outcome);
        self::assertTrue($again->succeeded());
        self::assertSame([1], $again->alreadyDetached);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines(), 'a read only: no detach call');
    }

    public function testOnlyTheInboundsTheReadListsAreSent(): void
    {
        $this->fake->clients[self::EMAIL]['inboundIds'] = [1, 2];

        $result = $this->detach([$this->old, $this->third, $this->old]);

        self::assertSame(InboundDetachResult::DONE, $result->outcome, (string) $result->message);
        self::assertSame(['inboundIds' => [1]], $this->fake->requests[1]['body'], 'inbound 3 is not listed: never sent; repeats once');
        self::assertSame([1], $result->detached);
        self::assertSame([3], $result->alreadyDetached);
    }

    public function testT5ThePrimaryAsTheRemoteIdRecordsItIsNeverDetached(): void
    {
        $result = $this->detach([$this->old, $this->primary]);

        self::assertSame(InboundDetachResult::REFUSED, $result->outcome);
        self::assertSame(InboundDetachResult::REFUSED_PRIMARY, $result->reason);
        self::assertFalse($result->succeeded());
        self::assertSame([], $this->fake->requests, 'nothing sent, not even a read');
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testT5TheLocalPrimaryIsNeverDetachedEvenWhenTheRemoteIdNamesAnother(): void
    {
        // vpn_service.inbound and the remote id disagree (both are written together; fail closed if they ever do not).
        $result = $this->detach([$this->old], $this->old);

        self::assertSame(InboundDetachResult::REFUSED_PRIMARY, $result->reason);
        self::assertSame([], $this->fake->requests);

        // The same inbound as another entity (re-read): the same remote inbound of the same panel.
        $copy = $this->fakeInbound($this->panel, 1);
        self::assertSame(InboundDetachResult::REFUSED_PRIMARY, $this->detach([$copy], $this->old)->reason);
        self::assertSame([], $this->fake->requests);
    }

    public function testT5WithoutALocalPrimaryTheRemoteIdStillGuards(): void
    {
        // The primary deleted locally (vpn_service.inbound SET NULL, 4.4): the remote id still names remote inbound 2.
        self::assertSame(InboundDetachResult::REFUSED_PRIMARY, $this->detach([$this->primary], null)->reason);
        self::assertSame([], $this->fake->requests);
        self::assertSame(InboundDetachResult::DONE, $this->detach([$this->old], null)->outcome);
    }

    public function testT5TheLastInboundIsNeverDetached(): void
    {
        // A hand edit left the client on the old inbound only; detaching it would leave zero (3x-ui would allow it).
        $this->fake->clients[self::EMAIL]['inboundIds'] = [1];

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::REFUSED, $result->outcome);
        self::assertSame(InboundDetachResult::REFUSED_LAST_INBOUND, $result->reason);
        self::assertSame([1], $result->inboundIdsAfter);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines(), 'the read, no detach');
        self::assertSame([1], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testT5EveryInboundAtOnceIsTheLastInboundToo(): void
    {
        $this->fake->clients[self::EMAIL]['inboundIds'] = [1, 3];

        self::assertSame(InboundDetachResult::REFUSED_LAST_INBOUND, $this->detach([$this->old, $this->third])->reason);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
    }

    public function testT5AClientNotOnItsPrimaryFailsAndNothingIsSent(): void
    {
        // On the old inbound and another one, not on the primary 2: a hand edit. Never detach without the primary listed.
        $this->fake->clients[self::EMAIL]['inboundIds'] = [1, 3];

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED, $result->outcome);
        self::assertSame(InboundDetachResult::FAILED_NOT_ON_PRIMARY, $result->reason);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
        self::assertSame([1, 3], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testT5AClientOnNeitherTheOldNorThePrimaryFailsAndNothingIsSent(): void
    {
        $this->fake->clients[self::EMAIL]['inboundIds'] = [3];

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_NOT_ON_PRIMARY, $result->reason, 'not "already detached": the state is unexpected');
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
    }

    public function testT5APanelNotInModernModeIsRefusedWithoutARequest(): void
    {
        $legacy = $this->fakePanel()->setIntegrationMode(SanaeiIntegrationMode::LEGACY);

        $result = $this->driver->detachInbounds($this->remoteId, $this->primary, [$this->fakeInbound($legacy, 1)], $legacy);

        self::assertSame(InboundDetachResult::REFUSED_NOT_MODERN, $result->reason);
        self::assertSame(InboundAttachDriverInterface::MODERN_PANEL_REQUIRED, $result->message);
        self::assertSame(InboundDetachResult::REFUSED_NOT_MODERN, $this->driver->detachInbounds($this->remoteId, $this->primary, [$this->old], null)->reason, 'no panel');
        self::assertSame([], $this->fake->requests);
    }

    public function testT5AnInboundOfAnotherPanelIsRefusedWithoutARequest(): void
    {
        $other = $this->modernPanel();

        $result = $this->detach([$this->fakeInbound($other, 1)]);

        self::assertSame(InboundDetachResult::REFUSED_INVALID_INBOUND, $result->reason);
        self::assertSame([], $this->fake->requests);
    }

    public function testARemoteIdOfAnotherPanelIsRefusedWithoutARequest(): void
    {
        $this->setId($this->panel, 7);
        $elsewhere = (new Sanaei3xuiRemoteIdParser())->format(8, null, '2', self::UUID, self::EMAIL, self::SUB_ID);

        self::assertSame(InboundDetachResult::REFUSED_INVALID_SERVICE, $this->driver->detachInbounds($elsewhere, $this->primary, [$this->old], $this->panel)->reason);
        self::assertSame(InboundDetachResult::REFUSED_INVALID_SERVICE, $this->driver->detachInbounds('not-a-remote-id', $this->primary, [$this->old], $this->panel)->reason);
        $here = (new Sanaei3xuiRemoteIdParser())->format(7, null, '2', self::UUID, self::EMAIL, self::SUB_ID);
        self::assertSame([], $this->fake->requests);
        self::assertSame(InboundDetachResult::DONE, $this->driver->detachInbounds($here, $this->primary, [$this->old], $this->panel)->outcome, 'its own panel');
    }

    public function testNoInboundOrAnInvalidRemoteInboundIdIsRefusedWithoutARequest(): void
    {
        self::assertSame(InboundDetachResult::REFUSED_INVALID_INBOUND, $this->detach([])->reason);
        self::assertSame(InboundDetachResult::REFUSED_INVALID_INBOUND, $this->detach([$this->fakeInbound($this->panel, 1)->setRemoteInboundId('1; drop')])->reason);
        self::assertSame([], $this->fake->requests);
    }

    public function testAnotherClientUnderTheSameEmailIsRefused(): void
    {
        $this->fake->clients[self::EMAIL]['id'] = '00000000-1111-4222-8333-444444444444';

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::REFUSED_NOT_THIS_CLIENT, $result->reason);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testAMissingClientFailsAndNothingIsSent(): void
    {
        unset($this->fake->clients[self::EMAIL]);

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_CLIENT_MISSING, $result->reason);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
    }

    /**
     * @return iterable<string, array{\Closure(self): MockResponse, string}>
     */
    public static function partialReads(): iterable
    {
        yield 'no inbound list' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(true, array_diff_key($t->stored(), ['inboundIds' => 1])), InboundDetachResult::FAILED_NOT_LISTED];
        yield 'inbound list is text' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(true, ['inboundIds' => 'some, inbounds'] + $t->stored()), InboundDetachResult::FAILED_NOT_LISTED];
        yield 'inbound list is null' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(true, ['inboundIds' => null] + $t->stored()), InboundDetachResult::FAILED_NOT_LISTED];
        yield 'no UUID' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(true, array_diff_key($t->stored(), ['id' => 1])), InboundDetachResult::FAILED_NOT_LISTED];
        yield 'obj is text' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(true, 'ok'), InboundDetachResult::FAILED_UNREADABLE];
        yield 'another client' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(true, ['email' => 'tg_123456_order_43'] + $t->stored()), InboundDetachResult::FAILED_UNREADABLE];
        yield 'not the envelope' => [static fn (self $t): MockResponse => new MockResponse('{"clients":[]}', ['response_headers' => ['content-type' => 'application/json']]), InboundDetachResult::FAILED_UNREADABLE];
        yield 'cut short' => [static fn (self $t): MockResponse => new MockResponse('{"success":true,"obj":{"email":"tg_123456_order_42","inboundIds":[1,', ['response_headers' => ['content-type' => 'application/json']]), InboundDetachResult::FAILED_UNREADABLE];
        yield 'an HTML page' => [static fn (self $t): MockResponse => Fake3xuiPanel::html(), InboundDetachResult::FAILED_UNREADABLE];
        yield 'success false' => [static fn (self $t): MockResponse => Fake3xuiPanel::envelope(false, null, 'database is locked'), InboundDetachResult::FAILED_UNREADABLE];
    }

    /**
     * @param \Closure(self): MockResponse $answer
     */
    #[DataProvider('partialReads')]
    public function testAPartialOrGarbledFirstReadSendsNothing(\Closure $answer, string $reason): void
    {
        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, $answer($this));

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED, $result->outcome, (string) $result->message);
        self::assertSame($reason, $result->reason);
        self::assertStringContainsString('nothing sent', (string) $result->message);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines(), 'no detach without a complete read');
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    /**
     * @param \Closure(self): MockResponse $answer
     */
    #[DataProvider('partialReads')]
    public function testAPartialOrGarbledReadAfterTheDetachIsNeverASuccess(\Closure $answer, string $reason): void
    {
        // The panel applied the detach; only the read back is partial: not confirmed, not "done".
        $this->scriptSecondRead($answer($this));

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED, $result->outcome, (string) $result->message);
        self::assertSame(InboundDetachResult::FAILED_NOT_CONFIRMED, $result->reason);
        self::assertFalse($result->succeeded());
        self::assertSame([2], $this->fake->clients[self::EMAIL]['inboundIds'], 'applied on the panel, but not confirmed');
    }

    public function testATimeoutOnTheFirstReadSendsNothing(): void
    {
        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds with 0 bytes received'), 3);

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_UNREADABLE, $result->reason);
        self::assertStringContainsString('network_timeout', (string) $result->message);
        self::assertSame(array_fill(0, 3, 'GET panel/api/clients/get/'.self::EMAIL), $this->fake->requestLines(), 'a GET is retried, never followed by a detach');
    }

    public function testADetachWhoseAnswerIsLostIsConfirmedByTheRead(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds with 0 bytes received'), 1, true);

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::DONE, $result->outcome, (string) $result->message);
        self::assertSame([2], $result->inboundIdsAfter);
        self::assertSame(1, count(array_keys($this->fake->requestLines(), 'POST panel/api/clients/'.self::EMAIL.'/detach')), 'a POST is never retried');
        self::assertContains('sanaei_modern_detach_confirmed_after_lost_answer', array_column($this->logs, 'message'));
    }

    public function testATimedOutDetachThePanelDidNotApplyFails(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds with 0 bytes received'));

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_STILL_LISTED, $result->reason);
        self::assertStringContainsString('network_timeout', (string) $result->message);
        self::assertSame([1, 2], $result->inboundIdsAfter);
    }

    public function testASuccessAnswerWithoutTheEffectIsNotASuccess(): void
    {
        // success:true, but the panel did not remove it (a partial answer): the read decides.
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', Fake3xuiPanel::envelope(true));

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_STILL_LISTED, $result->reason);
        self::assertFalse($result->succeeded());
    }

    public function testAGarbledDetachAnswerIsDecidedByTheRead(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', new MockResponse('<html>gateway</html>', ['http_code' => 502]), 1, true);

        self::assertSame(InboundDetachResult::DONE, $this->detach([$this->old])->outcome, 'applied: confirmed by the read');

        $this->fake->clients[self::EMAIL]['inboundIds'] = [1, 2];
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', new MockResponse('{"success":tr', ['response_headers' => ['content-type' => 'application/json']]));
        self::assertSame(InboundDetachResult::FAILED_STILL_LISTED, $this->detach([$this->old])->reason, 'not applied: failed');
    }

    public function testAReadAfterTheDetachThatFailsIsNotConfirmed(): void
    {
        $this->scriptSecondRead(Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'));

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_NOT_CONFIRMED, $result->reason);
        self::assertStringContainsString('not confirmed', (string) $result->message);
    }

    public function testAReadAfterTheDetachWithoutThePrimaryIsNotASuccess(): void
    {
        $this->scriptSecondRead(Fake3xuiPanel::envelope(true, ['inboundIds' => [3]] + $this->stored()));

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_STILL_LISTED, $result->reason);
        self::assertStringContainsString('primary inbound 2', (string) $result->message);
    }

    public function testThePanelRefusingTheDetachFailsWithItsReasonAndNoReadIsNeeded(): void
    {
        // The old inbound was deleted on the panel while the client still lists it: v3.11.0 fails to load it and writes
        // nothing ("inbound N: ..."). Whether the inbound is gone is the caller's check (inbounds/list, 4.3 step 5).
        unset($this->fake->inbounds[1]);

        $result = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_PANEL, $result->reason);
        self::assertStringContainsString('inbound 1: record not found', (string) $result->message);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL, 'POST panel/api/clients/'.self::EMAIL.'/detach'], $this->fake->requestLines());
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testARejectedTokenOnTheDetachFails(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', Fake3xuiPanel::envelope(false, null, 'unauthorized', 401));

        self::assertSame(InboundDetachResult::FAILED_PANEL, $this->detach([$this->old])->reason);
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testATimeLimitUsedUpBeforeTheStartSendsNothing(): void
    {
        [$driver, $budget, $clock] = $this->driverWithBudget();

        $result = $budget->within(5, function () use ($driver, $clock): InboundDetachResult {
            $clock->sleep(5);

            return $driver->detachInbounds($this->remoteId, $this->primary, [$this->old], $this->panel);
        });

        self::assertSame(InboundDetachResult::FAILED_UNREADABLE, $result->reason);
        self::assertStringContainsString('time limit', (string) $result->message);
        self::assertSame([], $this->fake->requests, 'no request at all');
    }

    public function testATimeLimitUsedUpAfterTheReadSendsNoDetach(): void
    {
        [$driver, $budget, $clock] = $this->driverWithBudget();
        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, function () use ($clock): MockResponse {
            $clock->sleep(10); // the read takes the whole pass

            return Fake3xuiPanel::envelope(true, $this->stored());
        });

        $result = $budget->within(5, fn (): InboundDetachResult => $driver->detachInbounds($this->remoteId, $this->primary, [$this->old], $this->panel));

        self::assertSame(InboundDetachResult::FAILED, $result->outcome);
        self::assertSame(InboundDetachResult::FAILED_NOT_CONFIRMED, $result->reason);
        self::assertStringContainsString('POST not sent: the time limit of this pass is used up', (string) $result->message);
        self::assertSame(['GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines(), 'neither the detach nor the read back was sent');
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testWithoutRecordHealthAFailureIsNotCountedOnThePanel(): void
    {
        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 3);
        $this->driver->detachInbounds($this->remoteId, $this->primary, [$this->old], $this->panel, false);
        self::assertSame(0, $this->panel->getConsecutiveFailures());

        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 3);
        $this->detach([$this->old]);
        self::assertSame(1, $this->panel->getConsecutiveFailures(), 'counted by default, as every other call');
    }

    public function testNothingSecretReachesTheResultOrTheLogs(): void
    {
        // A panel that echoes everything back in its error text.
        $echo = sprintf('inbound 1: Bearer %s at %s/secret/panel/api/clients/%s/detach uuid=%s subId=%s vless://%s@panel.example.test:443?type=ws#x', Fake3xuiPanel::TOKEN, Fake3xuiPanel::ORIGIN, self::EMAIL, self::UUID, self::SUB_ID, self::UUID);
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/detach', Fake3xuiPanel::envelope(false, null, $echo));
        $this->fake->script('GET', null, Fake3xuiPanel::envelope(false, null, $echo), 1, false);

        $unreadable = $this->detach([$this->old]);
        $refused = $this->detach([$this->old]);

        self::assertSame(InboundDetachResult::FAILED_UNREADABLE, $unreadable->reason);
        self::assertSame(InboundDetachResult::FAILED_PANEL, $refused->reason);
        $everything = json_encode([$unreadable, $refused, $this->logs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ([Fake3xuiPanel::TOKEN, self::UUID, self::SUB_ID, '123456', '/secret/', 'vless://'.substr(self::UUID, 0, 8)] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
        foreach ($this->logs as $log) {
            self::assertSame(['panel_id', 'reason', 'failure'], array_keys($log['context']), 'ids and failure kinds only');
        }
    }

    /**
     * The client as the fake stores it (a GET answer's obj).
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        return $this->fake->clients[self::EMAIL];
    }

    /**
     * @param list<VpnInbound>       $inbounds
     * @param VpnInbound|null|false $localPrimary false: the service's primary (inbound 2)
     */
    private function detach(array $inbounds, VpnInbound|null|false $localPrimary = false): InboundDetachResult
    {
        return $this->driver->detachInbounds($this->remoteId, false === $localPrimary ? $this->primary : $localPrimary, $inbounds, $this->panel);
    }

    /**
     * The first GET answers as the panel; every later one (a transient error is retried up to 3 times) with $answer.
     * The detach itself is applied by the fake.
     */
    private function scriptSecondRead(MockResponse $answer): void
    {
        $reads = 0;
        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, function () use (&$reads, $answer): MockResponse {
            return 1 === ++$reads ? Fake3xuiPanel::envelope(true, $this->stored()) : clone $answer;
        }, 1 + ModernPanelHttpClient::MAX_ATTEMPTS);
    }

    private function driverWith(ModernPanelHttpClient $http): Sanaei3xuiModernDriver
    {
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

        return new Sanaei3xuiModernDriver(new ModernClientsApi($http), new Sanaei3xuiRemoteIdParser(), $logger);
    }

    /**
     * @return array{Sanaei3xuiModernDriver, PanelCallBudget, MockClock}
     */
    private function driverWithBudget(): array
    {
        $clock = new MockClock('2026-11-04 12:00:00');
        $budget = new PanelCallBudget($clock);
        $http = new ModernPanelHttpClient($this->fake->client(), new PanelHttpClientFactory(), $this->credentials(), new \Psr\Log\NullLogger(), function (int $ms): void {
            $this->sleeps[] = $ms;
        }, $budget);

        return [$this->driverWith($http), $budget, $clock];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
