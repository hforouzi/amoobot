<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512084000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment gateway architecture and extend payment fields for online gateways';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_gateway (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, is_default TINYINT(1) NOT NULL, sort_order INT NOT NULL, currency VARCHAR(8) NOT NULL, config JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_ED58D24DC54C8C93 (type), INDEX IDX_ED58D24D8FCE962A (is_active), INDEX IDX_ED58D24DD9F6D38 (sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE payment ADD gateway_id INT DEFAULT NULL, ADD gateway_type VARCHAR(64) DEFAULT NULL, ADD gateway_transaction_id VARCHAR(255) DEFAULT NULL, ADD authority VARCHAR(255) DEFAULT NULL, ADD payment_url LONGTEXT DEFAULT NULL, ADD currency VARCHAR(8) NOT NULL DEFAULT \'IRR\', ADD payable_amount INT DEFAULT NULL, ADD callback_payload JSON DEFAULT NULL, ADD request_payload JSON DEFAULT NULL, ADD verify_payload JSON DEFAULT NULL, ADD verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD failed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD INDEX IDX_6D28840D2B9098D2 (gateway_id), ADD INDEX IDX_6D28840D2A97672D (gateway_type), ADD INDEX IDX_6D28840DD5807B89 (gateway_transaction_id), ADD INDEX IDX_6D28840DE1D35A29 (authority)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2B9098D2 FOREIGN KEY (gateway_id) REFERENCES payment_gateway (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D2B9098D2');
        $this->addSql('DROP TABLE payment_gateway');
        $this->addSql('DROP INDEX IDX_6D28840D2B9098D2 ON payment');
        $this->addSql('DROP INDEX IDX_6D28840D2A97672D ON payment');
        $this->addSql('DROP INDEX IDX_6D28840DD5807B89 ON payment');
        $this->addSql('DROP INDEX IDX_6D28840DE1D35A29 ON payment');
        $this->addSql('ALTER TABLE payment DROP gateway_id, DROP gateway_type, DROP gateway_transaction_id, DROP authority, DROP payment_url, DROP currency, DROP payable_amount, DROP callback_payload, DROP request_payload, DROP verify_payload, DROP verified_at, DROP failed_at, DROP expires_at');
    }
}

