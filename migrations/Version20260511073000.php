<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customizable plan fields, order metadata, and order_draft table for custom purchase flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan ADD is_customizable TINYINT(1) NOT NULL DEFAULT 0, ADD min_traffic_gb INT DEFAULT NULL, ADD max_traffic_gb INT DEFAULT NULL, ADD price_per_gb INT DEFAULT NULL, ADD min_duration_days INT DEFAULT NULL, ADD max_duration_days INT DEFAULT NULL, ADD price_per_day INT DEFAULT NULL, ADD allow_custom_username TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE `order` ADD metadata JSON DEFAULT NULL');

        $this->addSql('CREATE TABLE order_draft (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, status VARCHAR(50) NOT NULL, custom_username_prefix VARCHAR(64) DEFAULT NULL, final_username VARCHAR(128) DEFAULT NULL, traffic_gb INT DEFAULT NULL, duration_days INT DEFAULT NULL, calculated_amount INT DEFAULT NULL, step VARCHAR(64) NOT NULL, data JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", expires_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_115D6EA4A76ED395 (user_id), INDEX IDX_115D6EA4C1825E51 (plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE order_draft ADD CONSTRAINT FK_115D6EA4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_draft ADD CONSTRAINT FK_115D6EA4C1825E51 FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_draft DROP FOREIGN KEY FK_115D6EA4A76ED395');
        $this->addSql('ALTER TABLE order_draft DROP FOREIGN KEY FK_115D6EA4C1825E51');
        $this->addSql('DROP TABLE order_draft');

        $this->addSql('ALTER TABLE `order` DROP metadata');
        $this->addSql('ALTER TABLE plan DROP is_customizable, DROP min_traffic_gb, DROP max_traffic_gb, DROP price_per_gb, DROP min_duration_days, DROP max_duration_days, DROP price_per_day, DROP allow_custom_username');
    }
}

