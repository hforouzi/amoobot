<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529104000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed manual card payment bot templates and receipt button label';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('bot_message_template')) {
            $this->insertMessage(
                'payment.manual_card.instructions',
                'Manual card payment instructions',
                "کد پیگیری سفارش شما:\n{{ order.trackingCode }}\n\nشناسه سرویس: {{ order.serviceId }}\nمبلغ پایه: {{ order.baseAmount }} تومان\nتخفیف سراسری: {{ order.globalDiscount }} تومان\nکد تخفیف: {{ order.discountCode }} ({{ order.discountAmount }} تومان)\nمبلغ نهایی: {{ order.finalAmount }} تومان\n\nشماره کارت:\n{{ payment.cardNumber }}\n\nبه نام:\n{{ payment.cardHolder }}\n\n{{ payment.extraInstructions }}\n\nبرای ارسال رسید روی «{{ button.confirmAndSendReceipt }}» بزنید.",
                ['order.trackingCode', 'order.serviceId', 'order.baseAmount', 'order.globalDiscount', 'order.discountCode', 'order.discountAmount', 'order.finalAmount', 'payment.cardNumber', 'payment.cardHolder', 'payment.extraInstructions', 'button.confirmAndSendReceipt']
            );
            $this->insertMessage(
                'payment.manual_card.plan_instructions',
                'Manual card plan payment instructions',
                "کد پیگیری سفارش شما:\n{{ order.trackingCode }}\n\nپلن: {{ plan.title }}\nمبلغ پایه: {{ order.baseAmount }} تومان\nتخفیف سراسری: {{ order.globalDiscount }} تومان\nکد تخفیف: {{ order.discountCode }} ({{ order.discountAmount }} تومان)\nمبلغ نهایی: {{ order.finalAmount }} تومان\n\nشماره کارت:\n{{ payment.cardNumber }}\n\nبه نام:\n{{ payment.cardHolder }}\n\n{{ payment.extraInstructions }}\n\nبرای ارسال رسید روی «{{ button.confirmAndSendReceipt }}» بزنید.",
                ['order.trackingCode', 'plan.title', 'order.baseAmount', 'order.globalDiscount', 'order.discountCode', 'order.discountAmount', 'order.finalAmount', 'payment.cardNumber', 'payment.cardHolder', 'payment.extraInstructions', 'button.confirmAndSendReceipt']
            );
            $this->insertMessage(
                'payment.manual_card.custom_order_instructions',
                'Manual card custom order payment instructions',
                "کد پیگیری سفارش شما:\n{{ order.trackingCode }}\n\nپلن: {{ plan.title }}\nنام کاربری: {{ order.accountName }}\nحجم: {{ order.trafficGb }} گیگ\nمدت: {{ order.duration }}\nمبلغ پایه: {{ order.baseAmount }} تومان\nتخفیف سراسری: {{ order.globalDiscount }} تومان\nکد تخفیف: {{ order.discountCode }} ({{ order.discountAmount }} تومان)\nمبلغ نهایی: {{ order.finalAmount }} تومان\n\nشماره کارت:\n{{ payment.cardNumber }}\n\nبه نام:\n{{ payment.cardHolder }}\n\n{{ payment.extraInstructions }}\n\nبرای ارسال رسید روی «{{ button.confirmAndSendReceipt }}» بزنید.",
                ['order.trackingCode', 'plan.title', 'order.accountName', 'order.trafficGb', 'order.duration', 'order.baseAmount', 'order.globalDiscount', 'order.discountCode', 'order.discountAmount', 'order.finalAmount', 'payment.cardNumber', 'payment.cardHolder', 'payment.extraInstructions', 'button.confirmAndSendReceipt']
            );
            $this->insertMessage(
                'payment.manual_card.incomplete_instructions',
                'Manual card incomplete payment instructions',
                "سفارش ناتمام شما:\nکد پیگیری: {{ order.trackingCode }}\nمبلغ: {{ order.finalAmount }} تومان\n\nشماره کارت:\n{{ payment.cardNumber }}\n\nبه نام:\n{{ payment.cardHolder }}\n\n{{ payment.extraInstructions }}\n\nبرای ارسال رسید روی «{{ button.confirmAndSendReceipt }}» بزنید.",
                ['order.trackingCode', 'order.finalAmount', 'payment.cardNumber', 'payment.cardHolder', 'payment.extraInstructions', 'button.confirmAndSendReceipt']
            );
        }

        if ($schema->hasTable('bot_button_label')) {
            $this->insertButton('button.confirm_and_send_receipt', '✅ تایید و ارسال رسید');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('bot_message_template')) {
            $this->addSql(
                'DELETE FROM bot_message_template WHERE locale = ? AND template_key IN (?, ?, ?, ?)',
                [
                    'fa',
                    'payment.manual_card.instructions',
                    'payment.manual_card.plan_instructions',
                    'payment.manual_card.custom_order_instructions',
                    'payment.manual_card.incomplete_instructions',
                ]
            );
        }

        if ($schema->hasTable('bot_button_label')) {
            $this->addSql(
                'DELETE FROM bot_button_label WHERE locale = ? AND label_key = ?',
                ['fa', 'button.confirm_and_send_receipt']
            );
        }
    }

    /**
     * @param list<string> $variables
     */
    private function insertMessage(string $key, string $title, string $body, array $variables): void
    {
        $this->addSql(
            'INSERT INTO bot_message_template (template_key, locale, title, body, parse_mode, variables, category, is_active, is_system, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL
             WHERE NOT EXISTS (SELECT 1 FROM bot_message_template WHERE template_key = ? AND locale = ?)',
            [
                $key,
                'fa',
                $title,
                $body,
                'html',
                json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'payments',
                1,
                1,
                $key,
                'fa',
            ]
        );
    }

    private function insertButton(string $key, string $label): void
    {
        $this->addSql(
            'INSERT INTO bot_button_label (label_key, locale, label, button_type, category, is_active, is_system, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, NOW(), NULL
             WHERE NOT EXISTS (SELECT 1 FROM bot_button_label WHERE label_key = ? AND locale = ?)',
            [
                $key,
                'fa',
                $label,
                'inline',
                'payment',
                1,
                1,
                $key,
                'fa',
            ]
        );
    }
}
