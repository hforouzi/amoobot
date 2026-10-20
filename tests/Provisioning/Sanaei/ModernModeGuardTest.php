<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Application\ModernModeGuard;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\IntegrationTestCase;

/**
 * "modern" may not be forced on a panel that sells (active plan) until a probe confirmed the modern API.
 */
final class ModernModeGuardTest extends IntegrationTestCase
{
    public function testRefusalRules(): void
    {
        $guard = self::getContainer()->get(ModernModeGuard::class);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('guard')->setBaseUrl('https://panel.example.test:2053/');
        $this->em->persist($panel);
        $this->em->flush();

        self::assertSame('panel_connection.guard.no_token', $guard->refusal($panel, SanaeiIntegrationMode::MODERN)['key'] ?? null);

        $panel->setPlainApiToken('guard-token-0123456789abcdefghij');
        $this->em->flush();
        self::assertNull($guard->refusal($panel, SanaeiIntegrationMode::MODERN), 'no active plan: allowed');
        self::assertNull($guard->refusal($panel, SanaeiIntegrationMode::AUTO), 'auto is always allowed');

        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('in');
        $this->em->persist($inbound);
        $plan = $this->createPlan(inbound: $inbound);
        /** @var array{key: string, params: array<string, string>}|null $refusal (a new plan now uses the panel) */
        $refusal = $guard->refusal($panel, SanaeiIntegrationMode::MODERN);
        self::assertIsArray($refusal);
        self::assertSame('panel_connection.guard.not_confirmed', $refusal['key']);
        self::assertSame('-', $refusal['params']['%status%'] ?? null);

        $panel->recordProbe(null, 'auth_rejected', new \DateTimeImmutable());
        self::assertSame('auth_rejected', $guard->refusal($panel, SanaeiIntegrationMode::MODERN)['params']['%status%'] ?? null, 'the reason is shown');

        $panel->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        self::assertNull($guard->refusal($panel, SanaeiIntegrationMode::MODERN), 'confirmed by a probe: allowed');

        $panel->recordProbe(null, 'network_timeout', new \DateTimeImmutable());
        self::assertNotNull($guard->refusal($panel, SanaeiIntegrationMode::MODERN), 'the last probe must have succeeded');

        $plan->setIsActive(false);
        $this->em->flush();
        self::assertNull($guard->refusal($panel, SanaeiIntegrationMode::MODERN), 'inactive plans do not count');
    }
}
