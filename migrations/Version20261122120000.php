<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PM2b (docs/design/product-modules.md 4.2, 9.1): built-in modules and drivers get plugin rows, like gateways.
 *
 *  - plugin.source: 'core' (built in: no files, no main class) or 'package' (installed from a ZIP). Every existing row
 *    is a payment gateway package and reads 'package' through the default; no existing row is written.
 *  - Core rows: the 'vpn' module, the 'sanaei_3xui' and 'dummy' VPN drivers as enabled (what runs today), the 'server'
 *    module as disabled (no code yet, and APP_MODULES_ALLOWED keeps it off on a VPN install).
 *
 * The rows change nothing at run time in this PR: core drivers stay registered as before whatever their row says (the
 * gating of 4.6/4.7 is PM3b). The plugin table is small (one row per gateway plugin): a plain ALTER.
 *
 * up() is refused, changing nothing, when a plugin row already uses one of the four codes (plugin.code is unique).
 * down() is refused while a driver package is installed: without the source column it would read as a core row.
 */
final class Version20261122120000 extends AbstractMigration
{
    /** code => [type, status, name fa, name en] */
    private const CORE_ROWS = [
        'vpn' => ['product_module', 'enabled', 'وی‌پی‌ان', 'VPN'],
        'server' => ['product_module', 'disabled', 'سرور', 'Servers'],
        'sanaei_3xui' => ['vpn_driver', 'enabled', '3x-ui (سنایی)', '3x-ui (Sanaei)'],
        'dummy' => ['vpn_driver', 'enabled', 'آزمایشی (بدون پنل)', 'Dummy (development, no panel)'],
    ];

    public function getDescription(): string
    {
        return 'product modules PM2b: plugin.source, core rows for the vpn and server modules and the sanaei_3xui and dummy drivers';
    }

    public function up(Schema $schema): void
    {
        $codes = array_keys(self::CORE_ROWS);
        $taken = $this->connection->fetchFirstColumn(
            'SELECT code FROM plugin WHERE code IN (?, ?, ?, ?) ORDER BY code',
            $codes,
        );
        $this->abortIf([] !== $taken, sprintf('Plugin row(s) with the code(s) %s exist already; the core rows need these codes. Nothing was changed.', implode(', ', $taken)));

        $this->addSql("ALTER TABLE plugin ADD source VARCHAR(16) DEFAULT 'package' NOT NULL");
        foreach (self::CORE_ROWS as $code => [$type, $status, $fa, $en]) {
            $this->addSql(
                "INSERT INTO plugin (code, type, name, version, description, status, source, path, main_class, manifest, permissions, installed_at, enabled_at, disabled_at, error_message, created_at, updated_at)
                 VALUES (?, ?, ?, 'core', NULL, ?, 'core', '', NULL, '{}', NULL, NOW(), IF(? = 'enabled', NOW(), NULL), IF(? = 'disabled', NOW(), NULL), NULL, NOW(), NULL)",
                [
                    $code,
                    $type,
                    json_encode(['fa' => $fa, 'en' => $en], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $status,
                    $status,
                    $status,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $packages = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM plugin WHERE source = 'package' AND type <> 'payment_gateway'");
        $this->abortIf($packages > 0, sprintf('Irreversible: %d driver or module package(s) are installed; without plugin.source they would read as core rows. Nothing was changed.', $packages));

        $this->addSql("DELETE FROM plugin WHERE source = 'core'");
        $this->addSql('ALTER TABLE plugin DROP source');
    }
}
