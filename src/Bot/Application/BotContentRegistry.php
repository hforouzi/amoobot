<?php

declare(strict_types=1);

namespace App\Bot\Application;

final class BotContentRegistry
{
    public const DEFAULT_LOCALE = 'fa';

    /**
     * @return array<string, array{title:string, body:string, category:string, variables?:list<string>}>
     */
    public function messageTemplates(string $locale = self::DEFAULT_LOCALE): array
    {
        if ('en' === $locale) {
            return $this->englishMessages();
        }

        return $this->persianMessages();
    }

    /**
     * @return array<string, array{label:string, buttonType:string, category:string}>
     */
    public function buttonLabels(string $locale = self::DEFAULT_LOCALE): array
    {
        if ('en' === $locale) {
            return $this->englishButtons();
        }

        return $this->persianButtons();
    }

    public function emergencyMessage(string $key, string $locale = self::DEFAULT_LOCALE): string
    {
        $messages = $this->messageTemplates($locale);

        return $messages[$key]['body'] ?? $key;
    }

    public function emergencyButton(string $key, string $locale = self::DEFAULT_LOCALE): string
    {
        $buttons = $this->buttonLabels($locale);

        return $buttons[$key]['label'] ?? $key;
    }

    /**
     * @return list<string>
     */
    public function requiredMessageKeys(): array
    {
        return array_keys($this->persianMessages());
    }

    /**
     * @return list<string>
     */
    public function requiredButtonKeys(): array
    {
        return array_keys($this->persianButtons());
    }

    /**
     * @return list<string>
     */
    public function replyButtonKeys(): array
    {
        return [
            'button.main.buy_service',
            'button.main.my_services',
            'button.main.support',
            'button.main.track_order',
            'button.main.continue_order',
            'button.main.cancel_incomplete_order',
            'button.main.admin',
        ];
    }

    /**
     * @return array<string, array{title:string, body:string, category:string, variables?:list<string>}>
     */
    private function persianMessages(): array
    {
        return [
            'bot.welcome' => ['title' => 'Welcome', 'body' => "خوش آمدید 🌟\nاز منوی زیر می‌توانید سرویس خریداری کنید یا سرویس‌های خود را ببینید.", 'category' => 'general'],
            'bot.main_menu' => ['title' => 'Main menu', 'body' => 'منوی اصلی:', 'category' => 'general'],
            'bot.unknown_message' => ['title' => 'Unknown message', 'body' => 'دستور نامعتبر است. لطفا از منو استفاده کنید.', 'category' => 'general'],
            'bot.error' => ['title' => 'Generic error', 'body' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.', 'category' => 'general'],
            'bot.back' => ['title' => 'Back', 'body' => 'بازگشت', 'category' => 'general'],
            'bot.cancelled' => ['title' => 'Cancelled', 'body' => 'عملیات لغو شد.', 'category' => 'general'],
            'bot.support' => ['title' => 'Support', 'body' => 'برای پشتیبانی با ادمین در ارتباط باشید.', 'category' => 'general'],
            'sales.disabled' => ['title' => 'Sales disabled', 'body' => '{{ sales.disabledMessage }}', 'category' => 'sales', 'variables' => ['sales.disabledMessage']],
            'plan.list_empty' => ['title' => 'No plans', 'body' => 'در حال حاضر پلن فعالی موجود نیست.', 'category' => 'plans'],
            'plan.select' => ['title' => 'Select plan', 'body' => 'لطفا یک پلن را انتخاب کنید:', 'category' => 'plans'],
            'plan.invalid' => ['title' => 'Invalid plan', 'body' => 'پلن انتخاب شده معتبر نیست.', 'category' => 'plans'],
            'order.summary' => ['title' => 'Order summary', 'body' => "خلاصه سفارش #{{ order.id }}\nکد پیگیری: {{ order.trackingCode }}\nپلن: {{ plan.title }}\nمبلغ نهایی: {{ payment.amount }} تومان", 'category' => 'orders', 'variables' => ['order.id', 'order.trackingCode', 'plan.title', 'payment.amount']],
            'order.summary_after_discount' => ['title' => 'Order summary after discount', 'body' => "خلاصه سفارش:\nنام اکانت: {{ order.accountName }}\nپلن: {{ plan.title }}\nحجم: {{ order.volume }}\nمدت: {{ order.duration }}\nکد پیگیری: {{ order.trackingCode }}\n\n{{ payment.amountBlock }}", 'category' => 'orders', 'variables' => ['order.accountName', 'plan.title', 'order.volume', 'order.duration', 'order.trackingCode', 'payment.amountBlock']],
            'order.created' => ['title' => 'Order created', 'body' => 'سفارش شما ثبت شد.', 'category' => 'orders'],
            'order.tracking' => ['title' => 'Order tracking', 'body' => 'سفارش‌های اخیر شما:', 'category' => 'orders'],
            'order.not_found' => ['title' => 'Order not found', 'body' => 'سفارش معتبر نیست.', 'category' => 'orders'],
            'order.incomplete_found' => ['title' => 'Incomplete order found', 'body' => 'شما یک سفارش ناتمام دارید.', 'category' => 'orders'],
            'order.incomplete_cancelled' => ['title' => 'Incomplete order cancelled', 'body' => 'سفارش ناتمام حذف شد.', 'category' => 'orders'],
            'order.expired' => ['title' => 'Order expired', 'body' => 'این سفارش منقضی شده است.', 'category' => 'orders'],
            'order.waiting_payment' => ['title' => 'Order waiting payment', 'body' => 'این سفارش در انتظار پرداخت است.', 'category' => 'orders'],
            'payment.no_methods' => ['title' => 'No payment methods', 'body' => 'در حال حاضر روش پرداخت فعالی وجود ندارد.', 'category' => 'payments'],
            'payment.method_select' => ['title' => 'Select payment method', 'body' => 'روش پرداخت را انتخاب کنید:', 'category' => 'payments'],
            'payment.created' => ['title' => 'Payment created', 'body' => 'پرداخت ایجاد شد.', 'category' => 'payments'],
            'payment.check_pending' => ['title' => 'Payment pending', 'body' => 'پرداخت هنوز تایید نشده است. پس از پرداخت چند دقیقه صبر کنید و دوباره بررسی کنید.', 'category' => 'payments'],
            'payment.confirmed' => ['title' => 'Payment confirmed', 'body' => '✅ پرداخت شما تایید شد.', 'category' => 'payments'],
            'payment.rejected' => ['title' => 'Payment rejected', 'body' => '❌ پرداخت شما رد شد. لطفا مجدد رسید معتبر ارسال کنید یا با پشتیبانی تماس بگیرید.', 'category' => 'payments'],
            'payment.failed' => ['title' => 'Payment failed', 'body' => 'پرداخت ناموفق بود.', 'category' => 'payments'],
            'payment.expired' => ['title' => 'Payment expired', 'body' => 'مهلت پرداخت به پایان رسیده است.', 'category' => 'payments'],
            'payment.receipt_request' => ['title' => 'Receipt request', 'body' => 'لطفاً رسید پرداخت را ارسال کنید.', 'category' => 'payments'],
            'payment.receipt_received' => ['title' => 'Receipt received', 'body' => 'اطلاعات پرداخت شما ثبت شد و پس از بررسی تایید می‌شود.', 'category' => 'payments'],
            'payment.receipt_waiting_admin' => ['title' => 'Receipt waiting admin', 'body' => 'رسید شما ارسال شده و منتظر تایید ادمین است.', 'category' => 'payments'],
            'payment.crypto_created' => ['title' => 'Crypto payment created', 'body' => 'پرداخت ارز دیجیتال ایجاد شد.', 'category' => 'payments'],
            'service.created' => ['title' => 'Service created', 'body' => 'سرویس شما با موفقیت ایجاد شد.', 'category' => 'services'],
            'service.list_empty' => ['title' => 'No services', 'body' => 'شما در حال حاضر سرویسی ندارید.', 'category' => 'services'],
            'service.detail' => ['title' => 'Service detail', 'body' => 'جزئیات سرویس شما:', 'category' => 'services'],
            'service.expiring' => ['title' => 'Service expiring', 'body' => "⏳ سرویس شما به‌زودی منقضی می‌شود.\nشناسه سرویس: {{ service.id }}\nتاریخ انقضا: {{ service.expiresAt }}", 'category' => 'services', 'variables' => ['service.id', 'service.expiresAt']],
            'service.expired' => ['title' => 'Service expired', 'body' => "سرویس شما منقضی شده است.\nشناسه سرویس: {{ service.id }}", 'category' => 'services', 'variables' => ['service.id']],
            'service.traffic_low' => ['title' => 'Traffic low', 'body' => "حجم سرویس شما رو به اتمام است.\nمصرف: {{ service.trafficUsedPercent }}٪", 'category' => 'services', 'variables' => ['service.trafficUsedPercent']],
            'service.traffic_exhausted' => ['title' => 'Traffic exhausted', 'body' => 'حجم سرویس شما به پایان رسیده است.', 'category' => 'services'],
            'service.renewed' => ['title' => 'Service renewed', 'body' => '✅ سرویس شما با موفقیت تمدید شد.', 'category' => 'services'],
            'service.add_traffic_done' => ['title' => 'Traffic added', 'body' => '✅ حجم اضافه با موفقیت به سرویس شما اضافه شد.', 'category' => 'services'],
            'service.configs' => ['title' => 'Service configs', 'body' => "کانفیگهای سرویس #{{ service.id }}:\n{{ service.configs }}", 'category' => 'services', 'variables' => ['service.id', 'service.configs']],
            'service.subscription_link_missing' => ['title' => 'Missing subscription link', 'body' => 'لینک اشتراک برای این سرویس موجود نیست.', 'category' => 'services'],
            'discount.ask' => ['title' => 'Ask discount', 'body' => 'در صورت داشتن کد تخفیف، آن را وارد کنید.', 'category' => 'discounts'],
            'discount.applied' => ['title' => 'Discount applied', 'body' => "✅ کد تخفیف اعمال شد.\n\nکد تخفیف: {{ discount.code }} ({{ discount.amount }} تومان)", 'category' => 'discounts', 'variables' => ['discount.code', 'discount.amount']],
            'discount.invalid' => ['title' => 'Discount invalid', 'body' => "❌ کد تخفیف معتبر نیست یا قابل استفاده نمی‌باشد.\n{{ discount.reason }}", 'category' => 'discounts', 'variables' => ['discount.reason']],
            'discount.skipped' => ['title' => 'Discount skipped', 'body' => 'بدون کد تخفیف ادامه می‌دهید.', 'category' => 'discounts'],
            'admin.payment_submitted' => ['title' => 'Admin payment submitted', 'body' => "پرداخت جدید ثبت شد\n\nPayment ID: {{ payment.id }}\nOrder ID: {{ order.id }}\nUser: {{ user.name }}\nAmount: {{ payment.amount }} تومان", 'category' => 'admin', 'variables' => ['payment.id', 'order.id', 'user.name', 'payment.amount']],
            'admin.payment_confirmed' => ['title' => 'Admin payment confirmed', 'body' => '✅ پرداخت تایید شد.', 'category' => 'admin'],
            'admin.payment_rejected' => ['title' => 'Admin payment rejected', 'body' => '❌ پرداخت رد شد.', 'category' => 'admin'],
            'admin.order_created' => ['title' => 'Admin order created', 'body' => 'سفارش جدید ثبت شد.', 'category' => 'admin'],
            'admin.unauthorized' => ['title' => 'Admin unauthorized', 'body' => 'Unauthorized', 'category' => 'admin'],
        ];
    }

    private function englishMessages(): array
    {
        $messages = $this->persianMessages();
        $overrides = [
            'bot.welcome' => 'Welcome. Use the menu below to buy a service or view your services.',
            'bot.main_menu' => 'Main menu:',
            'bot.unknown_message' => 'Invalid command. Please use the menu.',
            'bot.error' => 'An error occurred. Please try again.',
            'bot.back' => 'Back',
            'bot.cancelled' => 'Cancelled.',
            'bot.support' => 'Contact admin for support.',
            'sales.disabled' => 'Sales are currently disabled. Please try again later.',
            'payment.no_methods' => 'No active payment method is available right now.',
            'payment.confirmed' => '✅ Your payment was confirmed.',
            'payment.rejected' => '❌ Your payment was rejected. Please send a valid receipt again or contact support.',
            'payment.receipt_received' => 'Your payment information was submitted and will be reviewed.',
            'service.list_empty' => 'You do not have any services yet.',
            'service.renewed' => '✅ Your service was renewed successfully.',
            'service.add_traffic_done' => '✅ Extra traffic was added to your service.',
        ];
        foreach ($overrides as $key => $body) {
            if (isset($messages[$key])) {
                $messages[$key]['body'] = $body;
            }
        }

        return $messages;
    }

    private function persianButtons(): array
    {
        return [
            'button.main.buy_service' => ['label' => '🛒 خرید سرویس', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.main.my_services' => ['label' => '📦 سرویسهای من', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.main.support' => ['label' => '🎧 پشتیبانی', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.main.track_order' => ['label' => '🔎 پیگیری سفارش', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.main.continue_order' => ['label' => '▶️ ادامه سفارش قبلی', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.main.cancel_incomplete_order' => ['label' => '🗑 حذف سفارش ناتمام', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.main.admin' => ['label' => '🛠 مدیریت', 'buttonType' => 'reply_keyboard', 'category' => 'main'],
            'button.common.back' => ['label' => '🔙 بازگشت', 'buttonType' => 'inline', 'category' => 'common'],
            'button.common.cancel' => ['label' => '❌ انصراف', 'buttonType' => 'inline', 'category' => 'common'],
            'button.common.confirm' => ['label' => '✅ تایید', 'buttonType' => 'inline', 'category' => 'common'],
            'button.common.close' => ['label' => '❌ بستن', 'buttonType' => 'inline', 'category' => 'common'],
            'button.payment.check' => ['label' => '🔄 بررسی پرداخت', 'buttonType' => 'inline', 'category' => 'payment'],
            'button.payment.cancel' => ['label' => '❌ انصراف سفارش', 'buttonType' => 'inline', 'category' => 'payment'],
            'button.payment.upload_receipt' => ['label' => '✅ تایید و ارسال رسید', 'buttonType' => 'inline', 'category' => 'payment'],
            'button.payment.open_url' => ['label' => 'پرداخت آنلاین', 'buttonType' => 'inline', 'category' => 'payment'],
            'button.payment.manual_card' => ['label' => '💳 کارت به کارت', 'buttonType' => 'inline', 'category' => 'payment'],
            'button.payment.online' => ['label' => '🌐 پرداخت آنلاین', 'buttonType' => 'inline', 'category' => 'payment'],
            'button.order.track' => ['label' => 'پیگیری سفارش', 'buttonType' => 'inline', 'category' => 'order'],
            'button.order.resume' => ['label' => 'ادامه پرداخت', 'buttonType' => 'inline', 'category' => 'order'],
            'button.order.cancel' => ['label' => '❌ انصراف', 'buttonType' => 'inline', 'category' => 'order'],
            'button.order.confirm' => ['label' => '✅ تایید سفارش', 'buttonType' => 'inline', 'category' => 'order'],
            'button.service.subscription' => ['label' => '🔗 لینک اشتراک', 'buttonType' => 'inline', 'category' => 'service'],
            'button.service.qr' => ['label' => '📷 QR لینک اشتراک', 'buttonType' => 'inline', 'category' => 'service'],
            'button.service.configs' => ['label' => '📨 ارسال مجدد کانفیگ', 'buttonType' => 'inline', 'category' => 'service'],
            'button.service.view' => ['label' => 'مشاهده سرویس', 'buttonType' => 'inline', 'category' => 'service'],
            'button.service.renew' => ['label' => '🔄 تمدید سرویس', 'buttonType' => 'inline', 'category' => 'service'],
            'button.service.add_traffic' => ['label' => '➕ خرید حجم اضافه', 'buttonType' => 'inline', 'category' => 'service'],
            'button.service.refresh_usage' => ['label' => '🔄 بروزرسانی مصرف', 'buttonType' => 'inline', 'category' => 'service'],
        ];
    }

    private function englishButtons(): array
    {
        $buttons = $this->persianButtons();
        $labels = [
            'button.main.buy_service' => '🛒 Buy service',
            'button.main.my_services' => '📦 My services',
            'button.main.support' => '🎧 Support',
            'button.main.track_order' => '🔎 Track order',
            'button.main.continue_order' => '▶️ Continue order',
            'button.main.cancel_incomplete_order' => '🗑 Delete incomplete order',
            'button.main.admin' => '🛠 Admin',
            'button.common.back' => '🔙 Back',
            'button.common.cancel' => '❌ Cancel',
            'button.common.confirm' => '✅ Confirm',
            'button.common.close' => '❌ Close',
            'button.payment.check' => '🔄 Check payment',
            'button.payment.cancel' => '❌ Cancel order',
            'button.payment.upload_receipt' => '✅ Confirm and send receipt',
            'button.payment.open_url' => 'Pay online',
            'button.payment.manual_card' => '💳 Card transfer',
            'button.payment.online' => '🌐 Online payment',
            'button.order.track' => 'Track order',
            'button.order.resume' => 'Continue payment',
            'button.order.cancel' => '❌ Cancel',
            'button.order.confirm' => '✅ Confirm order',
            'button.service.subscription' => '🔗 Subscription link',
            'button.service.qr' => '📷 Subscription QR',
            'button.service.configs' => '📨 Resend config',
            'button.service.view' => 'View service',
            'button.service.renew' => '🔄 Renew service',
            'button.service.add_traffic' => '➕ Buy extra traffic',
            'button.service.refresh_usage' => '🔄 Refresh usage',
        ];
        foreach ($labels as $key => $label) {
            $buttons[$key]['label'] = $label;
        }

        return $buttons;
    }
}
