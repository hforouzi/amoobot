<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Customers and services in the Mini App admin: search, detail, and the service actions by permission (assist,
 * manage, delete), through the services the bot and the web panel use.
 */
final class MiniAppAdminServicesTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const GB = 1073741824;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    public function testCustomersCanBeFoundByTelegramIdUsernameNameOrMobile(): void
    {
        $this->buyer->setMobile('09121234567')->setName('Reza Buyer');
        $this->buyer->getTelegramAccount()?->setUsername('reza_vpn')->setFirstName('Reza');
        $this->em->flush();
        $session = $this->authenticate(self::SUPPORT);

        foreach ([self::BUYER, '@reza_vpn', 'Reza', '0912123', '۰۹۱۲۱۲۳'] as $query) {
            $page = new Crawler($this->appGet('/app/admin/customers?q='.urlencode($query), $session));
            self::assertSame(200, $this->httpStatus());
            self::assertContains((string) $this->buyer->getId(), $page->filter('[data-customer-id]')->each(static fn (Crawler $c): string => (string) $c->attr('data-customer-id')), $query);
        }
        self::assertCount(1, (new Crawler($this->appGet('/app/admin/customers?q=R', $session)))->filter('[data-too-short]'));

        $detail = new Crawler($this->appGet('/app/admin/customers/'.$this->buyer->getId(), $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $detail->filter('[data-service-id="'.$this->service->getId().'"]'));
        self::assertCount(1, $detail->filter('[data-payments-summary]'));
        self::assertStringContainsString(self::BUYER, $detail->text());
    }

    public function testServiceListsFilterByTabIncludingLowTraffic(): void
    {
        $session = $this->authenticate(self::SUPPORT);
        $ids = fn (string $query): array => (new Crawler($this->appGet('/app/admin/services'.$query, $session)))->filter('[data-service-id]')->each(static fn (Crawler $c): string => (string) $c->attr('data-service-id'));

        self::assertSame([(string) $this->service->getId()], $ids('?tab=active'));
        self::assertSame([], $ids('?tab=low_traffic'));
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET traffic_used_bytes = ? WHERE id = ?', [46 * self::GB, $this->service->getId()]);
        self::assertSame([(string) $this->service->getId()], $ids('?tab=low_traffic'));
        self::assertSame([], $ids('?tab=suspended'));
        self::assertSame([(string) $this->service->getId()], $ids('?q=existing_user'));

        $detail = new Crawler($this->appGet('/app/admin/services/'.$this->service->getId(), $session));
        self::assertSame(200, $this->httpStatus());
        self::assertSame('92', $detail->filter('[role="progressbar"]')->attr('aria-valuenow'));
        self::assertCount(1, $detail->filter('[data-page-link]'));
        self::assertStringNotContainsString('dummy_existing', $detail->html(), 'no panel ids');
    }

    public function testSupportSeesAndRunsOnlyViewAndAssistActions(): void
    {
        $session = $this->authenticate(self::SUPPORT);
        $page = new Crawler($this->appGet('/app/admin/services/'.$this->service->getId(), $session));
        self::assertSame(['resend_config', 'sync_usage'], $page->filter('[data-admin-action]')->each(static fn (Crawler $c): string => (string) $c->attr('data-admin-action')));
        self::assertCount(0, $page->filter('[data-group="delete"], [data-group="extend"], [data-group="add_traffic"], [data-action-bar]'));

        $id = $this->service->getId();
        foreach (['suspend', 'activate', 'extend', 'add-traffic', 'reset-usage', 'regenerate-config', 'delete'] as $action) {
            $this->appPostJson('/app/api/admin/services/'.$id.'/'.$action, $session, ['days' => 30, 'trafficGb' => 10, 'confirmName' => 'existing_user'], self::newKey());
            self::assertSame(403, $this->httpStatus(), $action);
        }

        $this->appPostJson('/app/api/admin/services/'.$id.'/sync-usage', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), 'assist: no server confirm step');
        $answer = $this->appPostJson('/app/api/admin/services/'.$id.'/resend-config', $session, [], self::newKey());
        self::assertContains($this->httpStatus(), [200, 409], json_encode($answer));
        self::assertSame(['service.sync_usage', 'service.resend_config'], array_column($this->auditRows(), 'action'));
        self::assertSame(['miniapp'], array_values(array_unique(array_column($this->auditRows(), 'source'))));
        $this->em->clear();
        self::assertSame(VpnServiceStatus::ACTIVE, $this->em->find(VpnService::class, $id)?->getStatus());
    }

    public function testAnAdminManagesAServiceWithPresetsAndPersianDigits(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $id = (int) $this->service->getId();
        $page = new Crawler($this->appGet('/app/admin/services/'.$id, $session));
        self::assertCount(1, $page->filter('[data-group="extend"]'));
        self::assertCount(0, $page->filter('[data-admin-action="regenerate_config"]'), 'ADMIN has no PANELS_MANAGE, as on the web');
        self::assertCount(1, $page->filter('[data-group="delete"]'));

        $before = $this->em->find(VpnService::class, $id)?->getExpiresAt();
        $this->confirmedPost('/app/api/admin/services/'.$id.'/extend', $session, ['days' => '۱۰']);
        self::assertSame(200, $this->httpStatus());
        $this->confirmedPost('/app/api/admin/services/'.$id.'/add-traffic', $session, ['trafficGb' => 10]);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        $service = $this->em->find(VpnService::class, $id);
        self::assertSame(10 * 86400, ($service?->getExpiresAt()?->getTimestamp() ?? 0) - ($before?->getTimestamp() ?? 0));
        self::assertSame(60, $service?->getTrafficLimitGb());

        foreach ([['extend', ['days' => 0]], ['extend', ['days' => '99999']], ['extend', ['days' => 'ten']], ['add-traffic', ['trafficGb' => -1]], ['add-traffic', ['trafficGb' => 100001]]] as [$action, $body]) {
            $this->appPostJson('/app/api/admin/services/'.$id.'/'.$action, $session, $body, self::newKey());
            self::assertSame(422, $this->httpStatus(), $action.' '.json_encode($body));
        }

        $this->confirmedPost('/app/api/admin/services/'.$id.'/suspend', $session);
        self::assertSame(200, $this->httpStatus());
        $again = $this->confirmedPost('/app/api/admin/services/'.$id.'/suspend', $session);
        self::assertSame(409, $this->httpStatus());
        self::assertNotEmpty($again['message']);
        $this->confirmedPost('/app/api/admin/services/'.$id.'/activate', $session);
        self::assertSame(200, $this->httpStatus());
        $this->confirmedPost('/app/api/admin/services/'.$id.'/reset-usage', $session);
        self::assertSame(200, $this->httpStatus());

        self::assertSame(
            ['service.extend', 'service.add_traffic', 'service.suspend', 'service.suspend', 'service.activate', 'service.reset_usage'],
            array_column($this->auditRows(), 'action')
        );
        self::assertSame(['days' => 10, 'outcome' => 'done'], json_decode((string) $this->auditRows()[0]['details'], true));
    }

    public function testDeleteNeedsTheTypedNameAndTheConfirmToken(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $path = '/app/api/admin/services/'.$this->service->getId().'/delete';

        $this->appPostJson($path, $session, ['confirmName' => 'wrong'], self::newKey());
        self::assertSame(422, $this->httpStatus());
        $this->appPostJson($path, $session, [], self::newKey());
        self::assertSame(422, $this->httpStatus());

        $this->confirmedPost($path, $session, ['confirmName' => 'existing_user']);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertSame(VpnServiceStatus::DELETED, $this->em->find(VpnService::class, $this->service->getId())?->getStatus());
    }

    public function testTheSuperAdminMayRegenerateTheConfig(): void
    {
        $session = $this->authenticate(self::SUPER);
        $page = new Crawler($this->appGet('/app/admin/services/'.$this->service->getId(), $session));
        self::assertCount(1, $page->filter('[data-admin-action="regenerate_config"]'));
        $answer = $this->confirmedPost('/app/api/admin/services/'.$this->service->getId().'/regenerate-config', $session);
        self::assertSame(409, $this->httpStatus(), 'the fixture service has no client id');
        self::assertNotEmpty($answer['message']);
        self::assertSame('service.regenerate_config', $this->auditRows()[0]['action']);
    }
}
