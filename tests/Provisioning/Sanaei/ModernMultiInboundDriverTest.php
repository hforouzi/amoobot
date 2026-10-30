<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\InboundAttachment;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiConfigGenerator;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;

/**
 * The modern driver with a plan's additional inbounds (docs/design/multi-inbound-plans.md 3.2, phase M2a): one add with
 * every id, attach-missing with only the missing ids (one call; one by one when the panel refuses the batch), a GET
 * again, failures reported per inbound (D2) and the primary still required. Adoption attaches only for a UUID match.
 * Every other operation stays one request by email.
 */
final class ModernMultiInboundDriverTest extends TestCase
{
    use Fake3xuiWiring;

    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const EMAIL = 'tg_123456_order_42';

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;
    private VpnInbound $primary;
    private VpnInbound $vmess;
    private VpnInbound $trojan;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->panel = $this->modernPanel();
        $this->driver = $this->modernDriver();
        $this->primary = $this->fakeInbound($this->panel, 1);
        $this->vmess = $this->fakeInbound($this->panel, 2)->setProtocol('vmess')->setFlow(null);
        $this->trojan = $this->fakeInbound($this->panel, $trojanId)->setProtocol('trojan')->setFlow(null);
    }

    public function testOneAddCarriesEveryInboundAndNothingIsAttachedAfterwards(): void
    {
        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);

        self::assertSame([1, 2, 4], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame([1, 2, 4], $this->fake->requests[0]['body']['inboundIds'], 'primary first, one add');
        self::assertSame(['POST panel/api/clients/add', 'GET panel/api/clients/get/'.self::EMAIL, 'GET panel/api/clients/subLinks/'.$created->subId], $this->fake->requestLines());
        self::assertSame([[true, null], [true, null]], self::states($created->attachments));
        self::assertSame([$this->vmess, $this->trojan], array_map(static fn (InboundAttachment $a): VpnInbound => $a->inbound, $created->attachments));
        self::assertCount(3, $created->configLinks ?? [], 'the subscription lists every attached inbound');
    }

    public function testWithoutAdditionalInboundsTheRequestsAreTheSingleInboundOnes(): void
    {
        $created = $this->driver->createService($this->request([]), $this->panel);

        self::assertSame([1], $this->fake->requests[0]['body']['inboundIds']);
        self::assertSame(['POST panel/api/clients/add', 'GET panel/api/clients/get/'.self::EMAIL, 'GET panel/api/clients/subLinks/'.$created->subId], $this->fake->requestLines());
        self::assertSame([], $created->attachments);
    }

    public function testAPanelThatDropsAnInboundGetsOnlyThatOneAttached(): void
    {
        // The add is applied without inbound 4 (as a panel might), so the GET shows it missing.
        $this->fake->script('POST', 'panel/api/clients/add', function (string $method, string $url, array $options): \Symfony\Component\HttpClient\Response\MockResponse {
            $body = json_decode((string) $options['body'], true);
            $client = $body['client'] + ['inboundIds' => [1, 2], 'dbId' => 1];
            $this->fake->clients[$client['email']] = array_merge(['subId' => 'sub42', 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0], $client, ['inboundIds' => [1, 2]]);

            return Fake3xuiPanel::envelope(true, null, 'Client added');
        });

        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);

        $attach = array_values(array_filter($this->fake->requests, static fn (array $r): bool => str_ends_with($r['path'], '/attach')));
        self::assertCount(1, $attach);
        self::assertSame(['inboundIds' => [4]], $attach[0]['body'], 'only the missing id');
        self::assertSame([1, 2, 4], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame([[true, null], [true, null]], self::states($created->attachments));
        self::assertSame(2, count(array_keys($this->fake->requestLines(), 'GET panel/api/clients/get/'.self::EMAIL)), 'GET, attach, GET again');
    }

    public function testOneInboundThePanelRefusesFailsAloneAndTheServiceIsStillCreated(): void
    {
        // Inbound 9 was deleted on the panel: the add with every id is refused, the primary alone is added, the others
        // one by one after the batch attach is refused.
        $gone = $this->fakeInbound($this->panel, 9)->setProtocol('vmess');
        $created = $this->driver->createService($this->request([$this->vmess, $gone, $this->trojan]), $this->panel);

        self::assertSame([1, 2, 4], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame([true, false, true], array_map(static fn (InboundAttachment $a): bool => $a->attached, $created->attachments));
        self::assertStringContainsString('inbound 9 not found', (string) $created->attachments[1]->error);
        self::assertSame(1, count($this->fake->clients), 'one client');
        $adds = array_values(array_filter($this->fake->requests, static fn (array $r): bool => 'panel/api/clients/add' === $r['path']));
        self::assertSame([[1, 2, 9, 4], [1]], array_map(static fn (array $r): array => $r['body']['inboundIds'], $adds));
        $attaches = array_values(array_map(static fn (array $r): array => $r['body']['inboundIds'], array_filter($this->fake->requests, static fn (array $r): bool => str_ends_with($r['path'], '/attach'))));
        self::assertSame([[2, 9, 4], [2], [9], [4]], $attaches);
    }

    public function testEverySecondaryFailingStillDeliversThePrimary(): void
    {
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/attach', Fake3xuiPanel::envelope(false, null, 'database is locked'), 5);
        $this->fake->script('POST', 'panel/api/clients/add', function (string $method, string $url, array $options): \Symfony\Component\HttpClient\Response\MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[self::EMAIL] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });

        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);

        self::assertSame([1], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame([false, false], array_map(static fn (InboundAttachment $a): bool => $a->attached, $created->attachments));
        self::assertStringContainsString('database is locked', (string) $created->attachments[0]->error);
        self::assertSame(self::UUID, $created->clientUuid);
    }

    public function testThePrimaryMissingAfterTheAttachIsAnError(): void
    {
        $this->fake->script('POST', 'panel/api/clients/add', function (string $method, string $url, array $options): \Symfony\Component\HttpClient\Response\MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[self::EMAIL] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [2]]);

            return Fake3xuiPanel::envelope(true);
        });
        $this->fake->script('POST', 'panel/api/clients/'.self::EMAIL.'/attach', Fake3xuiPanel::envelope(false, null, 'inbound locked'), 5);

        try {
            $this->driver->createService($this->request([$this->vmess]), $this->panel);
            self::fail('a client without the primary was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("plan's inbound 1", $e->getMessage());
        }
    }

    public function testALostAddIsAdoptedByUuidAndTheMissingInboundsAttached(): void
    {
        // The add was applied with the primary only and its answer lost.
        $this->fake->script('POST', 'panel/api/clients/add', function (string $method, string $url, array $options): \Symfony\Component\HttpClient\Response\MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[self::EMAIL] = array_merge(['subId' => 'sub42', 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds');
        });

        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);

        self::assertSame(self::UUID, $created->clientUuid);
        self::assertSame([1, 2, 4], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame(1, count(array_keys($this->fake->requestLines(), 'POST panel/api/clients/add')), 'the add is never sent twice');
        self::assertSame([[true, null], [true, null]], self::states($created->attachments));
    }

    public function testARetriedCreateAttachesOnlyWhatIsMissing(): void
    {
        $this->seed([1, 2]);

        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);

        self::assertCount(1, $this->fake->clients);
        $attaches = array_values(array_map(static fn (array $r): array => $r['body']['inboundIds'], array_filter($this->fake->requests, static fn (array $r): bool => str_ends_with($r['path'], '/attach'))));
        self::assertSame([[4]], $attaches);
        self::assertSame([[true, null], [true, null]], self::states($created->attachments));
    }

    public function testAdoptionByUuidAttachesTheMissingInbounds(): void
    {
        $this->seed([1]);

        $adopted = $this->driver->findExistingService($this->request([$this->vmess, $this->trojan]), $this->panel);

        self::assertNotNull($adopted);
        self::assertSame(AdoptedVpnService::MATCHED_BY_UUID, $adopted->matchedBy);
        self::assertSame([1, 2, 4], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame([[true, null], [true, null]], self::states($adopted->service->attachments));
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines());
    }

    public function testAdoptionByEmailOnlyAttachesNothing(): void
    {
        $this->seed([1], '12345678-1234-4234-8234-123456789012');

        $adopted = $this->driver->findExistingService($this->request([$this->vmess]), $this->panel);

        self::assertNotNull($adopted);
        self::assertSame(AdoptedVpnService::MATCHED_BY_EMAIL, $adopted->matchedBy);
        self::assertSame([1], $this->fake->clients[self::EMAIL]['inboundIds'], 'a client matched by name is not changed');
        self::assertSame([false], array_map(static fn (InboundAttachment $a): bool => $a->attached, $adopted->service->attachments));
        self::assertEmpty(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
    }

    public function testRetryAttachSendsOnlyTheMissingIdsAndNeverAddsAClient(): void
    {
        $created = $this->driver->createService($this->request([]), $this->panel);
        $this->fake->requests = [];

        $results = $this->driver->attachInbounds($created->remoteId, [$this->vmess, $this->trojan], $this->panel);
        $again = $this->driver->attachInbounds($created->remoteId, [$this->vmess, $this->trojan], $this->panel);

        self::assertSame([[true, null], [true, null]], self::states($results));
        self::assertSame([[true, null], [true, null]], self::states($again));
        self::assertSame([
            'GET panel/api/clients/get/'.self::EMAIL, 'POST panel/api/clients/'.self::EMAIL.'/attach', 'GET panel/api/clients/get/'.self::EMAIL,
            'GET panel/api/clients/get/'.self::EMAIL,
        ], $this->fake->requestLines(), 'the second retry finds nothing missing: one GET, no attach');
        self::assertCount(1, $this->fake->clients);
    }

    public function testRetryAttachOfAClientGoneFromThePanelFailsWithoutCreatingIt(): void
    {
        $created = $this->driver->createService($this->request([]), $this->panel);
        $this->driver->deleteService($created->remoteId, $this->panel);

        try {
            $this->driver->attachInbounds($created->remoteId, [$this->vmess], $this->panel);
            self::fail('attached a client that does not exist');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not exist on the panel', $e->getMessage());
        }
        self::assertSame([], $this->fake->clients);
    }

    public function testEveryOtherOperationIsOneRecordByEmail(): void
    {
        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);
        $remoteId = $created->remoteId;
        $this->fake->useTraffic(self::EMAIL, 3 * Sanaei3xuiModernDriver::BYTES_PER_GB);
        $this->fake->requests = [];

        $this->driver->renewService($remoteId, new RenewVpnServiceRequest(durationDays: 30, trafficLimitGb: 80), $this->panel);
        $this->driver->addTrafficLimit($remoteId, 90, $this->panel);
        $this->driver->suspendService($remoteId, $this->panel);
        $this->driver->activateService($remoteId, $this->panel);
        $this->driver->resetUsage($remoteId, $this->panel);
        $usage = $this->driver->getUsage($remoteId, $this->panel);
        $usages = $this->driver->getUsages($this->panel, [$remoteId]);

        foreach ($this->fake->requestLines() as $line) {
            self::assertMatchesRegularExpression('#^(GET|POST) panel/api/clients/(get|update|resetTraffic|traffic)/'.self::EMAIL.'$|^GET panel/api/clients/list$#', $line, 'by email, never per inbound');
        }
        self::assertSame([1, 2, 4], $this->fake->clients[self::EMAIL]['inboundIds'], 'updates keep every inbound');
        self::assertSame(90 * Sanaei3xuiModernDriver::BYTES_PER_GB, $this->fake->clients[self::EMAIL]['totalGB']);
        self::assertSame(0, $usage->usedBytes);
        self::assertSame(0, $usages[$remoteId]->usedBytes);
    }

    public function testDeleteRemovesTheClientFromEveryInbound(): void
    {
        $created = $this->driver->createService($this->request([$this->vmess, $this->trojan]), $this->panel);
        $this->fake->requests = [];

        $this->driver->deleteService($created->remoteId, $this->panel);

        self::assertSame(['POST panel/api/clients/del/'.self::EMAIL, 'GET panel/api/clients/get/'.self::EMAIL], $this->fake->requestLines());
        foreach ([1, 2, 4] as $id) {
            self::assertSame([], array_filter($this->fake->clients, static fn (array $c): bool => in_array($id, $c['inboundIds'], true)), 'no client left on inbound '.$id);
        }
    }

    public function testAdditionalInboundsOfAnotherPanelAreRefusedBeforeAnyCall(): void
    {
        $other = $this->fakeInbound((new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://other.test/'), 2);

        $this->expectExceptionMessage('does not belong to the selected panel');
        try {
            $this->driver->createService($this->request([$other]), $this->panel);
        } finally {
            self::assertSame([], $this->fake->requests);
        }
    }

    public function testTheLegacyDriverRefusesAdditionalInboundsBeforeAnyRequest(): void
    {
        $legacy = new Sanaei3xuiDriver(new Sanaei3xuiApiClient($this->fake->client(), new PanelHttpClientFactory(), $this->credentials()), new Sanaei3xuiRemoteIdParser(), new Sanaei3xuiConfigGenerator());
        $panel = $this->fakePanel(true, true);

        try {
            $legacy->createService($this->request([$this->vmess]), $panel);
            self::fail('the legacy driver provisioned the primary alone');
        } catch (\RuntimeException $e) {
            self::assertSame('Several inbounds need the panel in modern mode (Panels → test connection).', $e->getMessage());
        }
        self::assertSame([], $this->fake->requests);
    }

    /**
     * @param list<VpnInbound> $additional
     */
    private function request(array $additional): CreateVpnServiceRequest
    {
        return new CreateVpnServiceRequest(
            username: self::EMAIL,
            durationDays: 30,
            trafficLimitGb: 50,
            inbound: $this->primary,
            remoteInboundId: '1',
            meta: ['orderId' => 42, 'telegramId' => '123456'],
            clientUuid: self::UUID,
            additionalInbounds: $additional,
        );
    }

    /**
     * @param list<int> $inboundIds
     */
    private function seed(array $inboundIds, string $uuid = self::UUID): void
    {
        $this->fake->clients[self::EMAIL] = ['email' => self::EMAIL, 'id' => $uuid, 'subId' => 'seeded42', 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => $inboundIds, 'dbId' => 7];
    }

    /**
     * @param list<InboundAttachment> $attachments
     *
     * @return list<array{bool, ?string}>
     */
    private static function states(array $attachments): array
    {
        return array_map(static fn (InboundAttachment $a): array => [$a->attached, $a->error], $attachments);
    }
}
