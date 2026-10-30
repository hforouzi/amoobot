<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\PlanAdditionalInbound;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The web admin for plans with several inbounds (docs/design/multi-inbound-plans.md 6, phase M1): the "Additional
 * inbounds" field, the plan list's "Inbounds" column and the inbound page's "Used by plans" exist only with the switch on;
 * with it off the pages make exactly the queries they made before (none touches the new tables) and a crafted POST is
 * refused. Ids the form did not offer are refused. An inbound (or a panel whose inbound) is an additional inbound of a
 * plan is not deleted; the admin is told which plans use it. The pages make a fixed number of queries per page.
 */
final class PlanAdminPagesTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $primary;
    private VpnInbound $vmess;
    private VpnInbound $trojan;
    private VpnInbound $elsewhere;
    private Plan $plan;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = $this->panel('modern');
        $this->primary = $this->inbound($this->panel, 1, 'reality', 'vless');
        $this->vmess = $this->inbound($this->panel, 2, 'ws', 'vmess');
        $this->trojan = $this->inbound($this->panel, 4, 'trojan', 'trojan');
        $this->elsewhere = $this->inbound($this->panel('other'), 2, 'elsewhere', 'vmess');
        $this->em->flush();
        $this->plan = $this->createPlan(100000, $this->primary)->setTitle('Gold');
        $this->em->flush();
        $this->client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
    }

    public function testWithTheSwitchOffThePagesAreAsBeforeAndTouchNoNewTable(): void
    {
        $queries = $this->sql(function (): void {
            $form = $this->client->request('GET', '/admin/plan/'.$this->plan->getId().'/edit');
            self::assertCount(0, $form->filter('[name="Plan[additionalInboundList][]"]'));
            $list = $this->client->request('GET', '/admin/plan');
            self::assertStringNotContainsString('data-not-sold', (string) $this->client->getResponse()->getContent());
            self::assertCount(0, $list->filter('th:contains("Inbounds"), th:contains("اینباندها")'));
            $this->client->request('GET', '/admin/plan/new');
            $this->client->request('GET', '/admin/vpn-inbound');
            self::assertStringNotContainsString('data-used-by', (string) $this->client->getResponse()->getContent());
        });

        self::assertNotEmpty($queries);
        self::assertSame([], array_values(array_filter($queries, static fn (string $q): bool => str_contains($q, 'plan_additional_inbound') || str_contains($q, 'vpn_service_inbound'))));
    }

    public function testWithTheSwitchOffACraftedPostIsRefused(): void
    {
        $form = $this->client->request('GET', '/admin/plan/'.$this->plan->getId().'/edit')->filter('form[name="Plan"]')->form();
        $values = $form->getPhpValues();
        $values['Plan']['title'] = 'Gold changed';
        $values['Plan']['additionalInboundList'] = [(string) $this->vmess->getId()];

        $this->client->request('POST', $form->getUri(), $values);

        self::assertSame(0, $this->rows());
        self::assertSame('Gold', $this->em->getConnection()->fetchOne('SELECT title FROM plan WHERE id = ?', [$this->plan->getId()]), 'nothing saved');
    }

    public function testWithTheSwitchOnTheFieldOffersTheRightInboundsAndSaves(): void
    {
        $this->switchOn();
        $inactive = $this->inbound($this->panel, 50, 'off', 'vmess')->setIsActive(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/plan/'.$this->plan->getId().'/edit');
        $offered = $crawler->filter('select[name="Plan[additionalInboundList][]"] option')->each(static fn (Crawler $o): int => (int) $o->attr('value'));
        self::assertSame([(int) $this->vmess->getId(), (int) $this->trojan->getId()], $offered, 'active inbounds of the primary\'s panel, not the primary, not inactive, not another panel');
        self::assertStringNotContainsString((string) $inactive->getTitle().' ·', (string) $this->client->getResponse()->getContent());

        $form = $crawler->filter('form[name="Plan"]')->form();
        $field = $form['Plan[additionalInboundList]'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->select([(string) $this->trojan->getId(), (string) $this->vmess->getId()]);
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirect(), $this->excerpt());
        self::assertSame([(int) $this->trojan->getId(), (int) $this->vmess->getId()], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT inbound_id FROM plan_additional_inbound ORDER BY sort_order')), 'in the order chosen');
        $this->client->followRedirect();
        self::assertMatchesRegularExpression('/not sold|فروخته نمی‌شود/u', $this->flashes(), 'the admin is told the plan is not sold yet');
    }

    /**
     * @return iterable<string, array{\Closure(self): list<mixed>}>
     */
    public static function craftedIds(): iterable
    {
        yield 'an inbound of another panel' => [static fn (self $t): array => [(string) $t->elsewhere->getId()]];
        yield 'the primary' => [static fn (self $t): array => [(string) $t->primary->getId()]];
        yield 'an id that does not exist' => [static fn (self $t): array => ['999999999']];
        yield 'not an integer' => [static fn (self $t): array => ['abc']];
        yield 'a trailing newline' => [static fn (self $t): array => [$t->vmess->getId()."\n"]];
        yield 'a nested array' => [static fn (self $t): array => [['x' => (string) $t->vmess->getId()]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('craftedIds')]
    public function testIdsTheFormDidNotOfferAreRefused(\Closure $ids): void
    {
        $this->switchOn();
        $form = $this->client->request('GET', '/admin/plan/'.$this->plan->getId().'/edit')->filter('form[name="Plan"]')->form();
        $values = $form->getPhpValues();
        $values['Plan']['additionalInboundList'] = $ids($this);

        $this->client->request('POST', $form->getUri(), $values);

        self::assertFalse($this->client->getResponse()->isRedirect(), 'the form is shown again with an error');
        self::assertSame(0, $this->rows());
    }

    public function testRepeatedAndManyIdsAreStoredOnce(): void
    {
        $this->switchOn();
        $form = $this->client->request('GET', '/admin/plan/'.$this->plan->getId().'/edit')->filter('form[name="Plan"]')->form();
        $values = $form->getPhpValues();
        $values['Plan']['additionalInboundList'] = [(string) $this->vmess->getId(), (string) $this->vmess->getId(), (string) $this->trojan->getId()];

        $this->client->request('POST', $form->getUri(), $values);

        self::assertTrue($this->client->getResponse()->isRedirect(), $this->excerpt());
        self::assertSame(2, $this->rows());
    }

    public function testThePlanListAndTheInboundPageShowTheUseInAFixedNumberOfQueries(): void
    {
        $this->switchOn();
        $this->addAdditional($this->plan, $this->vmess);
        $this->em->getConnection()->update('vpn_inbound', ['is_active' => 0], ['id' => $this->vmess->getId()]);

        $this->countQueries('/admin/plan');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-not-sold', $content);
        self::assertStringContainsString('data-inactive-inbound', $content);
        $this->countQueries('/admin/vpn-inbound');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-used-by="primary"', $content);
        self::assertStringContainsString('data-used-by="additional"', $content);

        // What the switch adds to a page is the same for 1 plan / 3 inbounds as for 7 plans / 9 inbounds.
        $few = $this->addedBySwitch();
        $this->em->clear();
        $primary = $this->em->find(VpnInbound::class, $this->primary->getId()) ?? self::fail('gone');
        $trojan = $this->em->find(VpnInbound::class, $this->trojan->getId()) ?? self::fail('gone');
        foreach (range(1, 6) as $n) {
            $this->addAdditional($this->createPlan(100000, $primary)->setTitle('Extra '.$n), $trojan);
            $this->inbound($primary->getPanel(), 10 + $n, 'more '.$n, 'trojan');
        }
        $this->em->flush();
        self::assertSame($few, $this->addedBySwitch(), 'no query per plan or per inbound');
        // The switch read and one usage query (PlanInboundUsage), whatever the number of rows.
        self::assertLessThanOrEqual(4, $few['/admin/plan']);
        self::assertLessThanOrEqual(4, $few['/admin/vpn-inbound']);
    }

    /**
     * @return array<string, int> page => queries with the switch on minus with it off
     */
    private function addedBySwitch(): array
    {
        $added = [];
        foreach (['/admin/plan', '/admin/vpn-inbound'] as $url) {
            $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [MultiInboundSettings::ENABLED]);
            $off = $this->countQueries($url);
            $this->switchOn();
            $added[$url] = $this->countQueries($url) - $off;
        }

        return $added;
    }

    public function testAnInboundOrPanelAPlanSellsAsAnAdditionalOneIsNotDeleted(): void
    {
        $this->addAdditional($this->plan, $this->vmess);

        $this->delete('/admin/vpn-inbound', (int) $this->vmess->getId());
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM vpn_inbound WHERE id = ?', [$this->vmess->getId()]));
        self::assertStringContainsString('#'.$this->plan->getId().' Gold', $this->flashes());

        $this->delete('/admin/vpn-panel', (int) $this->panel->getId());
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM vpn_panel WHERE id = ?', [$this->panel->getId()]));
        self::assertStringContainsString('#'.$this->plan->getId().' Gold', $this->flashes());

        // An inbound no plan sells as an additional one is deleted as before.
        $this->delete('/admin/vpn-inbound', (int) $this->trojan->getId());
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM vpn_inbound WHERE id = ?', [$this->trojan->getId()]));
    }

    public function testWithoutPlansManageThePlanFormIsClosed(): void
    {
        $this->switchOn();
        $support = $this->admin(AdminUser::ROLE_SUPPORT);

        $support->request('GET', '/admin/plan/'.$this->plan->getId().'/edit');

        self::assertContains($support->getResponse()->getStatusCode(), [302, 403]);
    }

    private function delete(string $base, int $id): void
    {
        $index = $this->client->request('GET', $base);
        $token = (string) $index->filter('input[name="token"]')->attr('value');
        $this->client->request('POST', $base.'/'.$id.'/delete', ['token' => $token]);
        while ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
        $this->em->clear();
    }

    private function flashes(): string
    {
        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

    private function countQueries(string $url): int
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $counter->start();
        $this->client->request('GET', $url);
        $count = $counter->stop();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $count;
    }

    /**
     * Every statement the callback sends to MariaDB (general log), so the switch-off pages can be checked for the new
     * tables.
     *
     * @return list<string>
     */
    private function sql(\Closure $callback): array
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("SET GLOBAL log_output='TABLE'");
        $connection->executeStatement('TRUNCATE mysql.general_log');
        $connection->executeStatement('SET GLOBAL general_log=1');
        try {
            $callback();
        } finally {
            $connection->executeStatement('SET GLOBAL general_log=0');
        }

        return array_map('strval', $connection->fetchFirstColumn("SELECT CONVERT(argument USING utf8mb4) FROM mysql.general_log WHERE command_type IN ('Query', 'Execute', 'Prepare')"));
    }

    private function addAdditional(Plan $plan, VpnInbound $inbound): void
    {
        $this->em->persist(new PlanAdditionalInbound($plan, $inbound, 1, new \DateTimeImmutable()));
        $this->em->flush();
    }

    private function rows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound');
    }

    private function excerpt(): string
    {
        return mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1500);
    }

    private function switchOn(): void
    {
        $this->em->getConnection()->insert('setting', ['key_name' => MultiInboundSettings::ENABLED, 'value' => 'true', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function panel(string $title): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle($title)->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setIntegrationMode(SanaeiIntegrationMode::MODERN);
        $this->em->persist($panel);

        return $panel;
    }

    private function inbound(VpnPanel $panel, int $remote, string $title, string $protocol): VpnInbound
    {
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remote)->setTitle($title)->setProtocol($protocol)->setPort(10000 + $remote);
        $this->em->persist($inbound);

        return $inbound;
    }

    private function admin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('m1-pages-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
