<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\AdminForms;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SpyProductModule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * PM1c: the web "delete on panel" goes through the service's product module (terminate()) exactly once, with the
 * service, only after the typed name matches, and flashes and audits as before.
 */
final class ServiceDeleteViaModuleTest extends IntegrationTestCase
{
    use AdminForms;

    private KernelBrowser $client;
    private SpyProductModule $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = SpyProductModule::install(self::getContainer());
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $admin = (new AdminUser())->setEmail('pm1c-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    public function testTheWebDeleteTerminatesThroughTheModuleOnce(): void
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound());
        $id = (int) $service->getId();
        $url = '/admin/vpn-service/'.$id.'/delete-on-panel';
        $token = $this->csrfOf($this->client, $url);

        $this->client->request('POST', $url, ['confirmName' => 'wrong', '_token' => $token]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame([], $this->spy->methods(), 'a wrong name never reaches the module');

        $this->client->request('POST', $url, ['confirmName' => 'existing_user', '_token' => $token]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame(['terminate'], $this->spy->methods());
        $call = $this->spy->calls[0];
        self::assertCount(1, $call['args']);
        self::assertInstanceOf(VpnService::class, $call['args'][0]);
        self::assertSame($id, $call['args'][0]->getId());
        self::assertSame(ServiceAdminOutcome::DONE, $call['result']);

        self::assertSame(VpnServiceStatus::DELETED, $this->row('vpn_service', $id)['status']);
        self::assertSame(['outcome' => 'done'], json_decode((string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'service.delete' ORDER BY id DESC LIMIT 1"), true));
    }
}
