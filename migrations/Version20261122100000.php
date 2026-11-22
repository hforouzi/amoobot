<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin redesign AD1 (docs/design/admin-redesign.md, 5.1): the layout switch admin.ui.layout, "classic" (today's
 * panel). Data only, and only when the row is missing: the settings page's "advanced" tab can edit an existing row but
 * not add one, so the owner flips it there to "modern" once the redesign is done (AdminUiMode).
 */
final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin layout switch setting admin.ui.layout = classic';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO setting (key_name, value, type, created_at, updated_at)
             SELECT ?, ?, ?, NOW(), NULL
             WHERE NOT EXISTS (SELECT 1 FROM setting WHERE key_name = ?)',
            ['admin.ui.layout', 'classic', 'string', 'admin.ui.layout']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM setting WHERE key_name = ?', ['admin.ui.layout']);
    }
}
