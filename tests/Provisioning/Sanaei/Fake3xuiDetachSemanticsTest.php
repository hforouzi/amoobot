<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;

/**
 * The fake panel's POST clients/{email}/detach behaves like 3x-ui v3.11.0 (docs/design/plan-inbound-change.md section 1,
 * 8.2), seen through ModernClientsApi::detach() alone, without the driver's guards: later PRs test against it.
 * Read in the v3.11.0 source, not yet checked on a real panel (8.3: U1, U5 and the exact error text).
 */
final class Fake3xuiDetachSemanticsTest extends TestCase
{
    use Fake3xuiWiring;

    private const EMAIL = 'tg_123456_order_42';
    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';

    private VpnPanel $panel;
    private ModernClientsApi $api;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $this->api = new ModernClientsApi($this->modernHttp());
        $this->api->addClient($this->panel, ['email' => self::EMAIL, 'id' => self::UUID, 'subId' => 'sub0123456789abc', 'totalGB' => 5368709120, 'expiryTime' => 1893456000000, 'limitIp' => 2, 'enable' => true], [1, 2]);
        $this->fake->useTraffic(self::EMAIL, 123456789);
        $this->fake->requests = [];
    }

    public function testDetachRemovesTheInboundAndKeepsTheRecordAndTheTraffic(): void
    {
        $before = $this->fake->clients[self::EMAIL];
        $traffic = $this->fake->traffic[self::EMAIL];

        $this->api->detach($this->panel, self::EMAIL, [1]);

        self::assertSame([2], $this->fake->clients[self::EMAIL]['inboundIds']);
        self::assertSame(array_diff_key($before, ['inboundIds' => 1]), array_diff_key($this->fake->clients[self::EMAIL], ['inboundIds' => 1]), 'UUID, subId, expiry, totalGB, limitIp, enable untouched');
        self::assertSame($traffic, $this->fake->traffic[self::EMAIL], 'keepTraffic=true (U1 on a real panel)');
        self::assertSame(['POST panel/api/clients/'.self::EMAIL.'/detach'], $this->fake->requestLines());
        self::assertSame(['inboundIds' => [1]], $this->fake->requests[0]['body']);
    }

    public function testAnInboundTheClientIsNotOnIsSkippedEvenWhenThePanelNoLongerHasIt(): void
    {
        unset($this->fake->inbounds[3]);

        $this->api->detach($this->panel, self::EMAIL, [3, 99]);

        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds'], 'skipped before the inbound is loaded');
    }

    public function testTheLastInboundIsDetachedToo3xuiHasNoGuard(): void
    {
        $this->api->detach($this->panel, self::EMAIL, [1, 2]);

        self::assertSame([], $this->fake->clients[self::EMAIL]['inboundIds'], 'zero inbounds: the guard must be ours');
        self::assertArrayHasKey(self::EMAIL, $this->fake->traffic);
    }

    public function testAListedInboundThePanelCannotLoadFailsTheWholeCallBeforeAnyWrite(): void
    {
        $this->api->attach($this->panel, self::EMAIL, [3]);
        unset($this->fake->inbounds[1]);

        try {
            $this->api->detach($this->panel, self::EMAIL, [3, 1]);
            self::fail('a listed inbound that cannot be loaded fails the call');
        } catch (PanelApiException $e) {
            self::assertSame(PanelFailure::PANEL_REJECTED, $e->failure);
            self::assertStringContainsString('inbound 1', (string) $e->panelMessage);
        }
        self::assertSame([1, 2, 3], $this->fake->clients[self::EMAIL]['inboundIds'], 'nothing written, not even inbound 3');
    }

    public function testADisabledInboundIsDetachedLikeAnyOther(): void
    {
        // Inbound 3 of the fake is disabled. U5 (8.3): whether a real panel does the same is not verified yet.
        $this->api->attach($this->panel, self::EMAIL, [3]);

        $this->api->detach($this->panel, self::EMAIL, [3]);

        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }

    public function testAnUnknownClientIsNotFound(): void
    {
        try {
            $this->api->detach($this->panel, 'tg_123456_order_43', [1]);
            self::fail('no such client');
        } catch (PanelApiException $e) {
            self::assertTrue(ModernClientsApi::isNotFound($e));
        }
    }

    public function testAttachIsUnchanged(): void
    {
        unset($this->fake->inbounds[3]);

        try {
            $this->api->attach($this->panel, self::EMAIL, [3]);
            self::fail('attach of a missing inbound fails, as before');
        } catch (PanelApiException $e) {
            self::assertSame('inbound 3 not found', $e->panelMessage);
        }
        $this->api->attach($this->panel, self::EMAIL, [2]);
        self::assertSame([1, 2], $this->fake->clients[self::EMAIL]['inboundIds']);
    }
}
