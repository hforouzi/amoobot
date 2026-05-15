<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515110200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plugin code to payment gateways';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_gateway ADD plugin_code VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_ED58D24D2F4202D4 ON payment_gateway (plugin_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_ED58D24D2F4202D4 ON payment_gateway');
        $this->addSql('ALTER TABLE payment_gateway DROP plugin_code');
    }
}
