<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add service_notification_log table for lifecycle notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_notification_log (id INT AUTO_INCREMENT NOT NULL, service_id INT NOT NULL, user_id INT NOT NULL, type VARCHAR(64) NOT NULL, key_name VARCHAR(128) NOT NULL, sent_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', payload JSON DEFAULT NULL, INDEX IDX_DA42FB4FED5CA9E6 (service_id), INDEX IDX_DA42FB4FA76ED395 (user_id), UNIQUE INDEX uniq_service_notification_key (service_id, type, key_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE service_notification_log ADD CONSTRAINT FK_DA42FB4FED5CA9E6 FOREIGN KEY (service_id) REFERENCES vpn_service (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_notification_log ADD CONSTRAINT FK_DA42FB4FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_notification_log DROP FOREIGN KEY FK_DA42FB4FED5CA9E6');
        $this->addSql('ALTER TABLE service_notification_log DROP FOREIGN KEY FK_DA42FB4FA76ED395');
        $this->addSql('DROP TABLE service_notification_log');
    }
}
