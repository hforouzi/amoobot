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
 * PM1c: the bot's admin "suspend" and "activate" buttons go through the service's product module (suspend(),
 * resume()) exactly once, with the service, and answer as before. Delete, extend, add traffic and reset usage still
 * call ServiceAdministration directly, so they never reach the module.
 */
final class AdminServiceLifecycleViaModuleTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private SpyProductModule $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = SpyProductModule::install(self::getContainer());
    }

    public function testSuspendAndActivateGoThroughTheModuleOnce(): void
    {
        $service = $this->createActiveService($this->createUser('8811'), $this->createTestInbound());
        $id = (int) $service->getId();
        $this->em->clear();

        $this->pressButton(self::ENV_ADMIN, 'service_suspend:'.$id);

        self::assertSame(['suspend'], $this->spy->methods());
        $this->assertCalledWith($id, ServiceAdminOutcome::DONE);
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->row('vpn_service', $id)['status']);
        self::assertContains('سرویس غیرفعال شد.', $this->callbackAnswers());

        $this->spy->calls = [];
        $this->http->reset();
        $this->pressButton(self::ENV_ADMIN, 'service_activate:'.$id);

        self::assertSame(['resume'], $this->spy->methods());
        $this->assertCalledWith($id, ServiceAdminOutcome::DONE);
        self::assertSame(VpnServiceStatus::ACTIVE, $this->row('vpn_service', $id)['status']);
        self::assertContains('سرویس فعال شد.', $this->callbackAnswers());
    }

    public function testARefusedSuspendIsTheSameOutcomeThroughTheModule(): void
    {
        $service = $this->createActiveService($this->createUser('8812'), $this->createTestInbound());
        $id = (int) $service->getId();
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET status = ? WHERE id = ?', [VpnServiceStatus::SUSPENDED, $id]);
        $this->em->clear();

        $this->pressButton(self::ENV_ADMIN, 'service_suspend:'.$id);

        self::assertSame(['suspend'], $this->spy->methods());
        $this->assertCalledWith($id, ServiceAdminOutcome::ALREADY_SUSPENDED);
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->row('vpn_service', $id)['status']);
    }

    public function testTheOtherAdminActionsDoNotReachTheModule(): void
    {
        $service = $this->createActiveService($this->createUser('8813'), $this->createTestInbound());
        $id = (int) $service->getId();
        $this->em->clear();

        foreach (['service_extend:%d:30', 'service_add_traffic:%d:10', 'service_reset_usage:%d', 'service_delete_confirm:%d'] as $callback) {
            $this->pressButton(self::ENV_ADMIN, sprintf($callback, $id));
        }

        self::assertSame([], $this->spy->methods());
        self::assertSame(VpnServiceStatus::DELETED, $this->row('vpn_service', $id)['status']);
    }

    private function assertCalledWith(int $serviceId, ServiceAdminOutcome $outcome): void
    {
        $call = $this->spy->calls[0];
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
