<?php

declare(strict_types=1);

namespace App\Tests\Shop\PlanInboundChange;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * T2 of docs/design/plan-inbound-change.md 8.2 (P1), web panel and commands: after a plan's inbound changes, its
 * existing services stay on the old one and every surface of design 3.2 says so with the texts of 3.3: the plan form
 * after save (a) and its help (b), the list's badge, the panel setup wizard (c), the trial plan form, the service page
 * (d) and the regenerate result (e), web and both commands. Visibility only: saving writes exactly what it wrote
 * before, nothing else is written, no panel is called; titles are escaped; nothing shows without services elsewhere.
 */
final class PlanInboundNoticesWebTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;
    use PlanInboundChangeFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInboundChange();
        $this->client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $this->client->request('GET', '/admin/locale/en');
    }

    public function testSavingAnotherInboundSaysTheExistingServicesStayAndTheFormKeepsSayingIt(): void
    {
        $this->planService($this->gold, $this->de);
        $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED);
        $this->planService($this->gold, $this->de, VpnServiceStatus::DELETED);
        $this->planService($this->gold, $this->nl);

        $this->saveForm($this->gold, ['inbound' => (string) $this->nl->getId()]);

        $expected = $this->notice('plan_inbound_drift.plan_saved_apply', ['%plan%' => 'Gold', '%new%' => 'NL-2', '%count%' => 2, '%breakdown%' => '2 on "DE-1"']);
        self::assertStringContainsString($expected, $this->page(), 'text (a): the two services on DE-1; the deleted one is not counted');
        self::assertStringContainsString('2 existing service(s) of this plan are on another inbound (2 on "DE-1")', $this->page());
        $form = $this->client->request('GET', '/admin/plan/'.$this->gold->getId().'/edit');
        self::assertSame(
            $this->notice('plan_inbound_drift.form_apply', ['%count%' => 2, '%breakdown%' => '2 on "DE-1"']),
            html_entity_decode($form->filter('[data-inbound-drift]')->text()),
            'text (b) under the inbound field',
        );

        // Saving again without changing an inbound: no new notice (the form's help stays).
        $this->saveForm($this->gold, ['description' => 'Fast']);
        self::assertStringNotContainsString('now uses inbound', $this->page());
    }

    public function testNoNoticeWhenEveryServiceIsOnTheNewInbound(): void
    {
        $this->planService($this->gold, $this->nl);
        $this->planService($this->gold, $this->de, VpnServiceStatus::DELETED);

        $this->saveForm($this->gold, ['inbound' => (string) $this->nl->getId()]);

        self::assertStringNotContainsString('now uses inbound', $this->page());
        $form = $this->client->request('GET', '/admin/plan/'.$this->gold->getId().'/edit');
        self::assertCount(0, $form->filter('[data-inbound-drift]'));
        $list = $this->client->request('GET', '/admin/plan');
        self::assertCount(0, $list->filter('[data-inbound-drift]'));
        self::assertStringNotContainsString('Inbound notice', (string) $this->client->getResponse()->getContent(), 'no column at all');
    }

    public function testSavingWritesExactlyWhatItWroteBeforeAndCallsNoPanel(): void
    {
        // Two identical plans, the same edit: one with services left on DE-1 (it gets the notice), one without.
        $twin = $this->createPlan(100000, $this->de)->setTitle('Gold');
        $this->em->flush();
        foreach ([VpnServiceStatus::ACTIVE, VpnServiceStatus::SUSPENDED] as $status) {
            $this->planService($this->gold, $this->de, $status, 0);
        }
        $services = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM vpn_service ORDER BY id');
        $orders = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM `order` ORDER BY id');

        foreach ([$this->gold, $twin] as $plan) {
            $this->saveForm($plan, ['inbound' => (string) $this->nl->getId(), 'price' => '120000']);
        }

        $rows = array_map(fn (Plan $p): array => array_diff_key($this->row('plan', (int) $p->getId()), ['id' => 1, 'created_at' => 1, 'updated_at' => 1]), [$this->gold, $twin]);
        self::assertSame($rows[1], $rows[0], 'the plan row is written the same with or without the notice');
        self::assertSame((int) $this->nl->getId(), (int) $rows[0]['inbound_id']);
        self::assertSame(120000, (int) $rows[0]['price']);
        self::assertSame($services, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM vpn_service ORDER BY id'), 'no service is touched (none moved)');
        self::assertSame($orders, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM `order` ORDER BY id'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound'));
        self::assertSame([], $this->fake->requests, 'no panel call');
    }

    public function testTheListBadgeIsOneQueryForThePage(): void
    {
        $plans = [$this->gold];
        for ($i = 0; $i < 4; ++$i) {
            $plans[] = $this->createPlan(100000, $this->de)->setTitle('Plan '.$i);
        }
        $this->em->flush();
        $counts = [];
        foreach ([1, 3] as $round) {
            foreach ($plans as $plan) {
                $this->planService($plan, $this->de);
            }
            foreach ($plans as $plan) {
                $this->sellOn($plan, $this->nl);
            }
            $counter = self::getContainer()->get(QueryCounter::class);
            $counter->start();
            $list = $this->client->request('GET', '/admin/plan');
            $counts[] = $counter->stop();
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $badges = $list->filter('[data-inbound-drift]');
            self::assertCount(5, $badges);
            self::assertSame((string) (1 === $round ? 1 : 2), $badges->first()->attr('data-inbound-drift'));
            self::assertSame('⚠ '.(1 === $round ? 1 : 2).' on old inbound', trim($badges->first()->text()));
        }
        self::assertSame($counts[0], $counts[1], 'more services per plan: no more queries');
    }

    public function testTitlesAreEscapedInEveryNotice(): void
    {
        $this->gold->setTitle('<b>Gold</b>');
        $this->de->setTitle('<img src=x onerror=alert(1)>');
        $this->nl->setTitle('NL "2" & <i>');
        $this->em->flush();
        $service = $this->planService($this->gold, $this->de);

        $this->saveForm($this->gold, ['inbound' => (string) $this->nl->getId()]);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<b>Gold</b>', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);

        $this->client->request('GET', '/admin/plan/'.$this->gold->getId().'/edit');
        self::assertStringNotContainsString('<img src=x', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/admin/vpn-service/'.$service->getId());
        self::assertStringNotContainsString('<img src=x', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $this->client->getResponse()->getContent());
    }

    public function testTheWizardSaysHowManyServicesStayAndStillSavesAsBefore(): void
    {
        $this->planService($this->gold, $this->de);
        $this->planService($this->gold, $this->de);
        $other = $this->createPlan(100000, $this->de)->setTitle('Silver');
        $this->em->flush();
        $this->planService($other, $this->de);

        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$this->modernPanel->getId().'/plans');
        $form = $crawler->filter('form')->form();
        foreach ([$this->gold, $other] as $plan) {
            $field = $form['plan['.$plan->getId().']'];
            self::assertInstanceOf(ChoiceFormField::class, $field);
            $field->select((string) $this->nl->getId());
        }
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect());

        // The wizard's steps show no flash; they wait in the session for the next admin page.
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(Session::class, $session);
        $warnings = array_map(static fn (TranslatableMessage $m): string => $m->trans(self::getContainer()->get('translator'), 'en'), $session->getFlashBag()->peek('warning'));
        self::assertSame([$this->notice('plan_inbound_drift.wizard', ['%plans%' => 2, '%count%' => 3])], $warnings);
        $this->em->clear();
        self::assertSame($this->nl->getId(), $this->em->find(Plan::class, $this->gold->getId())?->getInbound()?->getId(), 'saved directly, as before (the validator is not run here: a separate PR)');
        self::assertSame([], $this->fake->requests);
    }

    public function testTheTrialPlanFormSaysTheTrialsServicesStay(): void
    {
        $backing = $this->createPlan(0, $this->de)->setTitle('[Trial] Day')->setIsActive(false);
        $trial = (new TrialPlan())->setTitle('Day')->setInbound($this->de)->setBackingPlan($backing);
        $this->em->persist($trial);
        $this->em->flush();
        $this->planService($backing, $this->de, VpnServiceStatus::ACTIVE, 0, OrderType::TRIAL);

        $crawler = $this->client->request('GET', '/admin/trial-plan/'.$trial->getId().'/edit');
        $form = $crawler->filter('form[name="TrialPlan"]')->form();
        $field = $form['TrialPlan[inbound]'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->select((string) $this->nl->getId());
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString($this->notice('plan_inbound_drift.trial_plan_saved', ['%plan%' => 'Day', '%new%' => 'NL-2', '%count%' => 1, '%breakdown%' => '1 on "DE-1"']), $this->page());
        self::assertSame((int) $this->de->getId(), (int) $this->row('plan', (int) $backing->getId())['inbound_id'], 'the backing plan is still copied only at the next claim');
    }

    public function testTheServicePageAndRegenerateSayTheServiceWasNotMoved(): void
    {
        $service = $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 1);
        $onNew = $this->planService($this->gold, $this->nl, VpnServiceStatus::ACTIVE, 2);
        $this->sellOn($this->gold, $this->nl);
        $before = $this->checksums();

        $page = $this->client->request('GET', '/admin/vpn-service/'.$service->getId());
        self::assertSame($this->notice('plan_inbound_drift.service_apply', ['%current%' => 'DE-1', '%planInbound%' => 'NL-2']), trim(html_entity_decode($page->filter('[data-inbound-notice]')->text()), '⚠ '));
        $this->client->request('GET', '/admin/vpn-service/'.$onNew->getId());
        self::assertStringNotContainsString('data-inbound-notice', (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->checksums(), 'pages write nothing');
        self::assertSame([], $this->fake->requests, 'and call no panel');

        $this->client->request('GET', '/admin/vpn-service/'.$service->getId().'/regenerate-config');
        $this->client->followRedirect();
        $suffix = $this->notice('plan_inbound_drift.regenerate', ['%current%' => 'DE-1', '%planInbound%' => 'NL-2']);
        self::assertStringContainsString('refreshed from the panel', $this->page());
        self::assertStringContainsString($suffix, $this->page(), 'text (e) after the existing message');
        self::assertSame((int) $this->de->getId(), (int) $this->row('vpn_service', (int) $service->getId())['inbound_id'], 'not moved');

        $this->client->request('GET', '/admin/vpn-service/'.$onNew->getId().'/regenerate-config');
        $this->client->followRedirect();
        self::assertStringContainsString('refreshed from the panel', $this->page());
        self::assertStringNotContainsString('still serves', $this->page(), 'on the plan\'s inbound: the message as before');
    }

    public function testBothRegenerateCommandsSayTheServiceWasNotMoved(): void
    {
        $service = $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 1);
        $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 2);
        $this->sellOn($this->gold, $this->nl);
        $suffix = $this->notice('plan_inbound_drift.regenerate', ['%current%' => 'DE-1', '%planInbound%' => 'NL-2']);

        $one = $this->command('app:service:regenerate-config', ['serviceId' => (string) $service->getId()]);
        self::assertConsoleContains('! '.$suffix, $one);
        $bulk = $this->command('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->de->getId()]);
        self::assertSame(2, self::consoleCount($bulk, $suffix), 'one line per service, read with one query per batch');

        $this->sellOn($this->gold, $this->de);
        self::assertConsoleNotContains('still serves', $this->command('app:service:regenerate-config', ['serviceId' => (string) $service->getId()]));
    }

    public function testPermissionsAreUnchanged(): void
    {
        $service = $this->planService($this->gold, $this->de);
        $this->sellOn($this->gold, $this->nl);
        $support = $this->admin(AdminUser::ROLE_SUPPORT);

        $support->request('GET', '/admin/plan/'.$this->gold->getId().'/edit');
        self::assertContains($support->getResponse()->getStatusCode(), [302, 403], 'support cannot edit plans, notice or not');
        $support->request('GET', '/admin/vpn-service/'.$service->getId().'/regenerate-config');
        self::assertContains($support->getResponse()->getStatusCode(), [302, 403]);
        self::assertSame((int) $this->de->getId(), (int) $this->row('vpn_service', (int) $service->getId())['inbound_id']);
    }

    /**
     * Submits the plan's edit form with these fields changed and follows the redirect.
     *
     * @param array<string, string> $fields
     */
    private function saveForm(Plan $plan, array $fields): void
    {
        $form = $this->client->request('GET', '/admin/plan/'.$plan->getId().'/edit')->filter('form[name="Plan"]')->form();
        foreach ($fields as $name => $value) {
            $field = $form['Plan['.$name.']'];
            $field instanceof ChoiceFormField ? $field->select($value) : $field->setValue($value);
        }
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1500));
        $this->client->followRedirect();
        $this->em->clear();
    }

    private function page(): string
    {
        return html_entity_decode((string) $this->client->getResponse()->getContent(), ENT_QUOTES);
    }

    /**
     * @param array<string, string> $input
     */
    private function command(string $name, array $input): string
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $tester->execute($input);

        return $tester->getDisplay();
    }

    private function admin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('p1-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
