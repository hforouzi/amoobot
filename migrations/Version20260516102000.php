<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh default discount UX bot templates';
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
                "✅ کد تخفیف اعمال شد.\n\nکد تخفیف: {{ discount.code }}\nمبلغ سفارش: {{ payment.originalAmount }} تومان\nمیزان تخفیف: {{ discount.amount }} تومان\nمبلغ قابل پرداخت: {{ payment.payableAmount }} تومان",
            ]
        );
        $this->addSql(
            'UPDATE bot_message_template SET body = ? WHERE template_key = ? AND locale = ? AND body = ?',
            [
                "خلاصه سفارش:\nنام اکانت: {{ order.accountName }}\nپلن: {{ plan.title }}\nحجم: {{ order.volume }}\nمدت: {{ order.duration }}\nکد پیگیری: {{ order.trackingCode }}\n\n{{ payment.amountBlock }}",
                'order.summary_after_discount',
                'fa',
                "خلاصه سفارش:\nنام اکانت: {{ order.accountName }}\nپلن: {{ plan.title }}\nحجم: {{ order.volume }}\nمدت: {{ order.duration }}\nکد پیگیری: {{ order.trackingCode }}\nمبلغ قابل پرداخت: {{ payment.payableAmount }} تومان",
            ]
        );
        $this->addSql(
            'UPDATE bot_message_template SET body = ? WHERE template_key = ? AND locale = ? AND body = ?',
            [
                "✅ Discount code applied.\n\nDiscount code: {{ discount.code }} ({{ discount.amount }})",
                'discount.applied',
                'en',
                "✅ Discount code applied.\n\nDiscount code: {{ discount.code }}\nOrder amount: {{ payment.originalAmount }}\nDiscount amount: {{ discount.amount }}\nPayable amount: {{ payment.payableAmount }}",
            ]
        );
        $this->addSql(
            'UPDATE bot_message_template SET body = ? WHERE template_key = ? AND locale = ? AND body = ?',
            [
                "Order summary:\nAccount name: {{ order.accountName }}\nPlan: {{ plan.title }}\nVolume: {{ order.volume }}\nDuration: {{ order.duration }}\nTracking code: {{ order.trackingCode }}\n\n{{ payment.amountBlock }}",
                'order.summary_after_discount',
                'en',
                "Order summary:\nAccount name: {{ order.accountName }}\nPlan: {{ plan.title }}\nVolume: {{ order.volume }}\nDuration: {{ order.duration }}\nTracking code: {{ order.trackingCode }}\nPayable amount: {{ payment.payableAmount }}",
            ]
        );
    }

    public function down(Schema $schema): void
    {
    }
}
