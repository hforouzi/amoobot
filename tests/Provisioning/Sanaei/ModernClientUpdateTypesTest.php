<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A client row read with GET panel/api/clients/get (3x-ui's database record: allowedIPs and reverse are strings, numbers
 * may come as strings) must always be accepted back by POST panel/api/clients/update, which unmarshals into the Go
 * struct model.Client. Production failure this covers:
 * "json: cannot unmarshal string into Go struct field .allowedIPs of type []string" on add traffic.
 */
final class ModernClientUpdateTypesTest extends TestCase
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

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function allowedIps(): iterable
    {
        yield 'empty string' => ['', []];
        yield 'comma-separated' => ['1.2.3.4,5.6.7.8', ['1.2.3.4', '5.6.7.8']];
        yield 'spaces and empties' => [' 1.2.3.4 , ,5.6.7.8/32,', ['1.2.3.4', '5.6.7.8/32']];
        yield 'JSON text' => ['["10.0.0.2/32","fd00::2/128"]', ['10.0.0.2/32', 'fd00::2/128']];
        yield 'empty array' => [[], []];
        yield 'array' => [['10.0.0.2/32', ' '], ['10.0.0.2/32']];
        yield 'null' => [null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('allowedIps')]
    public function testAllowedIpsIsAlwaysAListOfStringsInTheUpdateBody(mixed $fromPanel, array $expected): void
    {
        $body = ModernClientsApi::mergeForUpdate($this->row(['allowedIPs' => $fromPanel]), ['totalGB' => 60 * self::GIB]);

        self::assertSame($expected, $body['allowedIPs']);
        self::assertNull(Fake3xuiPanel::goUnmarshalError($body), 'the body fits model.Client');
    }

    public function testEveryFieldIsSentWithItsGoStructType(): void
    {
        $row = $this->row([
            'tgId' => '123456', 'limitIp' => '2', 'limitHwid' => '1', 'totalGB' => '53687091200', 'expiryTime' => '1767225600000', 'reset' => '30',
            'resetDay' => '', 'keepAlive' => '25', 'trafficResetDay' => null, 'enable' => 'true', 'flow' => null, 'security' => 'auto',
            'subId' => 445566, 'comment' => 7, 'group' => false, 'reverse' => '', 'id' => 15, 'uuid' => self::UUID,
        ]);

        $body = ModernClientsApi::mergeForUpdate(ModernClientsApi::normaliseClient($row), []);

        self::assertNull(Fake3xuiPanel::goUnmarshalError($body));
        self::assertSame(123456, $body['tgId']);
        self::assertSame(2, $body['limitIp']);
        self::assertSame(1, $body['limitHwid']);
        self::assertSame(50 * self::GIB, $body['totalGB']);
        self::assertSame(1767225600000, $body['expiryTime']);
        self::assertSame(30, $body['reset']);
        self::assertSame(0, $body['resetDay'], 'empty string -> 0');
        self::assertSame(25, $body['keepAlive']);
        self::assertTrue($body['enable']);
        self::assertSame('auto', $body['security']);
        self::assertSame('445566', $body['subId']);
        self::assertSame('7', $body['comment']);
        self::assertSame('', $body['group']);
        self::assertSame(self::UUID, $body['id'], 'the UUID, never the database row id');
        self::assertArrayNotHasKey('reverse', $body, 'an empty reverse string is not an object: left out (omitempty)');
    }

    public function testFalseAndZeroLikeValuesBecomeBooleans(): void
    {
        foreach (['false', '0', 0, '', 'off'] as $value) {
            self::assertFalse(ModernClientsApi::mergeForUpdate($this->row(['enable' => $value]), [])['enable'], var_export($value, true));
        }
        foreach (['true', '1', 1, 'on'] as $value) {
            self::assertTrue(ModernClientsApi::mergeForUpdate($this->row(['enable' => $value]), [])['enable'], var_export($value, true));
        }
    }

    public function testReverseStoredAsJsonTextIsSentAsAnObject(): void
    {
        $body = ModernClientsApi::mergeForUpdate($this->row(['reverse' => '{"tag":"r1","domain":"rev.example.com"}']), []);

        self::assertSame(['tag' => 'r1', 'domain' => 'rev.example.com'], $body['reverse']);
        self::assertNull(Fake3xuiPanel::goUnmarshalError($body));
    }

    public function testFieldsTheBotDoesNotKnowAreSentBackUnchanged(): void
    {
        $row = $this->row(['futureFlag' => 'x', 'futureList' => ['a'], 'privateKey' => 'k', 'forwardedPorts' => '80,443', 'trafficReset' => 'monthly']);

        $body = ModernClientsApi::mergeForUpdate($row, ['enable' => false]);

        foreach (['futureFlag' => 'x', 'futureList' => ['a'], 'privateKey' => 'k', 'forwardedPorts' => '80,443', 'trafficReset' => 'monthly'] as $field => $value) {
            self::assertSame($value, $body[$field], $field);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function updates(): iterable
    {
        yield 'add traffic' => ['addTraffic'];
        yield 'renew / extend' => ['renew'];
        yield 'reset traffic' => ['resetUsage'];
        yield 'disable then enable' => ['suspendActivate'];
    }

    /**
     * Round trip against a panel that validates the body like the Go struct (a string where an array is expected is
     * rejected), starting from a row in the shape GET really returns.
     */
    #[DataProvider('updates')]
    public function testEveryUpdateOfAnExistingClientRoundTripsAGetRow(string $operation): void
    {
        $this->fake->clients[self::EMAIL] = $this->row([
            'allowedIPs' => '10.0.0.2/32,fd00::2/128', 'reverse' => '', 'trafficReset' => 'monthly', 'resetDay' => 3, 'privateKey' => 'wg-private', 'limitHwid' => 2,
            'inboundIds' => [1], 'dbId' => 1,
        ]);
        $this->fake->traffic[self::EMAIL] = ['up' => 3 * self::GIB, 'down' => 2 * self::GIB, 'lastOnline' => 0];
        $remoteId = $this->remoteId();

        match ($operation) {
            'addTraffic' => $this->driver->addTrafficLimit($remoteId, 60, $this->panel),
            'renew' => $this->driver->renewService($remoteId, new RenewVpnServiceRequest(durationDays: 30, trafficLimitGb: 80, expiresAt: new \DateTimeImmutable('+40 days')), $this->panel),
            'resetUsage' => $this->driver->resetUsage($remoteId, $this->panel),
            'suspendActivate' => (function () use ($remoteId): void {
                $this->driver->suspendService($remoteId, $this->panel);
                self::assertFalse($this->fake->clients[self::EMAIL]['enable']);
                $this->driver->activateService($remoteId, $this->panel);
            })(),
            default => self::fail('unknown operation '.$operation),
        };

        $row = $this->fake->clients[self::EMAIL];
        self::assertTrue($row['enable']);
        if ('addTraffic' === $operation) {
            self::assertSame(60 * self::GIB, $row['totalGB']);
        }
        if ('renew' === $operation) {
            self::assertSame(80 * self::GIB, $row['totalGB']);
        }
        if ('resetUsage' === $operation) {
            self::assertSame(0, $this->fake->traffic[self::EMAIL]['up'] + $this->fake->traffic[self::EMAIL]['down']);
        }
        foreach (['allowedIPs' => '10.0.0.2/32,fd00::2/128', 'trafficReset' => 'monthly', 'resetDay' => 3, 'privateKey' => 'wg-private', 'limitHwid' => 2, 'id' => self::UUID, 'subId' => 'sub445566'] as $field => $value) {
            self::assertSame($value, $row[$field], $field.' survives the row replacement');
        }
        foreach ($this->fake->requests as $request) {
            if (str_contains($request['path'], 'clients/update/')) {
                self::assertNull(Fake3xuiPanel::goUnmarshalError((array) $request['body']), 'every update body fits model.Client');
            }
        }
    }

    public function testAJsonTypeErrorFromThePanelNamesTheField(): void
    {
        $this->fake->clients[self::EMAIL] = $this->row(['inboundIds' => [1], 'dbId' => 1]);
        $this->fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'json: cannot unmarshal string into Go struct field .allowedIPs of type []string'));

        try {
            $this->driver->addTrafficLimit($this->remoteId(), 60, $this->panel);
            self::fail('the rejection was swallowed');
        } catch (PanelApiException $e) {
            self::assertSame(PanelFailure::PANEL_REJECTED, $e->failure);
            self::assertStringContainsString('field "allowedIPs"', $e->getMessage());
            self::assertStringContainsString('expects []string, got string', $e->getMessage());
            self::assertStringContainsString('POST panel/api/clients/update/'.self::EMAIL, $e->getMessage());
            self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $e->getMessage());
        }
    }

    private function remoteId(): string
    {
        return (new Sanaei3xuiRemoteIdParser())->format(null, null, '1', self::UUID, self::EMAIL, 'sub445566');
    }

    /**
     * A row as GET panel/api/clients/get returns it on a current 3x-ui (database record shape).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'email' => self::EMAIL, 'id' => self::UUID, 'subId' => 'sub445566', 'flow' => 'xtls-rprx-vision', 'totalGB' => 50 * self::GIB,
            'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 123456, 'comment' => 'amoobot order #42', 'enable' => true, 'group' => '',
            'reset' => 0, 'allowedIPs' => '', 'reverse' => '', 'security' => '', 'password' => '', 'createdAt' => 1760000000000, 'updatedAt' => 1760000000000,
        ], $overrides);
    }
}
