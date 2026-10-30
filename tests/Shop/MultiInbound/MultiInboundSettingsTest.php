<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Admin\Application\SettingsAdministration;
use App\Entity\AdminUser;
use App\Entity\PlanAdditionalInbound;
use App\Entity\VpnInbound;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SettingsFormCases;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * plans.multi_inbound_enabled (docs/design/multi-inbound-plans.md 6): off by default, on the web settings page's "plans"
 * tab (SETTINGS_MANAGE), saved and audited like the other switches. Turning it off while plans keep additional inbounds
 * names them (they stay unsold). The Mini App's sales form, which saves every sales key, never touches it.
 */
final class MultiInboundSettingsTest extends IntegrationTestCase
{
    public function testOffByDefaultAndSavedOnTheirOwnTab(): void
    {
        self::assertFalse(self::getContainer()->get(MultiInboundSettings::class)->enabled());
        $client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);

        $crawler = $client->request('GET', '/admin/settings?tab=plans');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $checkbox = $crawler->filter('input[name="plans_multi_inbound_enabled"]');
        self::assertCount(1, $checkbox);
        self::assertNull($checkbox->attr('checked'));

        $audits = $this->planAudits();
        $form = $crawler->filter('form.admin-settings-form')->form();
        $this->checkbox($form, 'plans_multi_inbound_enabled')->tick();
        $client->submit($form);
        self::assertSame('true', $this->setting());
        self::assertSame($audits + 1, $this->planAudits(), 'the change is audited');

        $crawler = $client->request('GET', '/admin/settings?tab=plans');
        $form = $crawler->filter('form.admin-settings-form')->form();
        $this->checkbox($form, 'plans_multi_inbound_enabled')->untick();
        $client->submit($form);
        self::assertSame('false', $this->setting());
    }

    public function testTurningItOffNamesThePlansThatStayUnsold(): void
    {
        $primary = $this->createTestInbound();
        $other = (new VpnInbound())->setPanel($primary->getPanel())->setRemoteInboundId('2')->setTitle('second');
        $this->em->persist($other);
        $plan = $this->createPlan(100000, $primary)->setTitle('Gold multi');
        $this->em->persist(new PlanAdditionalInbound($plan, $other, 1, new \DateTimeImmutable()));
        $this->em->flush();
        $client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);

        $client->request('POST', '/admin/settings?tab=plans', []);

        self::assertSame('false', $this->setting());
        self::assertStringContainsString('#'.$plan->getId().' Gold multi', html_entity_decode((string) $client->getResponse()->getContent()));
    }

    public function testTheSalesFormNeverTouchesIt(): void
    {
        $settings = self::getContainer()->get(SettingsAdministration::class);
        $settings->savePlans(['plans_multi_inbound_enabled' => 'on']);

        self::assertNull($settings->saveSales(SettingsFormCases::input('sales', [])));

        self::assertSame('true', $this->setting(), 'the Mini App and web sales forms save their own keys only');
        self::assertNotContains(MultiInboundSettings::ENABLED, SettingsAdministration::SALES_KEYS + SettingsAdministration::AUTOMATION_KEYS);
    }

    public function testTheScheduledRetrySettingsHaveDefaultsAndAreSavedOnThePlansTab(): void
    {
        $settings = self::getContainer()->get(MultiInboundSettings::class);
        self::assertTrue($settings->attachRetryEnabled());
        self::assertFalse($settings->scheduledRetryOn(), 'never without the switch');
        self::assertSame([12, 5], [$settings->maxAttempts(), $settings->retryDelayMinutes()]);
        $client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);

        $crawler = $client->request('GET', '/admin/settings?tab=plans');
        self::assertNotNull($crawler->filter('input[name="plans_attach_retry_enabled"]')->attr('checked'));
        self::assertSame('12', $crawler->filter('input[name="plans_attach_max_attempts"]')->attr('value'));
        self::assertSame('5', $crawler->filter('input[name="plans_attach_retry_delay_minutes"]')->attr('value'));

        $client->request('POST', '/admin/settings?tab=plans', ['plans_multi_inbound_enabled' => 'on', 'plans_attach_max_attempts' => '3', 'plans_attach_retry_delay_minutes' => '30']);

        self::assertFalse($settings->attachRetryEnabled(), 'an unticked checkbox turns it off');
        self::assertFalse($settings->scheduledRetryOn());
        self::assertSame([3, 30], [$settings->maxAttempts(), $settings->retryDelayMinutes()]);
    }

    public function testOutOfRangeRetrySettingsAreRefusedAndNothingIsSaved(): void
    {
        $settings = self::getContainer()->get(SettingsAdministration::class);
        foreach ([['plans_attach_max_attempts' => '0'], ['plans_attach_max_attempts' => '101'], ['plans_attach_max_attempts' => '2.5'],
            ['plans_attach_retry_delay_minutes' => '0'], ['plans_attach_retry_delay_minutes' => '1441'], ['plans_attach_retry_delay_minutes' => 'x']] as $bad) {
            $problem = $settings->savePlans(['plans_multi_inbound_enabled' => 'on'] + $bad);

            self::assertSame(array_key_first($bad), $problem['field'] ?? null, (string) json_encode($bad));
        }
        self::assertNull($this->setting(), 'nothing was saved, the switch included');
    }

    public function testTheBackOffDoublesUpToSixHoursAndStopsAtTheLimit(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 12:00:00');
        $delays = [];
        for ($attempts = 1; $attempts <= 12; ++$attempts) {
            $next = MultiInboundSettings::backoff($attempts, 12, 5, $now);
            $delays[] = null === $next ? null : intdiv($next->getTimestamp() - $now->getTimestamp(), 60);
        }

        self::assertSame([5, 10, 20, 40, 80, 160, 320, 360, 360, 360, 360, null], $delays, 'minutes after the n-th failure; given up after 12');
        self::assertSame(600, intdiv((int) MultiInboundSettings::backoff(3, 12, 600, $now)?->getTimestamp() - $now->getTimestamp(), 60), 'a first delay above 6 h is not shortened');
        self::assertNull(MultiInboundSettings::backoff(1, 1, 5, $now), 'one attempt in all: the one at the sale');
    }

    public function testAnAdminWithoutSettingsManageCannotChangeIt(): void
    {
        $client = $this->admin(AdminUser::ROLE_SUPPORT);

        $client->request('POST', '/admin/settings?tab=plans', ['plans_multi_inbound_enabled' => 'on']);

        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        self::assertNull($this->setting());
    }

    private function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function planAudits(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'settings.update' AND details LIKE '%\"page\":\"plans\"%'");
    }

    private function setting(): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [MultiInboundSettings::ENABLED]);

        return false === $value ? null : (string) $value;
    }

    private function admin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('m1-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
