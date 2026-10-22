<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RestoresTables;
use App\Tests\Support\SettingsFormCases;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What the web settings page saves and refuses (sales & pricing, automation tabs), case by case: recorded before the
 * save moved into SettingsAdministration, which the Mini App settings use too (MiniAppAdminSettingsTest runs the same
 * cases there).
 */
final class SettingsSaveCharacterizationTest extends IntegrationTestCase
{
    use RestoresTables;

    private const PREFIXES = ['renewal.', 'pricing.', 'traffic_addon.', 'sales.', 'automation.', 'orders.'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotTables('setting');
        $this->clearSettings();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $admin = (new AdminUser())->setEmail('settings@example.test')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setPassword('x');
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    protected function tearDown(): void
    {
        $this->clearSettings();
        $this->restoreTables();
        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $changes
     * @param array<string, string>      $stored
     */
    #[DataProvider('cases')]
    public function testTheWebSavesOrRefusesAsRecorded(string $tab, array $changes, ?string $error, array $stored): void
    {
        $this->client->request('POST', '/admin/settings?tab='.$tab, SettingsFormCases::input($tab, $changes));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        $translator = self::getContainer()->get('translator');

        if (null !== $error) {
            self::assertStringContainsString(htmlspecialchars($translator->trans($error, [], 'admin', 'fa'), ENT_QUOTES), $html);
            self::assertSame([], $this->settings(), 'nothing saved');
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM admin_audit_log'));

            return;
        }
        $saved = $this->settings();
        foreach ($stored as $key => $value) {
            self::assertSame($value, $saved[$key] ?? null, $key);
        }
        self::assertSame(['settings.update', 'web'], array_values($this->em->getConnection()->fetchAssociative('SELECT action, source FROM admin_audit_log') ?: []));
    }

    /**
     * @return iterable<string, array{string, array<string, string|null>, string|null, array<string, string>}>
     */
    public static function cases(): iterable
    {
        return SettingsFormCases::cases();
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue('SELECT key_name, value FROM setting WHERE '.implode(' OR ', array_fill(0, count(self::PREFIXES), 'key_name LIKE ?')), array_map(static fn (string $p): string => $p.'%', self::PREFIXES));

        return array_map('strval', $rows);
    }

    private function clearSettings(): void
    {
        foreach (self::PREFIXES as $prefix) {
            $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name LIKE ?', [$prefix.'%']);
        }
    }
}
