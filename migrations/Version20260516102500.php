<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516102500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh legacy discount applied bot template';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('bot_message_template')) {
            return;
        }

        $this->addSql(
            'UPDATE bot_message_template SET body = ? WHERE template_key = ? AND locale = ? AND body = ?',
            [
                "✅ کد تخفیف اعمال شد.\n\nکد تخفیف: {{ discount.code }} ({{ discount.amount }} تومان)",
                'discount.applied',
                'fa',
                'کد تخفیف اعمال شد.',
            ]
        );
        $this->addSql(
            'UPDATE bot_message_template SET body = ? WHERE template_key = ? AND locale = ? AND body = ?',
            [
                "✅ Discount code applied.\n\nDiscount code: {{ discount.code }} ({{ discount.amount }})",
                'discount.applied',
                'en',
                'Discount code applied.',
            ]
        );
    }

    public function down(Schema $schema): void
    {
    }
}
