<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tracking_code to order table with unique index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD tracking_code VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F52993988F3A7B45 ON `order` (tracking_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_F52993988F3A7B45 ON `order`');
        $this->addSql('ALTER TABLE `order` DROP tracking_code');
    }
}

