<?php

declare(strict_types=1);

namespace App\Tests\Shop\PlanInboundChange;

use App\Entity\TrialPlan;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * T2 of docs/design/plan-inbound-change.md 8.2 (P1), Mini App admin: the plan form's live check warns, before saving,
 * that another inbound leaves the existing services where they are (warning kind existing_services_other_inbound,
 * text 3.3 (a)); the edit page lists (b); the trial form's check, the service page (d) and the regenerate result (e)
 * say the same, in Persian and English. The check writes nothing and calls no panel; permissions are as before; no
 * customer or link is shown.
 */
final class PlanInboundNoticesMiniAppTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;
    use PlanInboundChangeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
        $this->setUpInboundChange();
    }

    public function testTheLiveCheckWarnsBeforeSavingAnotherInboundAndSavesNothing(): void
    {
        $this->planService($this->gold, $this->de);
        $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED);
        $this->planService($this->gold, $this->de, VpnServiceStatus::DELETED);
        $session = $this->authenticate(self::ADMIN);
        $before = $this->checksums();

        $answer = $this->appPostJson('/app/api/admin/plans/check?lang=en', $session, $this->body(['inbound' => (string) $this->nl->getId()]));

        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertSame([$this->notice('plan_inbound_drift.plan_saved', ['%plan%' => 'Gold', '%new%' => 'NL-2', '%count%' => 2, '%breakdown%' => '2 on "DE-1"'])], $answer['warnings']);
        $fa = $this->appPostJson('/app/api/admin/plans/check', $session, $this->body(['inbound' => (string) $this->nl->getId()]));
        self::assertSame([$this->notice('plan_inbound_drift.plan_saved', ['%plan%' => 'Gold', '%new%' => 'NL-2', '%count%' => 2, '%breakdown%' => '2 روی «DE-1»'], 'fa')], $fa['warnings']);
        self::assertSame($before, $this->checksums(), 'the check writes nothing');
        self::assertSame([], $this->fake->requests, 'and calls no panel');

        $same = $this->appPostJson('/app/api/admin/plans/check?lang=en', $session, $this->body(['inbound' => (string) $this->de->getId()]));
        self::assertSame([], $same['warnings'], 'the stored inbound: nothing to say');
        $new = $this->appPostJson('/app/api/admin/plans/check?lang=en', $session, ['title' => 'New', 'durationDays' => '30', 'price' => '100000', 'inbound' => (string) $this->nl->getId()]);
        self::assertSame([], $new['warnings'], 'a new plan has no services');
    }

    public function testNoWarningWhenEveryServiceIsAlreadyOnTheSubmittedInbound(): void
    {
        $this->planService($this->gold, $this->nl);
        $session = $this->authenticate(self::ADMIN);

        $answer = $this->appPostJson('/app/api/admin/plans/check?lang=en', $session, $this->body(['inbound' => (string) $this->nl->getId()]));

        self::assertSame([], $answer['warnings']);
        $page = new Crawler($this->appGet('/app/admin/plans/'.$this->gold->getId().'?lang=en', $session));
        self::assertCount(1, $page->filter('[data-warning-list] li'), 'the edit page compares with the stored inbound DE-1: the service on NL-2 is one line (b)');
    }

    public function testTheEditPageListsTheServicesNotOnThePlansInbound(): void
    {
        $this->planService($this->gold, $this->nl);
        $this->planService($this->gold, $this->nl);
        $session = $this->authenticate(self::ADMIN);

        $page = new Crawler($this->appGet('/app/admin/plans/'.$this->gold->getId().'?lang=en', $session));

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$this->notice('plan_inbound_drift.form', ['%count%' => 2, '%breakdown%' => '2 on "NL-2"'])], $page->filter('[data-warning-list] li')->each(static fn (Crawler $li): string => $li->text()));
        self::assertNull($page->filter('[data-warnings]')->attr('hidden'));
        self::assertSame('⚠ Check before saving', trim($page->filter('[data-warnings] h2')->text()));
    }

    public function testAPriceConfirmationAlsoCarriesTheWarning(): void
    {
        $this->planService($this->gold, $this->de);
        $session = $this->authenticate(self::ADMIN);

        $first = $this->appPostJson('/app/api/admin/plans/'.$this->gold->getId().'?lang=en', $session, $this->body(['inbound' => (string) $this->nl->getId(), 'price' => '200000']), self::newKey());

        self::assertSame(428, $this->httpStatus(), 'confirmed as before: a price changed');
        self::assertStringContainsString('1 existing service(s) of this plan are on another inbound (1 on "DE-1")', $first['confirm']['message']);

        // Another inbound without a price change saves at once, as before.
        $this->appPostJson('/app/api/admin/plans/'.$this->gold->getId().'?lang=en', $session, $this->body(['inbound' => (string) $this->nl->getId()]), self::newKey());
        self::assertSame(200, $this->httpStatus());
        self::assertSame((int) $this->nl->getId(), (int) $this->row('plan', (int) $this->gold->getId())['inbound_id']);
        self::assertSame((int) $this->de->getId(), (int) $this->em->getConnection()->fetchOne('SELECT inbound_id FROM vpn_service WHERE order_id IS NOT NULL ORDER BY id DESC LIMIT 1'), 'the service is not moved');
    }

    public function testTheTrialFormCheckWarnsForTheTrialsServices(): void
    {
        $backing = $this->createPlan(0, $this->de)->setTitle('[Trial] Day')->setIsActive(false);
        $trial = (new TrialPlan())->setTitle('Day')->setInbound($this->de)->setBackingPlan($backing);
        $this->em->persist($trial);
        $this->em->flush();
        $this->planService($backing, $this->de, VpnServiceStatus::ACTIVE, 0, OrderType::TRIAL);
        $session = $this->authenticate(self::ADMIN);
        $body = ['id' => (string) $trial->getId(), 'title' => 'Day', 'durationDays' => '1', 'maxClaimsPerUser' => '1', 'sortOrder' => '0', 'isActive' => '1'];

        $answer = $this->appPostJson('/app/api/admin/trial-plans/check?lang=en', $session, $body + ['inbound' => (string) $this->nl->getId()]);

        self::assertSame([$this->notice('plan_inbound_drift.trial_plan_saved', ['%plan%' => 'Day', '%new%' => 'NL-2', '%count%' => 1, '%breakdown%' => '1 on "DE-1"'])], $answer['warnings']);
        self::assertSame([], $this->appPostJson('/app/api/admin/trial-plans/check?lang=en', $session, $body + ['inbound' => (string) $this->de->getId()])['warnings']);
        $page = new Crawler($this->appGet('/app/admin/trial-plans/'.$trial->getId(), $session));
        self::assertCount(1, $page->filter('[data-warnings][hidden] [data-warning-list]'), 'the box the live check fills, hidden until then');
    }

    public function testTheServicePageAndRegenerateSayTheServiceWasNotMovedWithoutNamingTheCustomer(): void
    {
        $service = $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 1);
        $this->sellOn($this->gold, $this->nl);
        $session = $this->authenticate(self::SUPER);

        $html = $this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $session);
        $page = new Crawler($html);
        self::assertSame('⚠ '.$this->notice('plan_inbound_drift.service', ['%current%' => 'DE-1', '%planInbound%' => 'NL-2']), trim($page->filter('[data-inbound-notice]')->text()));
        $fa = new Crawler($this->appGet('/app/admin/services/'.$service->getId(), $session));
        self::assertSame('⚠ '.$this->notice('plan_inbound_drift.service', ['%current%' => 'DE-1', '%planInbound%' => 'NL-2'], 'fa'), trim($fa->filter('[data-inbound-notice]')->text()));
        self::assertStringNotContainsString('Kaveh', $page->filter('[data-inbound-notice]')->text());

        $answer = $this->confirmedPost('/app/api/admin/services/'.$service->getId().'/regenerate-config?lang=en', $session);

        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertStringEndsWith(' '.$this->notice('plan_inbound_drift.regenerate', ['%current%' => 'DE-1', '%planInbound%' => 'NL-2']), $answer['message']);
        self::assertStringNotContainsString('vless://', (string) json_encode($answer));
        self::assertSame((int) $this->de->getId(), (int) $this->row('vpn_service', (int) $service->getId())['inbound_id'], 'not moved');
    }

    public function testNoNoticeForAServiceOnItsPlansInbound(): void
    {
        $service = $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 1);
        $session = $this->authenticate(self::SUPER);

        $page = new Crawler($this->appGet('/app/admin/services/'.$service->getId().'?lang=en', $session));
        self::assertCount(0, $page->filter('[data-inbound-notice]'));
        $answer = $this->confirmedPost('/app/api/admin/services/'.$service->getId().'/regenerate-config?lang=en', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('still serves', $answer['message'], 'the message as before');
    }

    public function testPermissionsAreUnchanged(): void
    {
        $this->planService($this->gold, $this->de);
        $support = $this->authenticate(self::SUPPORT);

        $this->appPostJson('/app/api/admin/plans/check?lang=en', $support, $this->body(['inbound' => (string) $this->nl->getId()]));
        self::assertSame(403, $this->httpStatus(), 'support has no catalog, as before');
        $admin = $this->authenticate(self::ADMIN);
        $this->appPostJson('/app/api/admin/services/'.$this->planService($this->gold, $this->de)->getId().'/regenerate-config', $admin, [], self::newKey());
        self::assertSame(403, $this->httpStatus(), 'ADMIN has no PANELS_MANAGE, as before');
    }

    /**
     * The plan's form as stored, with these fields changed.
     *
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function body(array $changes): array
    {
        return $changes + ['id' => (string) $this->gold->getId(), 'title' => 'Gold', 'durationDays' => '30', 'trafficGb' => '50', 'price' => '100000', 'isActive' => '1', 'inbound' => (string) $this->de->getId()];
    }
}
