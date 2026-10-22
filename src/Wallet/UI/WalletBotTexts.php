<?php

declare(strict_types=1);

namespace App\Wallet\UI;

/**
 * Default (Persian) texts and button labels of the customer wallet in the bot, merged into BotContentRegistry (English:
 * translations/bot.en.yaml). Each can be changed in the admin (bot message templates / button labels) like every other
 * bot text.
 */
final class WalletBotTexts
{
    /**
     * @return array<string, array{title: string, body: string, category: string, variables?: list<string>}>
     */
    public static function messages(): array
    {
        return [
            'wallet.topup_confirmed' => ['title' => "Wallet topped up", 'body' => "✅ کیف پول شما {{ wallet.amount }} تومان شارژ شد.\nموجودی فعلی: {{ wallet.balance }} تومان", 'category' => 'wallet', 'variables' => ['wallet.amount', 'wallet.balance']],
            'wallet.overview' => ['title' => "My wallet", 'body' => "👛 کیف پول من\n\nموجودی: {{ wallet.balance }} تومان", 'category' => 'wallet', 'variables' => ['wallet.balance']],
            'wallet.overview_credit' => ['title' => "Wallet credit line", 'body' => "سقف اعتبار: {{ wallet.creditLimit }} تومان\nقابل استفاده: {{ wallet.available }} تومان", 'category' => 'wallet', 'variables' => ['wallet.creditLimit', 'wallet.available']],
            'wallet.overview_frozen' => ['title' => "Wallet frozen notice", 'body' => "⛔ کیف پول شما موقتاً مسدود است؛ پرداخت با آن ممکن نیست. برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.", 'category' => 'wallet'],
            'wallet.transactions_title' => ['title' => "Wallet transactions", 'body' => "📜 تراکنش‌های کیف پول (صفحه {{ page }}):", 'category' => 'wallet', 'variables' => ['page']],
            'wallet.transactions_empty' => ['title' => "No wallet transactions", 'body' => "هنوز تراکنشی ثبت نشده است.", 'category' => 'wallet'],
            'wallet.transaction_line' => ['title' => "Wallet transaction line", 'body' => "{{ tx.date }} | {{ tx.type }} | {{ tx.amount }} تومان | موجودی: {{ tx.balance }}", 'category' => 'wallet', 'variables' => ['tx.date', 'tx.type', 'tx.amount', 'tx.balance']],
            'wallet.tx_type.topup' => ['title' => "Wallet type: top-up", 'body' => "شارژ", 'category' => 'wallet'],
            'wallet.tx_type.purchase' => ['title' => "Wallet type: purchase", 'body' => "خرید", 'category' => 'wallet'],
            'wallet.tx_type.refund' => ['title' => "Wallet type: refund", 'body' => "بازگشت وجه", 'category' => 'wallet'],
            'wallet.tx_type.adjustment' => ['title' => "Wallet type: adjustment", 'body' => "اصلاح توسط پشتیبانی", 'category' => 'wallet'],
            'wallet.tx_type.reward' => ['title' => "Wallet type: reward", 'body' => "پاداش", 'category' => 'wallet'],
            'wallet.tx_type.reversal' => ['title' => "Wallet type: reversal", 'body' => "برگشت تراکنش", 'category' => 'wallet'],
            'wallet.topup_choose_amount' => ['title' => "Wallet top-up amount", 'body' => "مبلغ شارژ را انتخاب کنید (از {{ wallet.min }} تا {{ wallet.max }} تومان).\nمبلغ دیگری می‌خواهید؟ آن را در اپلیکیشن وارد کنید.", 'category' => 'wallet', 'variables' => ['wallet.min', 'wallet.max']],
            'wallet.topup_order' => ['title' => "Wallet top-up order", 'body' => "➕ شارژ کیف پول: {{ wallet.amount }} تومان\nکد پیگیری: {{ order.trackingCode }}\n\nروش پرداخت را انتخاب کنید:", 'category' => 'wallet', 'variables' => ['wallet.amount', 'order.trackingCode']],
            'wallet.topup_manual_card' => ['title' => "Wallet top-up card transfer", 'body' => "شارژ کیف پول\nکد پیگیری: {{ order.trackingCode }}\nمبلغ: {{ order.finalAmount }} تومان\n\nشماره کارت:\n{{ payment.cardNumber }}\n\nبه نام:\n{{ payment.cardHolder }}\n\n{{ payment.extraInstructions }}\n\nبرای ارسال رسید روی «{{ button.confirmAndSendReceipt }}» بزنید.", 'category' => 'wallet', 'variables' => ['order.trackingCode', 'order.finalAmount', 'payment.cardNumber', 'payment.cardHolder', 'payment.extraInstructions', 'button.confirmAndSendReceipt']],
            'wallet.disabled' => ['title' => "Wallet disabled", 'body' => "کیف پول در حال حاضر فعال نیست.", 'category' => 'wallet'],
            'wallet.frozen' => ['title' => "Wallet frozen", 'body' => "⛔ کیف پول شما مسدود است؛ برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.", 'category' => 'wallet'],
            'wallet.topup_out_of_range' => ['title' => "Wallet top-up out of range", 'body' => "مبلغ شارژ باید بین {{ wallet.min }} و {{ wallet.max }} تومان باشد.", 'category' => 'wallet', 'variables' => ['wallet.min', 'wallet.max']],
            'wallet.paid' => ['title' => "Order paid from wallet", 'body' => "✅ سفارش با کیف پول پرداخت شد ({{ wallet.amount }} تومان).\nموجودی فعلی: {{ wallet.balance }} تومان", 'category' => 'wallet', 'variables' => ['wallet.amount', 'wallet.balance']],
            'wallet.pay_insufficient' => ['title' => "Wallet balance too low", 'body' => "موجودی کیف پول برای این سفارش کافی نیست. ابتدا کیف پول را شارژ کنید یا روش دیگری انتخاب کنید.", 'category' => 'wallet'],
            'wallet.pay_failed' => ['title' => "Wallet payment failed", 'body' => "پرداخت با کیف پول انجام نشد. لطفاً دوباره تلاش کنید.", 'category' => 'wallet'],
            'wallet.reversed' => ['title' => "Wallet payment returned", 'body' => "↩️ مبلغ {{ wallet.amount }} تومان به کیف پول شما بازگشت.\nموجودی فعلی: {{ wallet.balance }} تومان", 'category' => 'wallet', 'variables' => ['wallet.amount', 'wallet.balance']],
        ];
    }

    /**
     * @return array<string, array{label: string, buttonType: string, category: string}>
     */
    public static function buttons(): array
    {
        return [
            'button.main.wallet' => ['label' => "👛 کیف پول", 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.wallet.topup' => ['label' => "➕ شارژ کیف پول", 'buttonType' => 'inline', 'category' => 'wallet'],
            'button.wallet.transactions' => ['label' => "📜 تراکنش‌ها", 'buttonType' => 'inline', 'category' => 'wallet'],
            'button.wallet.next_page' => ['label' => "صفحه بعد", 'buttonType' => 'inline', 'category' => 'wallet'],
            'button.wallet.back' => ['label' => "👛 بازگشت به کیف پول", 'buttonType' => 'inline', 'category' => 'wallet'],
            'button.payment.wallet' => ['label' => "👛 پرداخت با کیف پول", 'buttonType' => 'inline', 'category' => 'payment'],
        ];
    }
}
