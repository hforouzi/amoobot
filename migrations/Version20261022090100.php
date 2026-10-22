<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * client_app (import deep links on the subscription page) and app_download (installers served by /d/{id}).
 *
 * Seeded client apps. Only schemes found in the app's own source code or documentation are active (checked
 * 2026-10-22); the others are seeded inactive so an admin can test them on a device and switch them on.
 *   Hiddify   hiddify://install-config?url=<url>#<name>   hiddify-app lib/utils/link_parsers.dart ("Doc form")
 *   v2rayNG   v2rayng://install-sub?url=<url>#<name>      v2rayNG AndroidManifest.xml (host install-sub) +
 *             UrlSchemeActivity.kt; the name is the fragment (AngConfigManager: remarks = uri.fragment)
 *   NekoBox   sn://subscription?url=<url>&name=<name>     NekoBoxForAndroid AndroidManifest.xml + MainActivity.kt
 *   v2rayN    no URL scheme in its source: active without a deep link (install + copy the link)
 *   sing-box  sing-box://import-remote-profile?url=<url>#<name> (sing-box.sagernet.org/clients/general): inactive,
 *             the clients read sing-box profiles, not a v2ray/3x-ui base64 subscription
 *   V2Box, Streisand, Shadowrocket, FoXray, Happ: closed source, no vendor documentation of the scheme (only
 *             third-party pages): inactive
 */
final class Version20261022090100 extends AbstractMigration
{
    private const ALL = ['android', 'ios', 'windows', 'macos', 'linux'];

    public function getDescription(): string
    {
        return 'client_app and app_download for the subscription page, with verified client app seeds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_app (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, platforms JSON NOT NULL, import_url_template LONGTEXT DEFAULT NULL, store_url VARCHAR(500) DEFAULT NULL, icon VARCHAR(16) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, sort_order INT NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_client_app_active_sort (is_active, sort_order), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE app_download (id INT AUTO_INCREMENT NOT NULL, client_app_id INT DEFAULT NULL, title VARCHAR(150) NOT NULL, platform VARCHAR(16) NOT NULL, version VARCHAR(50) DEFAULT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(8) NOT NULL, external_url VARCHAR(500) DEFAULT NULL, stored_name VARCHAR(64) DEFAULT NULL, original_name VARCHAR(190) DEFAULT NULL, mime_type VARCHAR(100) DEFAULT NULL, size_bytes BIGINT DEFAULT NULL, sha256 VARCHAR(64) DEFAULT NULL, sort_order INT NOT NULL, is_active TINYINT(1) NOT NULL, download_count INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_709AE2DD2C216A9E (client_app_id), INDEX idx_app_download_active_platform (is_active, platform, sort_order), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE app_download ADD CONSTRAINT FK_APP_DOWNLOAD_CLIENT_APP FOREIGN KEY (client_app_id) REFERENCES client_app (id) ON DELETE SET NULL');

        $apps = [
            ['Hiddify', self::ALL, 'hiddify://install-config?url={url_encoded}#{name}', 'https://github.com/hiddify/hiddify-app/releases', 10, true,
                'Verified: hiddify-app lib/utils/link_parsers.dart, "hiddify://install-config?url=<uri>#<name>".'],
            ['v2rayNG', ['android'], 'v2rayng://install-sub?url={url_encoded}#{name}', 'https://github.com/2dust/v2rayNG/releases', 20, true,
                'Verified: v2rayNG AndroidManifest.xml (scheme v2rayng, host install-sub) and UrlSchemeActivity.kt; the profile name is the URL fragment.'],
            ['NekoBox', ['android'], 'sn://subscription?url={url_encoded}&name={name}', 'https://github.com/MatsuriDayo/NekoBoxForAndroid/releases', 30, true,
                'Verified: NekoBoxForAndroid AndroidManifest.xml (scheme sn, host subscription) and MainActivity.kt (url, name query parameters).'],
            ['v2rayN', ['windows', 'linux', 'macos'], null, 'https://github.com/2dust/v2rayN/releases', 40, true,
                'v2rayN registers no URL scheme (checked in its source): customers copy the link and add it under Subscription group.'],
            ['sing-box', ['android', 'ios', 'macos'], 'sing-box://import-remote-profile?url={url_encoded}#{name}', 'https://github.com/SagerNet/sing-box/releases', 50, false,
                'Scheme verified (sing-box.sagernet.org/clients/general), but sing-box clients only read sing-box JSON profiles, not a v2ray/3x-ui subscription. Enable only with a sing-box format subscription.'],
            ['V2Box', ['ios', 'android'], 'v2box://install-sub?url={url_encoded}&name={name}', 'https://apps.apple.com/us/app/v2box-v2ray-client/id6446814690', 60, false,
                'Unverified: closed source, scheme only documented by third parties. Test on a device before enabling.'],
            ['Streisand', ['ios', 'macos'], 'streisand://import/{url}#{name}', 'https://apps.apple.com/app/id6450534064', 70, false,
                'Unverified: closed source, scheme only documented by third parties. Test on a device before enabling.'],
            ['Shadowrocket', ['ios'], 'sub://{url_base64}#{name}', null, 80, false,
                'Unverified: closed source, sub://<base64 url> is a third-party convention. Test on a device before enabling.'],
            ['FoXray', ['ios', 'macos'], 'foxray://yiguo.dev/sub/add/?url={url_encoded}#{name}', 'https://apps.apple.com/app/id6448898396', 90, false,
                'Unverified: closed source, scheme only documented by third parties; the app was at times removed from the App Store.'],
            ['Happ', self::ALL, 'happ://add/{url}', 'https://www.happ.su/', 100, false,
                'Unverified: Happ\'s docs (happ.su) mention deep links but only document happ://crypt*/ and happ://routing/; happ://add/ comes from a community source.'],
        ];
        foreach ($apps as [$name, $platforms, $template, $store, $sort, $active, $notes]) {
            $this->addSql(
                'INSERT INTO client_app (name, platforms, import_url_template, store_url, icon, notes, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, NOW())',
                [$name, json_encode($platforms, JSON_THROW_ON_ERROR), $template, $store, $notes, $sort, $active ? 1 : 0],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_download DROP FOREIGN KEY FK_APP_DOWNLOAD_CLIENT_APP');
        $this->addSql('DROP TABLE app_download');
        $this->addSql('DROP TABLE client_app');
    }
}
