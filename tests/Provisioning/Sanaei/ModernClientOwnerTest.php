<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;

/**
 * The two claim-link capabilities of the modern driver against the stateful fake 3x-ui (update replaces the whole
 * row): updateClientOwner() changes tgId and comment only; rotateCredentials() gives the client a new UUID and subId and
 * keeps everything else, and refuses a client that authenticates by password. Both read back what they wrote.
 */
final class ModernClientOwnerTest extends TestCase
{
    use Fake3xuiWiring;

    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const EMAIL = 'tg_123456_order_42';

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $this->driver = $this->modernDriver();
        $this->driver->createService(new CreateVpnServiceRequest(
            username: self::EMAIL,
            durationDays: 30,
            trafficLimitGb: 50,
            ipLimit: 2,
            inbound: $this->fakeInbound($this->panel),
            remoteInboundId: '1',
            meta: ['orderId' => 42, 'telegramId' => '123456'],
            clientUuid: self::UUID,
        ), $this->panel);
    }

    public function testTheOwnerUpdateChangesTgIdAndCommentOnly(): void
    {
        $before = $this->fake->clients[self::EMAIL];

        $this->driver->updateClientOwner($this->remoteId(), 987654, 'amoobot order #42, claimed', $this->panel);

        $after = $this->fake->clients[self::EMAIL];
        self::assertSame(987654, $after['tgId']);
        self::assertSame('amoobot order #42, claimed', $after['comment']);
        foreach (['id', 'subId', 'totalGB', 'expiryTime', 'limitIp', 'enable', 'flow'] as $field) {
            self::assertSame($before[$field], $after[$field], $field.' is kept');
        }
    }

    public function testRotationGivesNewCredentialsAndKeepsTheRest(): void
    {
        $before = $this->fake->clients[self::EMAIL];
        self::assertTrue($this->driver->canRotateCredentials($this->remoteId(), $this->panel));

        $rotated = $this->driver->rotateCredentials($this->remoteId(), $this->panel);

        $after = $this->fake->clients[self::EMAIL];
        self::assertNotSame(self::UUID, $after['id']);
        self::assertSame($rotated->clientUuid, $after['id']);
        self::assertSame($rotated->subId, $after['subId']);
        self::assertNotSame($before['subId'], $after['subId']);
        foreach (['totalGB', 'expiryTime', 'limitIp', 'enable', 'tgId', 'comment'] as $field) {
            self::assertSame($before[$field], $after[$field], $field.' is kept');
        }
        $ref = (new Sanaei3xuiRemoteIdParser())->parse($rotated->remoteId);
        self::assertSame([$rotated->clientUuid, self::EMAIL, $rotated->subId], [$ref?->clientId, $ref?->email, $ref?->subId]);
        self::assertNotSame([], $rotated->configLinks);
        foreach ($rotated->configLinks as $link) {
            self::assertStringNotContainsString(self::UUID, $link, 'the old UUID is gone from the links');
        }
    }

    public function testAPasswordClientIsNotRotated(): void
    {
        $this->fake->clients[self::EMAIL]['password'] = 'trojan-secret';
        $before = $this->fake->clients[self::EMAIL];

        try {
            $this->driver->rotateCredentials($this->remoteId(), $this->panel);
            self::fail('rotated a password client');
        } catch (\RuntimeException) {
        }
        self::assertSame($before['id'], $this->fake->clients[self::EMAIL]['id']);
        self::assertSame($before['subId'], $this->fake->clients[self::EMAIL]['subId']);
    }

    private function remoteId(): string
    {
        return (new Sanaei3xuiRemoteIdParser())->format(null, null, '1', self::UUID, self::EMAIL, $this->fake->clients[self::EMAIL]['subId'] ?? null);
    }
}
