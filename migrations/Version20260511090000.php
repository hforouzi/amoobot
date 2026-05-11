<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plan unlimited duration flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan ADD is_unlimited_duration TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan DROP is_unlimited_duration');
    }
}

