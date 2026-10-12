<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unguessable per-payment return token used by the customer return page.
 *
 * Existing payments keep NULL (their gateway return URLs were issued without a token); a unique
 * index allows any number of NULLs.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment return token (unique) for the customer return page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD return_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_return_token ON payment (return_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_payment_return_token ON payment');
        $this->addSql('ALTER TABLE payment DROP return_token');
    }
}
