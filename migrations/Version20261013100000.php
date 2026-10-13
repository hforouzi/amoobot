<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records that a rejected payment (e.g. a duplicate for an already fulfilled order) was refunded.
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment refunded_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD refunded_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP refunded_at');
    }
}
