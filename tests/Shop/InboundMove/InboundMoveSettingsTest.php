<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Admin\Application\SettingsAdministration;
use App\Entity\AdminUser;
use App\Entity\Setting;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SettingsFormCases;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The inbound move switch and limits (docs/design/plan-inbound-change.md 8.1, PR P3): off, 24 h, 20, 2 s by default;
 * on the web settings page's "plans" tab with a note that only the command uses them (P4); saved and audited with the tab;
 * out-of-range or non-integer input refused with nothing saved; a stored value that is not valid reads as the default
 * (fail closed). Retries keep MultiInboundSettings' keys (no new retry key), and the sales form never touches them.
 */
final class InboundMoveSettingsTest extends IntegrationTestCase
{
    private const KEYS = [InboundMoveSettings::ENABLED, InboundMoveSettings::DETACH_GRACE_HOURS, InboundMoveSettings::BATCH_SIZE, InboundMoveSettings::PAUSE_SECONDS];

    public function testDefaults(): void
    {
        $settings = $this->settings();

        self::assertFalse($settings->enabled());
        self::assertSame([24, 20, 2], [$settings->detachGraceHours(), $settings->batchSize(), $settings->pauseSeconds()]);
        self::assertSame(['plans.inbound_move_enabled', 'plans.inbound_move_detach_grace_hours', 'plans.inbound_move_batch_size', 'plans.inbound_move_pause_seconds'], self::KEYS);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function storedValues(): iterable
    {
        yield 'grace lowest' => [InboundMoveSettings::DETACH_GRACE_HOURS, '0', 0];
        yield 'grace highest' => [InboundMoveSettings::DETACH_GRACE_HOURS, '168', 168];
        yield 'grace above' => [InboundMoveSettings::DETACH_GRACE_HOURS, '169', 24];
        yield 'grace negative' => [InboundMoveSettings::DETACH_GRACE_HOURS, '-1', 24];
        yield 'grace decimal' => [InboundMoveSettings::DETACH_GRACE_HOURS, '1.5', 24];
        yield 'grace text' => [InboundMoveSettings::DETACH_GRACE_HOURS, 'abc', 24];
        yield 'grace persian digits' => [InboundMoveSettings::DETACH_GRACE_HOURS, '۱۲', 24];
        yield 'grace padded' => [InboundMoveSettings::DETACH_GRACE_HOURS, ' 12 ', 12];
        yield 'batch lowest' => [InboundMoveSettings::BATCH_SIZE, '1', 1];
        yield 'batch highest' => [InboundMoveSettings::BATCH_SIZE, '100', 100];
        yield 'batch zero' => [InboundMoveSettings::BATCH_SIZE, '0', 20];
        yield 'batch above' => [InboundMoveSettings::BATCH_SIZE, '101', 20];
        yield 'batch huge' => [InboundMoveSettings::BATCH_SIZE, '99999999999999999999', 20];
        yield 'pause lowest' => [InboundMoveSettings::PAUSE_SECONDS, '0', 0];
        yield 'pause highest' => [InboundMoveSettings::PAUSE_SECONDS, '60', 60];
        yield 'pause above' => [InboundMoveSettings::PAUSE_SECONDS, '61', 2];
        yield 'pause exponent' => [InboundMoveSettings::PAUSE_SECONDS, '1e1', 2];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storedValues')]
    public function testAStoredValueOutOfRangeOrNotAWholeNumberReadsAsTheDefault(string $key, string $stored, int $expected): void
    {
        $this->store($key, $stored);
        $settings = $this->settings();

        $read = match ($key) {
            InboundMoveSettings::DETACH_GRACE_HOURS => $settings->detachGraceHours(),
            InboundMoveSettings::BATCH_SIZE => $settings->batchSize(),
            default => $settings->pauseSeconds(),
        };
        self::assertSame($expected, $read);
    }

    /** Read with SettingValueProvider::getBool(), like every other switch. */
    public function testTheSwitchIsOnOnlyWhenStoredAsOn(): void
    {
        foreach (['true' => true, '1' => true, 'false' => false, '0' => false, 'garbage' => false, '' => false] as $stored => $on) {
            $this->store(InboundMoveSettings::ENABLED, (string) $stored, 'boolean');
            self::assertSame($on, $this->settings()->enabled(), 'stored "'.$stored.'"');
        }
    }

    public function testShownOnThePlansTabWithTheNoteAndSavedAndAudited(): void
    {
        $client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $client->request('GET', '/admin/locale/en');

        $crawler = $client->request('GET', '/admin/settings?tab=plans');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($crawler->filter('input[name="plans_inbound_move_enabled"]')->attr('checked'));
        foreach (['plans_inbound_move_detach_grace_hours' => ['24', '0', '168'], 'plans_inbound_move_batch_size' => ['20', '1', '100'], 'plans_inbound_move_pause_seconds' => ['2', '0', '60']] as $name => [$value, $min, $max]) {
            $input = $crawler->filter('input[name="'.$name.'"]');
            self::assertSame([$value, $min, $max], [$input->attr('value'), $input->attr('min'), $input->attr('max')], $name);
        }
        self::assertStringContainsString('"Apply to existing services" on a plan\'s page', $crawler->filter('.admin-section')->last()->text());
        self::assertStringContainsString('the old inbound is removed after the grace period below, unless "keep the old inbound"', $crawler->filter('.admin-section')->last()->text());

        $audits = $this->planAudits();
        $form = $crawler->filter('form.admin-settings-form')->form();
        $checkbox = $form['plans_inbound_move_enabled'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->tick();
        $form['plans_inbound_move_detach_grace_hours']->setValue('0');
        $form['plans_inbound_move_batch_size']->setValue('100');
        $form['plans_inbound_move_pause_seconds']->setValue('60');
        $client->submit($form);

        self::assertSame(['true', '0', '100', '60'], $this->stored());
        self::assertSame($audits + 1, $this->planAudits());
        $details = json_decode((string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'settings.update' ORDER BY id DESC LIMIT 1"), true);
        self::assertIsArray($details);
        self::assertSame([true, 0, 100, 60], [$details['plans_inbound_move_enabled'] ?? null, $details['plans_inbound_move_detach_grace_hours'] ?? null, $details['plans_inbound_move_batch_size'] ?? null, $details['plans_inbound_move_pause_seconds'] ?? null]);

        $crawler = $client->request('GET', '/admin/settings?tab=plans');
        self::assertNotNull($crawler->filter('input[name="plans_inbound_move_enabled"]')->attr('checked'));
        $form = $crawler->filter('form.admin-settings-form')->form();
        $checkbox = $form['plans_inbound_move_enabled'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $client->submit($form);
        self::assertSame(['false', '0', '100', '60'], $this->stored());
    }

    public function testOutOfRangeOrNonIntegerInputIsRefusedAndNothingIsSaved(): void
    {
        $settings = self::getContainer()->get(SettingsAdministration::class);
        foreach ([
            ['plans_inbound_move_detach_grace_hours' => '-1'], ['plans_inbound_move_detach_grace_hours' => '169'], ['plans_inbound_move_detach_grace_hours' => '2.5'], ['plans_inbound_move_detach_grace_hours' => ''],
            ['plans_inbound_move_batch_size' => '0'], ['plans_inbound_move_batch_size' => '101'], ['plans_inbound_move_batch_size' => 'x'], ['plans_inbound_move_batch_size' => ['1']],
            ['plans_inbound_move_pause_seconds' => '61'], ['plans_inbound_move_pause_seconds' => '1e1'], ['plans_inbound_move_pause_seconds' => '٣'],
        ] as $bad) {
            $problem = $settings->savePlans(['plans_inbound_move_enabled' => 'on', 'plans_multi_inbound_enabled' => 'on'] + $bad);

            self::assertSame(array_key_first($bad), $problem['field'] ?? null, (string) json_encode($bad));
            self::assertSame('flash.settings.'.array_key_first($bad), $problem['message']);
        }
        self::assertSame([null, null, null, null], $this->stored(), 'nothing was saved');
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT 1 FROM setting WHERE key_name = ?', [MultiInboundSettings::ENABLED]), 'not even the other switches of the tab');
    }

    public function testTheWebFormRefusalSaysWhyAndSavesNothing(): void
    {
        $client = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $client->request('GET', '/admin/locale/en');

        $client->request('POST', '/admin/settings?tab=plans', ['plans_inbound_move_enabled' => 'on', 'plans_inbound_move_batch_size' => '500']);
        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        self::assertStringContainsString('Services per pass must be a whole number between 1 and 100.', html_entity_decode((string) $client->getResponse()->getContent(), ENT_QUOTES));
        self::assertSame([null, null, null, null], $this->stored());
    }

    public function testAFormWithoutTheNewFieldsSavesTheDefaults(): void
    {
        self::assertNull(self::getContainer()->get(SettingsAdministration::class)->savePlans(['plans_multi_inbound_enabled' => 'on']));

        self::assertSame(['false', '24', '20', '2'], $this->stored());
    }

    public function testRetriesReuseTheMultiInboundKeysAndNoOtherFormTouchesTheNewOnes(): void
    {
        self::assertSame([
            'plans_multi_inbound_enabled' => MultiInboundSettings::ENABLED,
            'plans_attach_retry_enabled' => MultiInboundSettings::ATTACH_RETRY_ENABLED,
            'plans_attach_max_attempts' => MultiInboundSettings::ATTACH_MAX_ATTEMPTS,
            'plans_attach_retry_delay_minutes' => MultiInboundSettings::ATTACH_RETRY_DELAY_MINUTES,
            'plans_inbound_move_enabled' => InboundMoveSettings::ENABLED,
            'plans_inbound_move_detach_grace_hours' => InboundMoveSettings::DETACH_GRACE_HOURS,
            'plans_inbound_move_batch_size' => InboundMoveSettings::BATCH_SIZE,
            'plans_inbound_move_pause_seconds' => InboundMoveSettings::PAUSE_SECONDS,
        ], SettingsAdministration::PLANS_KEYS, 'one key per setting, the retry ones shared');
        $constants = (new \ReflectionClass(InboundMoveSettings::class))->getConstants();
        self::assertSame(self::KEYS, array_values(array_filter($constants, static fn (mixed $v): bool => is_string($v) && str_starts_with($v, 'plans.'))), 'no retry key of its own');

        $settings = self::getContainer()->get(SettingsAdministration::class);
        self::assertNull($settings->savePlans(['plans_inbound_move_enabled' => 'on', 'plans_inbound_move_batch_size' => '7']));
        self::assertNull($settings->saveSales(SettingsFormCases::input('sales', [])));
        self::assertSame(['true', '24', '7', '2'], $this->stored(), 'the sales form saves its own keys only');
        foreach (self::KEYS as $key) {
            self::assertNotContains($key, SettingsAdministration::SALES_KEYS + SettingsAdministration::AUTOMATION_KEYS);
        }
    }

    public function testAnAdminWithoutSettingsManageCannotChangeThem(): void
    {
        $client = $this->admin(AdminUser::ROLE_SUPPORT);

        $client->request('POST', '/admin/settings?tab=plans', ['plans_inbound_move_enabled' => 'on']);

        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        self::assertSame([null, null, null, null], $this->stored());
    }

    private function settings(): InboundMoveSettings
    {
        $this->em->clear();

        return self::getContainer()->get(InboundMoveSettings::class);
    }

    private function store(string $key, string $value, string $type = 'number'): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $this->em->clear();
        $this->em->persist((new Setting())->setKeyName($key)->setValue($value)->setType($type));
        $this->em->flush();
    }

    /** @return list<string|null> the stored value of each key, NULL when there is no row */
    private function stored(): array
    {
        return array_map(function (string $key): ?string {
            $value = $this->em->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [$key]);

            return false === $value ? null : (string) $value;
        }, self::KEYS);
    }

    private function planAudits(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'settings.update' AND details LIKE '%\"page\":\"plans\"%'");
    }

    private function admin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('p3-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
