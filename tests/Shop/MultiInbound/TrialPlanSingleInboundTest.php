<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\Catalog\TrialBackingPlanLocked;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\TrialAccountService;
use App\Shop\Application\TrialClaimResult;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * M2d B5: trial plans keep one inbound. A trial's hidden backing plan (TrialPlan::getBackingPlan(), an inactive "[Trial]"
 * plan row) accepts no additional inbounds on any save (the web plan form and PlanAdminService::save(), which the bot
 * and the Mini App use), and a trial claim goes through the sale rule (PlanSaleRule::acceptsTrial()): a backing plan
 * that has additional inbounds anyway (data written before M2d) is not provisioned.
 *
 * Since docs/design/trial-backing-plans.md the sales area does not save a backing plan at all (TrialBackingPlanLocked,
 * its form redirects to the trial plan); the inbound rule stays behind it (PlanInboundsValidator).
 */
final class TrialPlanSingleInboundTest extends IntegrationTestCase
{
    private VpnInbound $primary;
    private VpnInbound $extra;
    private TrialPlan $trial;
    private Plan $backing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        $this->primary = $this->createTestInbound();
        $this->extra = (new VpnInbound())->setPanel($this->primary->getPanel())->setRemoteInboundId('2')->setTitle('extra')->setProtocol('vmess');
        $this->em->persist($this->extra);
        $this->primary->setProtocol('vless');
        $this->backing = $this->createPlan(0, $this->primary)->setTitle('[Trial] test')->setIsActive(false);
        $this->trial = (new TrialPlan())->setTitle('trial')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->primary)->setMaxClaimsPerUser(1)->setBackingPlan($this->backing);
        $this->em->persist($this->trial);
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        self::getContainer()->get('translator')->setLocale('en');
    }

    public function testTheBotAndTheMiniAppCannotGiveTheBackingPlanAnAdditionalInbound(): void
    {
        [, $extra] = $this->onAModernPanel();
        $plan = $this->em->find(Plan::class, $this->backing->getId()) ?? self::fail('plan');
        $plan->setAdditionalInbounds([$this->em->find(VpnInbound::class, $extra->getId()) ?? self::fail('inbound')]);

        try {
            self::getContainer()->get(PlanAdminService::class)->save($plan);
            self::fail('a trial plan was given a second inbound');
        } catch (TrialBackingPlanLocked $e) {
            self::assertSame($this->backing->getId(), $e->planId);
        }
        // The rule itself still refuses it (a save that would reach validation).
        self::assertStringContainsString('trial plan keeps one inbound', implode(' ', self::getContainer()->get(PlanAdminService::class)->violations($plan)['additionalInboundList'] ?? []));
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound'));
    }

    public function testTheWebPlanFormCannotGiveTheBackingPlanAnAdditionalInbound(): void
    {
        [, $extra] = $this->onAModernPanel();
        $client = $this->admin();
        $client->request('GET', '/admin/plan/'.$this->backing->getId().'/edit');
        self::assertTrue($client->getResponse()->isRedirect(), 'no form: the trial plan manages it');

        // A crafted post of the form: refused the same way.
        $client->request('POST', '/admin/plan/'.$this->backing->getId().'/edit', ['Plan' => ['title' => '[Trial] test', 'durationDays' => '1', 'price' => '0', 'inbound' => (string) $this->primary->getId(), 'additionalInboundList' => [(string) $extra->getId()]]]);

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound'));
    }

    public function testATrialWhoseBackingPlanHasAnAdditionalInboundIsNotProvisioned(): void
    {
        // Written before M2d, around the form.
        $this->em->getConnection()->insert('plan_additional_inbound', ['plan_id' => $this->backing->getId(), 'inbound_id' => $this->extra->getId(), 'sort_order' => 0, 'created_at' => '2026-10-01 00:00:00']);
        $this->em->clear();
        $account = $this->createUser()->getTelegramAccount() ?? self::fail('account');
        $trial = $this->em->find(TrialPlan::class, $this->trial->getId()) ?? self::fail('trial');

        $result = self::getContainer()->get(TrialAccountService::class)->claim($account, $trial);

        self::assertSame(TrialClaimResult::STATUS_INACTIVE, $result->status);
        self::assertSame(0, $this->driver()->createCalls, 'nothing created on the panel');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM `order` WHERE type = 'trial'"));
    }

    public function testATrialWithOneInboundIsProvisionedAsBefore(): void
    {
        $account = $this->createUser()->getTelegramAccount() ?? self::fail('account');

        $result = self::getContainer()->get(TrialAccountService::class)->claim($account, $this->trial);

        self::assertSame(TrialClaimResult::STATUS_SUCCESS, $result->status);
        self::assertSame(1, $this->driver()->createCalls);
    }

    /**
     * The backing plan's primary on a modern 3x-ui panel, so a trial is the only reason left to refuse an additional one.
     *
     * @return array{VpnInbound, VpnInbound} primary, additional candidate
     */
    private function onAModernPanel(): array
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl('https://panel.example.test:2053/secret/')->setPlainApiToken('t')
            ->setIntegrationMode(SanaeiIntegrationMode::MODERN);
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $extra = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        foreach ([$panel, $primary, $extra] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE plan SET inbound_id = ? WHERE id = ?', [$primary->getId(), $this->backing->getId()]);
        $this->em->clear();

        return [$primary, $extra];
    }

    private function admin(): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('b5-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
