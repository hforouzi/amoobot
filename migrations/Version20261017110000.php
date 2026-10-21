<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Infrastructure\SecretCipher;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * vpn_panel: panel health (consecutive failures, degraded since), password column wide enough for ciphertext, and
 * encryption at rest of the API tokens and passwords stored in clear text so far (including the old plain copy of the
 * token in config.api_token, which is removed). Keyed from APP_SECRET (SecretCipher), read from the environment the
 * console was started with (bin/console loads .env*.local / .env.local.php).
 */
final class Version20261017110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vpn_panel: health columns; encrypt stored panel API tokens and passwords';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_panel ADD consecutive_failures INT DEFAULT 0 NOT NULL, ADD degraded_since DATETIME DEFAULT NULL, MODIFY password LONGTEXT DEFAULT NULL');

        $rows = $this->connection->fetchAllAssociative('SELECT id, api_token, password, config FROM vpn_panel');
        $cipher = null;
        foreach ($rows as $row) {
            $config = is_string($row['config']) ? (json_decode($row['config'], true) ?: []) : [];
            $token = trim((string) ($row['api_token'] ?? ''));
            if ('' === $token) {
                $token = trim((string) ($config['api_token'] ?? ''));
            }
            $password = (string) ($row['password'] ?? '');
            $needsToken = '' !== $token && !SecretCipher::isEncrypted($token);
            $needsPassword = '' !== $password && !SecretCipher::isEncrypted($password);
            if (!$needsToken && !$needsPassword && !array_key_exists('api_token', $config)) {
                continue;
            }
            $cipher ??= new SecretCipher($this->appSecret());
            unset($config['api_token']);
            $this->addSql('UPDATE vpn_panel SET api_token = ?, password = ?, config = ? WHERE id = ?', [
                '' === $token ? null : ($needsToken ? $cipher->encrypt($token) : $token),
                '' === $password ? null : ($needsPassword ? $cipher->encrypt($password) : $password),
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) $row['id'],
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $cipher = null;
        foreach ($this->connection->fetchAllAssociative('SELECT id, api_token, password FROM vpn_panel') as $row) {
            if (!SecretCipher::isEncrypted($row['api_token']) && !SecretCipher::isEncrypted($row['password'])) {
                continue;
            }
            $cipher ??= new SecretCipher($this->appSecret());
            $this->addSql('UPDATE vpn_panel SET api_token = ?, password = ? WHERE id = ?', [
                SecretCipher::isEncrypted($row['api_token']) ? $cipher->decrypt($row['api_token']) : $row['api_token'],
                SecretCipher::isEncrypted($row['password']) ? mb_substr($cipher->decrypt($row['password']), 0, 255) : $row['password'],
                (int) $row['id'],
            ]);
        }
        $this->addSql('ALTER TABLE vpn_panel DROP consecutive_failures, DROP degraded_since, MODIFY password VARCHAR(255) DEFAULT NULL');
    }

    private function appSecret(): string
    {
        $secret = (string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: '');
        $this->abortIf('' === $secret, 'APP_SECRET is not set: panel secrets cannot be encrypted. Run the migration through bin/console with the production environment.');

        return $secret;
    }
}
