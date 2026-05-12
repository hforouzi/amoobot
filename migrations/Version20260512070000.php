<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discount code and usage entities with order draft discount fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discount_code (id INT AUTO_INCREMENT NOT NULL, plan_id INT DEFAULT NULL, code VARCHAR(64) NOT NULL, title VARCHAR(255) DEFAULT NULL, type VARCHAR(20) NOT NULL, value INT NOT NULL, is_active TINYINT(1) NOT NULL, starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', max_uses INT DEFAULT NULL, used_count INT NOT NULL, max_uses_per_user INT DEFAULT NULL, first_purchase_only TINYINT(1) NOT NULL, applies_to VARCHAR(30) NOT NULL, min_amount INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_discount_code (code), INDEX IDX_AA649A4DCF7D7D86 (plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE discount_usage (id INT AUTO_INCREMENT NOT NULL, discount_code_id INT NOT NULL, user_id INT NOT NULL, order_id INT DEFAULT NULL, amount_before INT NOT NULL, discount_amount INT NOT NULL, amount_after INT NOT NULL, used_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', metadata JSON DEFAULT NULL, INDEX IDX_B33F677C2D663A75 (discount_code_id), INDEX IDX_B33F677CA76ED395 (user_id), INDEX IDX_B33F677C8D9F6D38 (order_id), UNIQUE INDEX uniq_discount_usage_order_code_user (order_id, discount_code_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE discount_code ADD CONSTRAINT FK_AA649A4DCF7D7D86 FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE discount_usage ADD CONSTRAINT FK_B33F677C2D663A75 FOREIGN KEY (discount_code_id) REFERENCES discount_code (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_usage ADD CONSTRAINT FK_B33F677CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_usage ADD CONSTRAINT FK_B33F677C8D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE order_draft ADD discount_code VARCHAR(64) DEFAULT NULL, ADD discount_amount INT DEFAULT NULL, ADD final_amount INT DEFAULT NULL, ADD price_snapshot JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discount_usage DROP FOREIGN KEY FK_B33F677C2D663A75');
        $this->addSql('ALTER TABLE discount_usage DROP FOREIGN KEY FK_B33F677CA76ED395');
        $this->addSql('ALTER TABLE discount_usage DROP FOREIGN KEY FK_B33F677C8D9F6D38');
        $this->addSql('ALTER TABLE discount_code DROP FOREIGN KEY FK_AA649A4DCF7D7D86');
        $this->addSql('DROP TABLE discount_usage');
        $this->addSql('DROP TABLE discount_code');
        $this->addSql('ALTER TABLE order_draft DROP discount_code, DROP discount_amount, DROP final_amount, DROP price_snapshot');
    }
}
