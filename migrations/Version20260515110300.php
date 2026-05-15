<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515110300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plugin source code to payment gateways';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('payment_gateway');
        if (!$table->hasColumn('plugin_code')) {
            $this->addSql('ALTER TABLE payment_gateway ADD plugin_code VARCHAR(128) DEFAULT NULL');
        }
        if (!$table->hasIndex('IDX_ED58D24D2F4202D4')) {
            $this->addSql('CREATE INDEX IDX_ED58D24D2F4202D4 ON payment_gateway (plugin_code)');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('payment_gateway');
        if ($table->hasIndex('IDX_ED58D24D2F4202D4')) {
            $this->addSql('DROP INDEX IDX_ED58D24D2F4202D4 ON payment_gateway');
        }
        if ($table->hasColumn('plugin_code')) {
            $this->addSql('ALTER TABLE payment_gateway DROP plugin_code');
        }
    }
}
