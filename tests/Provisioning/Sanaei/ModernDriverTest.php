<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiConfigGenerator;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;

/**
 * The modern driver against the stateful fake 3x-ui: every operation, the units (bytes, ms, first-use expiry),
 * update-replaces-row (fields preserved), idempotent retries, adoption, lost responses and write verification.
 */
final class ModernDriverTest extends TestCase
{
    use Fake3xuiWiring;

    private const GIB = 1073741824;
    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const EMAIL = 'tg_123456_order_42';

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $this->driver = $this->modernDriver();
    }

    public function testCreateSendsTheOrderDerivedClientAndVerifiesIt(): void
    {
        $before = time() * 1000;
        $created = $this->driver->createService($this->request(durationDays: 30, trafficGb: 50, ipLimit: 2), $this->panel);

        $row = $this->fake->clients[self::EMAIL];
        self::assertSame(self::UUID, $row['id'], 'the UUID is sent explicitly, so a retry can adopt by UUID');
        self::assertSame([1], $row['inboundIds']);
        self::assertSame(50 * self::GIB, $row['totalGB'], 'totalGB is bytes');
        self::assertEqualsWithDelta($before + 30 * 86400000, $row['expiryTime'], 5000, 'expiryTime is Unix milliseconds');
        self::assertSame(2, $row['limitIp']);
        self::assertSame(123456, $row['tgId']);
        self::assertSame('amoobot order #42', $row['comment']);
        self::assertSame('xtls-rprx-vision', $row['flow']);
        self::assertTrue($row['enable']);

        $ref = (new Sanaei3xuiRemoteIdParser())->parse($created->remoteId);
        self::assertNotNull($ref);
        self::assertSame(self::EMAIL, $ref->email);
        self::assertSame(self::UUID, $ref->clientId);
        self::assertSame($row['subId'], $created->subId);
        self::assertSame(self::UUID, $created->clientUuid);
        self::assertSame(['vless://'.self::UUID.'@panel.example.test:443?type=tcp#'.self::EMAIL], $created->configLinks);
        self::assertSame([
            'POST panel/api/clients/add', 'GET panel/api/clients/get/'.self::EMAIL,
            'GET panel/api/clients/links/'.self::EMAIL, 'GET panel/api/clients/subLinks/'.$row['subId'],
        ], $this->fake->requestLines());
        self::assertSame(['client', 'inboundIds'], array_keys((array) $this->fake->requests[0]['body']));
    }

    public function testUnlimitedTrafficAndDurationAreZero(): void
    {
        $this->driver->createService($this->request(durationDays: 0, trafficGb: null), $this->panel);

        self::assertSame(0, $this->fake->clients[self::EMAIL]['totalGB']);
        self::assertSame(0, $this->fake->clients[self::EMAIL]['expiryTime']);
    }

    public function testAnExpiryThatStartsAtFirstUseIsNegative(): void
    {
        $this->driver->createService($this->request(durationDays: 30, meta: ['startOnFirstUse' => true]), $this->panel);
        self::assertSame(-30 * 86400000, $this->fake->clients[self::EMAIL]['expiryTime']);

        $usage = $this->driver->getUsage($this->remoteId(), $this->panel);
        self::assertNull($usage->expiresAt, 'not started yet: no date');
    }

    public function testRenewReplacesTheRowWithEveryFieldPreserved(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        // Fields set on the panel by hand that the bot does not manage.
        $this->fake->clients[self::EMAIL] = array_replace($this->fake->clients[self::EMAIL], ['comment' => 'VIP customer', 'limitIp' => 3, 'limitHwid' => 2, 'group' => 'resellers', 'reset' => 30, 'tgId' => 999, 'enable' => false]);
        $subId = $this->fake->clients[self::EMAIL]['subId'];
        $expires = new \DateTimeImmutable('2030-01-02 03:04:05 UTC');

        $this->driver->renewService($this->remoteId(), new RenewVpnServiceRequest(durationDays: 30, trafficLimitGb: 80, expiresAt: $expires), $this->panel);

        $row = $this->panelRow();
        self::assertSame($expires->getTimestamp() * 1000, $row['expiryTime']);
        self::assertSame(80 * self::GIB, $row['totalGB']);
        self::assertTrue($row['enable']);
        foreach (['comment' => 'VIP customer', 'limitIp' => 3, 'limitHwid' => 2, 'group' => 'resellers', 'reset' => 30, 'tgId' => 999, 'flow' => 'xtls-rprx-vision', 'id' => self::UUID, 'subId' => $subId] as $field => $value) {
            self::assertSame($value, $row[$field], $field.' survives the row replacement');
        }
        $update = $this->lastRequest('POST', 'panel/api/clients/update/'.self::EMAIL);
        self::assertArrayNotHasKey('client', (array) $update['body'], 'the update body is the bare client');
        self::assertArrayNotHasKey('inboundIds', (array) $update['body']);
        self::assertSame('GET panel/api/clients/get/'.self::EMAIL, $this->fake->requestLines()[count($this->fake->requests) - 3], 'GET first');
    }

    public function testARetriedRenewWritesTheSameRow(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $request = new RenewVpnServiceRequest(durationDays: 30, trafficLimitGb: 80, expiresAt: new \DateTimeImmutable('+60 days'));
        $this->driver->renewService($this->remoteId(), $request, $this->panel);
        $first = $this->fake->clients[self::EMAIL];
        $this->driver->renewService($this->remoteId(), $request, $this->panel);

        self::assertSame($first, $this->fake->clients[self::EMAIL]);
    }

    public function testARenewWhoseResponseWasLostCanBeRetried(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $request = new RenewVpnServiceRequest(durationDays: 30, trafficLimitGb: 70, expiresAt: new \DateTimeImmutable('+45 days'));
        $this->fake->script('POST', 'panel/api/clients/update/'.self::EMAIL, Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds'), 1, afterHandling: true);

        try {
            $this->driver->renewService($this->remoteId(), $request, $this->panel);
            self::fail('the lost answer is reported');
        } catch (PanelApiException $e) {
            self::assertSame(PanelFailure::NETWORK_TIMEOUT, $e->failure);
        }
        $this->driver->renewService($this->remoteId(), $request, $this->panel);

        self::assertSame(70 * self::GIB, $this->fake->clients[self::EMAIL]['totalGB'], 'absolute values: applying twice is the same as once');
    }

    public function testAddTrafficChangesOnlyTheLimit(): void
    {
        $this->driver->createService($this->request(trafficGb: 50), $this->panel);
        $before = $this->fake->clients[self::EMAIL];
        $this->driver->addTrafficLimit($this->remoteId(), 60, $this->panel);

        self::assertSame(array_replace($before, ['totalGB' => 60 * self::GIB]), $this->fake->clients[self::EMAIL]);
    }

    public function testSuspendAndActivateFlipTheEnableFlag(): void
    {
        $this->driver->createService($this->request(trafficGb: 1), $this->panel);
        $this->driver->suspendService($this->remoteId(), $this->panel);
        self::assertFalse($this->fake->clients[self::EMAIL]['enable']);

        $this->driver->activateService($this->remoteId(), $this->panel);
        self::assertTrue($this->fake->clients[self::EMAIL]['enable']);

        // Documented: re-enabling a depleted client only flips the flag; the panel's loop disables it again.
        $this->fake->useTraffic(self::EMAIL, 2 * self::GIB);
        $this->fake->runTrafficLoop();
        $this->driver->activateService($this->remoteId(), $this->panel);
        $this->fake->runTrafficLoop();
        self::assertFalse($this->fake->clients[self::EMAIL]['enable']);
    }

    public function testDeleteRemovesTheClientAndIsSafeToRepeat(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $this->driver->deleteService($this->remoteId(), $this->panel);
        self::assertArrayNotHasKey(self::EMAIL, $this->fake->clients);
        self::assertContains('POST panel/api/clients/del/'.self::EMAIL, $this->fake->requestLines());
        self::assertSame(['keepTraffic' => '0'], $this->lastRequest('POST', 'panel/api/clients/del/'.self::EMAIL)['query']);

        $this->driver->deleteService($this->remoteId(), $this->panel);
    }

    public function testResetUsageAndUsage(): void
    {
        $this->driver->createService($this->request(durationDays: 10, trafficGb: 20), $this->panel);
        $this->fake->useTraffic(self::EMAIL, 3 * self::GIB + 5);

        $usage = $this->driver->getUsage($this->remoteId(), $this->panel);
        self::assertSame(3 * self::GIB + 5, $usage->usedBytes);
        self::assertSame(3, $usage->trafficUsedGb);
        self::assertSame(20 * self::GIB, $usage->totalBytes);
        self::assertSame(20, $usage->trafficLimitGb);
        self::assertTrue($usage->isEnabled);
        self::assertSame(intdiv($this->fake->clients[self::EMAIL]['expiryTime'], 1000), $usage->expiresAt?->getTimestamp());

        $this->driver->resetUsage($this->remoteId(), $this->panel);
        self::assertSame(0, $this->driver->getUsage($this->remoteId(), $this->panel)->usedBytes);
    }

    public function testUsageOfManyClientsIsOneRequest(): void
    {
        $remoteIds = [];
        foreach ([1, 2, 3] as $n) {
            $created = $this->driver->createService($this->request(email: 'tg_1_order_'.$n, uuid: sprintf('00000000-0000-4000-8000-00000000000%d', $n), trafficGb: 10), $this->panel);
            $remoteIds[] = $created->remoteId;
            $this->fake->useTraffic('tg_1_order_'.$n, $n * self::GIB);
        }
        $this->fake->requests = [];

        $usages = $this->driver->getUsages($this->panel, [...$remoteIds, (new Sanaei3xuiRemoteIdParser())->format(0, 0, '1', 'x', 'gone@x')]);

        self::assertSame(['GET panel/api/clients/list'], $this->fake->requestLines());
        self::assertCount(3, $usages, 'a client the panel does not report is left out');
        self::assertSame(2 * self::GIB, $usages[$remoteIds[1]]->usedBytes);
        self::assertSame(10 * self::GIB, $usages[$remoteIds[1]]->totalBytes);
    }

    public function testACreateWhoseAnswerWasLostIsAdoptedByItsUuid(): void
    {
        $this->fake->script('POST', 'panel/api/clients/add', Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds'), 1, afterHandling: true);
        $created = $this->driver->createService($this->request(), $this->panel);

        self::assertSame(self::UUID, $created->clientUuid);
        self::assertCount(1, $this->fake->clients);
        self::assertSame(1, count(array_filter($this->fake->requestLines(), static fn (string $l): bool => 'POST panel/api/clients/add' === $l)), 'the add is never sent twice');
    }

    public function testARetriedCreateAdoptsItsOwnClient(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $again = $this->driver->createService($this->request(), $this->panel);

        self::assertSame(self::UUID, $again->clientUuid);
        self::assertCount(1, $this->fake->clients);
    }

    public function testCreateRefusesAnEmailThatBelongsToAnotherClient(): void
    {
        $this->driver->createService($this->request(uuid: '11111111-1111-4111-8111-111111111111'), $this->panel);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists on the panel with another UUID');
        $this->driver->createService($this->request(), $this->panel);
    }

    public function testAdoptionByUuid(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $adopted = $this->driver->findExistingService($this->request(), $this->panel);

        self::assertNotNull($adopted);
        self::assertSame(AdoptedVpnService::MATCHED_BY_UUID, $adopted->matchedBy);
        self::assertSame(self::UUID, $adopted->service->clientUuid);
    }

    public function testAdoptionByUuidOfAClientRenamedOnThePanel(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $this->fake->clients['renamed'] = array_replace($this->fake->clients[self::EMAIL], ['email' => 'renamed']);
        unset($this->fake->clients[self::EMAIL]);

        $adopted = $this->driver->findExistingService($this->request(), $this->panel);

        self::assertSame(AdoptedVpnService::MATCHED_BY_UUID, $adopted?->matchedBy);
        self::assertSame('renamed', $adopted->service->clientEmail);
    }

    public function testAnEmailMatchWithAnotherUuidIsReportedAsSuch(): void
    {
        $this->driver->createService($this->request(uuid: '22222222-2222-4222-8222-222222222222'), $this->panel);
        $adopted = $this->driver->findExistingService($this->request(), $this->panel);

        // VpnProvisioningService adopts this only for generated names and refuses it for customer-chosen names.
        self::assertSame(AdoptedVpnService::MATCHED_BY_EMAIL, $adopted?->matchedBy);
    }

    public function testNoClientMeansNothingToAdopt(): void
    {
        self::assertNull($this->driver->findExistingService($this->request(), $this->panel));
    }

    public function testAnUnreadablePanelIsNeverTakenAsNoClient(): void
    {
        $this->fake->script('GET', 'panel/api/clients/get/'.self::EMAIL, Fake3xuiPanel::html(404));

        $this->expectException(PanelApiException::class);
        $this->driver->findExistingService($this->request(), $this->panel);
    }

    public function testAnUpdateThePanelDidNotApplyIsAnError(): void
    {
        $this->driver->createService($this->request(), $this->panel);
        $this->fake->script('POST', 'panel/api/clients/update/'.self::EMAIL, Fake3xuiPanel::envelope(true, null, 'ok'));

        $this->expectException(PanelApiException::class);
        $this->expectExceptionMessage('did not apply "enable"');
        $this->driver->suspendService($this->remoteId(), $this->panel);
    }

    public function testOperationsOnAMissingClientFailClearly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist on the panel');
        $this->driver->suspendService($this->remoteId(), $this->panel);
    }

    public function testTheRegistryPicksTheDriverByEffectiveMode(): void
    {
        $legacy = new Sanaei3xuiDriver(new Sanaei3xuiApiClient($this->fake->client(), new PanelHttpClientFactory(), $this->credentials()), new Sanaei3xuiRemoteIdParser(), new Sanaei3xuiConfigGenerator());
        $panel = $this->fakePanel();

        self::assertTrue($legacy->supports($panel), 'auto, never probed: legacy');
        self::assertFalse($this->driver->supports($panel));

        $panel->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        self::assertFalse($legacy->supports($panel));
        self::assertTrue($this->driver->supports($panel));
        self::assertTrue($this->driver->supportsProvisioning($panel));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function request(int $durationDays = 30, ?int $trafficGb = 50, ?int $ipLimit = null, string $email = self::EMAIL, string $uuid = self::UUID, array $meta = []): CreateVpnServiceRequest
    {
        return new CreateVpnServiceRequest(
            username: $email,
            durationDays: $durationDays,
            trafficLimitGb: $trafficGb,
            ipLimit: $ipLimit,
            inbound: $this->fakeInbound($this->panel),
            remoteInboundId: '1',
            meta: array_merge(['orderId' => 42, 'telegramId' => '123456'], $meta),
            clientUuid: $uuid,
        );
    }

    /**
     * @return array<string, mixed> the client as the fake panel stores it now
     */
    private function panelRow(): array
    {
        return $this->fake->clients[self::EMAIL];
    }

    private function remoteId(): string
    {
        return (new Sanaei3xuiRemoteIdParser())->format(null, null, '1', self::UUID, self::EMAIL, $this->fake->clients[self::EMAIL]['subId'] ?? null);
    }

    /**
     * @return array{method: string, path: string, query: array<string, string>, headers: array<string, list<string>>, body: mixed}
     */
    private function lastRequest(string $method, string $path): array
    {
        foreach (array_reverse($this->fake->requests) as $request) {
            if ($request['method'] === $method && $request['path'] === $path) {
                return $request;
            }
        }
        self::fail(sprintf('no %s %s request', $method, $path));
    }
}
