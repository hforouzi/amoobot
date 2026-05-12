<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512105700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add StorePaymentMethod and link payments to store payment methods';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE store_payment_method (id INT AUTO_INCREMENT NOT NULL, gateway_id INT NOT NULL, title VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL, sort_order INT NOT NULL, min_amount INT DEFAULT NULL, max_amount INT DEFAULT NULL, currency VARCHAR(8) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_52E3638E2B9098D2 (gateway_id), INDEX IDX_52E3638E8FCE962A (is_active), INDEX IDX_52E3638ED9F6D38 (sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE store_payment_method ADD CONSTRAINT FK_52E3638E2B9098D2 FOREIGN KEY (gateway_id) REFERENCES payment_gateway (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment ADD store_payment_method_id INT DEFAULT NULL, ADD INDEX IDX_6D28840D8B5A09D2 (store_payment_method_id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8B5A09D2 FOREIGN KEY (store_payment_method_id) REFERENCES store_payment_method (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D8B5A09D2');
        $this->addSql('DROP TABLE store_payment_method');
        $this->addSql('DROP INDEX IDX_6D28840D8B5A09D2 ON payment');
        $this->addSql('ALTER TABLE payment DROP store_payment_method_id');
    }
}

