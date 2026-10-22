<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\AdminUser;
use App\Provisioning\Application\ServiceConfigRegeneration;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Regenerate config" of one service, extracted from VpnPanelInboundActionsController: the web action and a direct
 * call (as the Mini App makes it) give the same result.
 */
final class ServiceConfigRegeneratorTest extends IntegrationTestCase
{
    public function testAServiceWithoutAClientIdentityIsLeftAlone(): void
    {
        $service = $this->createActiveService($this->createUser('8721'), $this->createTestInbound());
        $result = self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($service, 'test');

        self::assertSame(ServiceConfigRegeneration::NO_IDENTITY, $result->outcome);
        self::assertFalse($result->succeeded());
    }

    public function testTheWebActionAndTheServiceRegenerateTheSameLinks(): void
    {
        $inbound = $this->createTestInbound();
        $user = $this->createUser('8722');
        $viaWeb = $this->createActiveService($user, $inbound)->setClientUuid('11111111-2222-3333-4444-555555555555')->setSubId('subweb');
        $direct = $this->createActiveService($user, $inbound)->setClientUuid('11111111-2222-3333-4444-555555555555')->setSubId('subweb');
        $this->em->flush();

        $result = self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($direct, 'test');
        self::assertContains($result->outcome, [ServiceConfigRegeneration::REGENERATED, ServiceConfigRegeneration::FAILED]);

        $admin = (new AdminUser())->setEmail('regen@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $client->request('GET', '/admin/vpn-service/'.$viaWeb->getId().'/regenerate-config');
        self::assertSame(302, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $web = $this->row('vpn_service', (int) $viaWeb->getId());
        $mine = $this->row('vpn_service', (int) $direct->getId());
        self::assertSame($mine['config_text'], $web['config_text']);
        self::assertSame($mine['subscription_url'], $web['subscription_url']);
        self::assertSame(null === $mine['last_access_info_synced_at'], null === $web['last_access_info_synced_at']);
    }
}
