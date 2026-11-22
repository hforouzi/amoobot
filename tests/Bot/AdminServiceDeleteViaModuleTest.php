<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SpyProductModule;

/**
 * PM1d: the bot's admin delete (ServiceManagementService::deleteService(), "service_delete_confirm") goes through the
 * service's product module, terminate(), exactly once with the service, and answers as before. For 'vpn' that is the
 * same ServiceAdministration::delete() call; a second delete is the module's ALREADY_DELETED outcome, as before.
 */
final class AdminServiceDeleteViaModuleTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private SpyProductModule $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = SpyProductModule::install(self::getContainer());
    }

    public function testDeleteGoesThroughTerminateOnce(): void
    {
        $service = $this->createActiveService($this->createUser('8821'), $this->createTestInbound());
        $id = (int) $service->getId();
        $this->em->clear();

        $this->pressButton(self::ENV_ADMIN, 'service_delete_confirm:'.$id);

        self::assertSame(['terminate'], $this->spy->methods());
        $this->assertCalledWith(0, $id, ServiceAdminOutcome::DONE);
        self::assertSame(VpnServiceStatus::DELETED, $this->row('vpn_service', $id)['status']);
        self::assertContains('سرویس حذف شد.', $this->callbackAnswers());

        $this->http->reset();
        $this->pressButton(self::ENV_ADMIN, 'service_delete_confirm:'.$id);

        self::assertSame(['terminate', 'terminate'], $this->spy->methods());
        $this->assertCalledWith(1, $id, ServiceAdminOutcome::ALREADY_DELETED);
    }

    public function testAnUnknownServiceNeverReachesTheModule(): void
    {
        $this->pressButton(self::ENV_ADMIN, 'service_delete_confirm:99999999');

        self::assertSame([], $this->spy->methods());
        self::assertContains('سرویس معتبر نیست.', $this->callbackAnswers());
    }

    private function assertCalledWith(int $index, int $serviceId, ServiceAdminOutcome $outcome): void
    {
        $call = $this->spy->calls[$index];
        self::assertCount(1, $call['args']);
        self::assertInstanceOf(VpnService::class, $call['args'][0]);
        self::assertSame($serviceId, $call['args'][0]->getId());
        self::assertSame($outcome, $call['result']);
    }

    /** @return list<string> */
    private function callbackAnswers(): array
    {
        return array_map(fn (array $request): string => (string) ($this->requestBody($request)['text'] ?? ''), $this->http->requests('/answerCallbackQuery'));
    }
}
