<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\SpyProductModule;

/**
 * PM1c: the Mini App admin's suspend, activate and delete go through the service's product module (suspend(),
 * resume(), terminate()) exactly once, with the service, and answer and audit as before. Extend, add traffic and reset
 * usage still call ServiceAdministration and never reach the module.
 */
final class MiniAppAdminLifecycleViaModuleTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private SpyProductModule $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = SpyProductModule::install(self::getContainer());
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    public function testSuspendActivateAndDeleteGoThroughTheModuleOnceEach(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $id = (int) $this->service->getId();

        $this->confirmedPost('/app/api/admin/services/'.$id.'/suspend', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['suspend'], $this->spy->methods());
        $this->assertLastCall($id, ServiceAdminOutcome::DONE);

        $again = $this->confirmedPost('/app/api/admin/services/'.$id.'/suspend', $session);
        self::assertSame(409, $this->httpStatus());
        self::assertNotEmpty($again['message']);
        self::assertSame(['suspend', 'suspend'], $this->spy->methods());
        $this->assertLastCall($id, ServiceAdminOutcome::ALREADY_SUSPENDED);

        $this->confirmedPost('/app/api/admin/services/'.$id.'/activate', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['suspend', 'suspend', 'resume'], $this->spy->methods());
        $this->assertLastCall($id, ServiceAdminOutcome::DONE);

        foreach ([['extend', ['days' => 7]], ['add-traffic', ['trafficGb' => 10]], ['reset-usage', []]] as [$action, $body]) {
            $this->confirmedPost('/app/api/admin/services/'.$id.'/'.$action, $session, $body);
            self::assertSame(200, $this->httpStatus(), $action);
        }
        self::assertSame(['suspend', 'suspend', 'resume'], $this->spy->methods(), 'extend, add traffic and reset usage do not use the module');

        $this->confirmedPost('/app/api/admin/services/'.$id.'/delete', $session, ['confirmName' => 'existing_user']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['suspend', 'suspend', 'resume', 'terminate'], $this->spy->methods());
        $this->assertLastCall($id, ServiceAdminOutcome::DONE);

        $this->em->clear();
        self::assertSame(VpnServiceStatus::DELETED, $this->em->find(VpnService::class, $id)?->getStatus());
        self::assertSame(
            ['service.suspend', 'service.suspend', 'service.activate', 'service.extend', 'service.add_traffic', 'service.reset_usage', 'service.delete'],
            array_column($this->auditRows(), 'action')
        );
    }

    public function testAWrongNameNeverReachesTheModule(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $this->appPostJson('/app/api/admin/services/'.$this->service->getId().'/delete', $session, ['confirmName' => 'wrong'], self::newKey());

        self::assertSame(422, $this->httpStatus());
        self::assertSame([], $this->spy->methods());
    }

    private function assertLastCall(int $serviceId, ServiceAdminOutcome $outcome): void
    {
        $call = $this->spy->calls[array_key_last($this->spy->calls)];
        self::assertCount(1, $call['args']);
        self::assertInstanceOf(VpnService::class, $call['args'][0]);
        self::assertSame($serviceId, $call['args'][0]->getId());
        self::assertSame($outcome, $call['result']);
    }
}
