<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\AdminUser;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What M2b shows of additional inbounds (docs/design/multi-inbound-plans.md, phase M2b): the customer's "Servers: N"
 * and a neutral note in the bot and the Mini App (never which inbound or where), the admin list's badge and filter
 * (one query per page, whatever the number of services), the service page's next try, the panel page and the doctor.
 * Nothing changes while the switch is off or for a service without additional inbounds.
 */
final class MultiInboundVisibilityTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ConsoleOutputAssertions;
    use MiniAppTestTrait;

    private const BUYER = '8761';

    private Fake3xuiPanel $fake;
    /** @var array<string, mixed> the panel's inbound 2 while it is "deleted" */
    private array $vmessOnPanel;
    private VpnPanel $panel;
    private VpnInbound $primary;
    private Plan $plan;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->startMiniAppClient();
        $this->fake = new Fake3xuiPanel();
        $trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->primary = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $vmess = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('2')->setTitle('ws-hidden')->setProtocol('vmess');
        $trojan = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan-hidden')->setProtocol('trojan');
        foreach ([$this->panel, $this->primary, $vmess, $trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->setting(MultiInboundSettings::ENABLED, 'true');
        $this->plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds([$vmess, $trojan]);
        $this->em->flush();

        // Sold while inbound 2 was missing on the panel: 3 servers, 1 of them not connected.
        $this->vmessOnPanel = $this->fake->inbounds[2];
        unset($this->fake->inbounds[2]);
        $this->service = $this->sell($this->plan, self::BUYER);
    }

    public function testTheCustomerSeesHowManyServersAndANeutralNoteWhileOneIsNotConnected(): void
    {
        $bot = $this->botDetail();
        self::assertStringContainsString("\nتعداد سرورها: 3\n1 سرور هنوز در حال اتصال است و خودکار اضافه می‌شود.\n", $bot);
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $this->authenticate(self::BUYER)));
        self::assertSame('3', $page->filter('[data-servers]')->attr('data-servers'));
        self::assertCount(1, $page->filter('[data-servers-connecting]'));
        foreach ([$bot, $page->html()] as $text) {
            foreach (['ws-hidden', 'trojan-hidden', '2083', 'panel.example.test', '/secret'] as $detail) {
                self::assertStringNotContainsString($detail, $text, 'never which inbound, where or why');
            }
        }

        $this->fake->inbounds[2] = $this->vmessOnPanel;
        self::getContainer()->get(ServiceInboundAttacher::class)->retry($this->em->find(VpnService::class, $this->service->getId()) ?? self::fail('service'), 'admin');

        self::assertStringContainsString("\nتعداد سرورها: 3\nپروتکل", $this->botDetail(), 'all connected: no note');
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $this->authenticate(self::BUYER)));
        self::assertCount(0, $page->filter('[data-servers-connecting]'));
    }

    public function testTheNoteSaysContactSupportOnceTheRetryWillNotConnectIt(): void
    {
        $db = $this->em->getConnection();
        $connecting = '1 سرور هنوز در حال اتصال است و خودکار اضافه می‌شود.';
        $support = '1 سرور هنوز وصل نشده است؛ لطفاً با پشتیبانی تماس بگیرید.';

        // Attempts used up (or a re-attach loop stopped, which sets them to the maximum).
        $db->executeStatement('UPDATE vpn_service_inbound SET attempts = ? WHERE service_id = ? AND status = ?', [MultiInboundSettings::DEFAULT_MAX_ATTEMPTS, $this->service->getId(), 'failed']);
        $bot = $this->botDetail();
        self::assertStringContainsString("\nتعداد سرورها: 3\n".$support."\n", $bot);
        self::assertStringNotContainsString($connecting, $bot);
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $this->authenticate(self::BUYER)));
        self::assertCount(0, $page->filter('[data-servers-connecting]'));
        self::assertCount(1, $page->filter('[data-servers-not-connected]'));

        // Attempts left, but the scheduled retry is turned off.
        $db->executeStatement('UPDATE vpn_service_inbound SET attempts = 1 WHERE service_id = ?', [$this->service->getId()]);
        $this->setting(MultiInboundSettings::ATTACH_RETRY_ENABLED, 'false');
        self::assertStringContainsString("\n".$support."\n", $this->botDetail());

        // Retry on again, one retrying and one used up: both notes.
        $this->setting(MultiInboundSettings::ATTACH_RETRY_ENABLED, 'true');
        $db->executeStatement('UPDATE vpn_service_inbound SET status = ?, attempts = ? WHERE service_id = ? AND status = ?', ['failed', MultiInboundSettings::DEFAULT_MAX_ATTEMPTS, $this->service->getId(), 'attached']);
        $db->executeStatement('UPDATE vpn_service_inbound SET status = ? WHERE service_id = ? AND attempts = 1', ['pending', $this->service->getId()]);
        self::assertStringContainsString("\nتعداد سرورها: 3\n".$connecting."\n".$support."\n", $this->botDetail());
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $this->authenticate(self::BUYER)));
        self::assertCount(1, $page->filter('[data-servers-connecting]'));
        self::assertCount(1, $page->filter('[data-servers-not-connected]'));
        foreach (['ws-hidden', 'trojan-hidden', 'panel.example.test'] as $detail) {
            self::assertStringNotContainsString($detail, $page->html());
        }
    }

    public function testAServerThePanelCannotAttachSaysContactSupportNotAutomatically(): void
    {
        // M2d B4: the panel left modern mode (or the service is on another panel type). The scheduled run skips such a
        // service, so its not-connected inbounds, attempts left or not (NOT_MODERN, SWITCHED_OFF), are never retried.
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE vpn_panel SET sanaei_integration_mode = 'legacy' WHERE id = ?", [$this->panel->getId()]);
        $db->executeStatement('UPDATE vpn_service_inbound SET attempts = 1, last_error = ? WHERE service_id = ? AND status = ?', [\App\Provisioning\Application\VpnProvisioningService::SWITCHED_OFF, $this->service->getId(), 'failed']);
        $this->em->clear();

        $bot = $this->botDetail();
        self::assertStringContainsString("\nتعداد سرورها: 3\n1 سرور هنوز وصل نشده است؛ لطفاً با پشتیبانی تماس بگیرید.\n", $bot);
        self::assertStringNotContainsString('خودکار اضافه می‌شود', $bot);
        $session = $this->authenticate(self::BUYER);
        foreach (['' => 'لطفاً با پشتیبانی تماس بگیرید', '?lang=en' => 'Please contact support.'] as $query => $support) {
            $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId().$query, $session));
            self::assertCount(0, $page->filter('[data-servers-connecting]'), 'never "added automatically" '.$query);
            self::assertCount(1, $page->filter('[data-servers-not-connected]'));
            self::assertStringContainsString($support, $page->filter('[data-servers-not-connected]')->text());
        }
    }

    public function testNothingChangesForTheCustomerWithTheSwitchOffOrWithoutAdditionalInbounds(): void
    {
        $single = $this->sell($this->createPlan(90000, $this->em->find(VpnInbound::class, $this->primary->getId()) ?? self::fail('inbound')), '8762');
        $singleBot = $this->botDetail($single, '8762');
        self::assertStringNotContainsString('تعداد سرورها', $singleBot);
        self::assertStringContainsString("سرور/کشور: - / -\nپروتکل", $singleBot, 'the text as before');
        $page = new Crawler($this->appGet('/app/view/services/'.$single->getId(), $this->authenticate('8762')));
        self::assertCount(0, $page->filter('[data-servers]'));

        $this->setting(MultiInboundSettings::ENABLED, 'false');
        self::assertStringNotContainsString('تعداد سرورها', $this->botDetail());
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $this->authenticate(self::BUYER)));
        self::assertCount(0, $page->filter('[data-servers]'));
        $client = $this->admin(AdminUser::ROLE_ADMIN);
        $list = $client->request('GET', '/admin/vpn-service');
        self::assertCount(0, $list->filter('[data-inbounds-not-connected], [data-tab="inbounds_not_connected"]'));
    }

    public function testTheAdminListHasABadgeAndAFilterInOneQueryWhateverTheNumberOfServices(): void
    {
        $client = $this->admin(AdminUser::ROLE_SUPPORT);
        $list = $client->request('GET', '/admin/vpn-service');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('1', $list->filter('[data-inbounds-not-connected]')->attr('data-inbounds-not-connected'));
        self::assertStringContainsString('1', $list->filter('[data-tab="inbounds_not_connected"]')->text());
        $single = $this->sell($this->createPlan(90000, $this->em->find(VpnInbound::class, $this->primary->getId()) ?? self::fail('inbound')), '8763');

        $filtered = $client->request('GET', '/admin/vpn-service?inbounds=not_connected');
        $ids = $filtered->filter('tr[data-id]')->each(static fn (Crawler $row): string => (string) $row->attr('data-id'));
        self::assertSame([(string) $this->service->getId()], $ids, 'only the service with an inbound not attached');
        self::assertNotContains((string) $single->getId(), $ids);

        // The badge and the tab add no query per row: the list grows by the same number of queries with the switch off.
        $few = $this->listQueries($client);
        $this->setting(MultiInboundSettings::ENABLED, 'false');
        $fewOff = $this->listQueries($client);
        $this->setting(MultiInboundSettings::ENABLED, 'true');
        for ($n = 0; $n < 6; ++$n) {
            $this->sell($this->plan, (string) (8770 + $n));
        }
        $many = $this->listQueries($client);
        $this->setting(MultiInboundSettings::ENABLED, 'false');
        $manyOff = $this->listQueries($client);
        self::assertSame($many - $few, $manyOff - $fewOff, 'no query per row from the badge');
        self::assertLessThanOrEqual(2, $few - $fewOff, 'one query for the badge and the tab count (and the switch)');
    }

    public function testTheServicePageShowsTheNextTryOrThatTheRetriesAreUsedUp(): void
    {
        $client = $this->admin(AdminUser::ROLE_ADMIN);
        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertSame($this->trans('service_inbounds.next_run'), $page->filter('[data-next-attempt]')->text());
        self::assertCount(1, $page->filter('form[action*="retry-attach"]'));

        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1');
        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertSame($this->trans('service_inbounds.given_up'), $page->filter('[data-next-attempt]')->text());

        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '12');
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET next_attempt_at = '2099-01-01 10:00:00' WHERE status = 'failed'");
        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertStringContainsString('2099-01-01 10:00', $page->filter('[data-next-attempt]')->text());
    }

    public function testThePanelPageAndTheDoctorCountTheInboundsNotAttached(): void
    {
        $client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $page = $client->request('GET', '/admin/vpn-panel/'.$this->panel->getId());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('1/0', $page->filter('[data-inbound-attachments]')->attr('data-inbound-attachments'));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:panel:doctor'));
        $tester->execute(['panelId' => (string) $this->panel->getId()]);
        self::assertConsoleContains('attachments 1 failed (0 with retries used up), 0 being attached', $tester->getDisplay());

        $this->setting(MultiInboundSettings::ENABLED, 'false');
        $tester->execute(['panelId' => (string) $this->panel->getId()]);
        self::assertConsoleNotContains('attachments', $tester->getDisplay());
    }

    private function sell(Plan $plan, string $telegramId): VpnService
    {
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan');
        $order = $this->createOrder($this->createUser($telegramId), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        return $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
    }

    private function botDetail(?VpnService $service = null, string $telegramId = self::BUYER): string
    {
        $before = count($this->http->telegramTexts());
        $this->pressButton($telegramId, 'service_view:'.($service ?? $this->service)->getId());
        $texts = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_starts_with($t, 'سرویس #')));
        self::assertCount(1, $texts, 'the detail message (the access messages follow it)');

        return $texts[0];
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get('translator')->trans($key, [], 'admin', 'fa');
    }

    private function listQueries(KernelBrowser $client): int
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $this->em->clear();
        $counter->start();
        $client->request('GET', '/admin/vpn-service');
        $queries = $counter->stop();
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return $queries;
    }

    private function setting(string $key, string $value): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $db->insert('setting', ['key_name' => $key, 'value' => $value, 'type' => in_array($value, ['true', 'false'], true) ? 'boolean' : 'integer', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function admin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('m2b-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
