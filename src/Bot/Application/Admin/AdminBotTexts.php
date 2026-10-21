<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

/**
 * Default (Persian) texts and button labels of the bot admin area, merged into BotContentRegistry. Each can be changed
 * in the admin (bot message templates / button labels) like every other bot text.
 */
final class AdminBotTexts
{
    /**
     * @return array<string, array{title: string, body: string, category: string, variables?: list<string>}>
     */
    public static function messages(): array
    {
        $m = static fn (string $title, string $body, array $variables = []): array => ['title' => $title, 'body' => $body, 'category' => 'admin'] + ([] === $variables ? [] : ['variables' => $variables]);

        return [
            // General.
            'admin.menu_title' => $m('Admin menu', 'منوی مدیریت:'),
            'admin.store_title' => $m('Store management', "مدیریت فروشگاه:\nساخت و ویرایش کامل (پلن، کد تخفیف، درگاه، پنل، پیام همگانی) در پنل وب در دسترس است."),
            'admin.confirm' => $m('Confirm an admin change', "⚠️ تایید عملیات\n\n{{ action }}\n\nادامه می‌دهید؟", ['action']),
            'admin.cancelled' => $m('Admin action cancelled', 'لغو شد.'),
            'admin.done' => $m('Admin change done', '✅ انجام شد.'),
            'admin.not_found' => $m('Admin record not found', 'این مورد پیدا نشد.'),
            'admin.state_changed' => $m('Admin record changed meanwhile', 'وضعیت این مورد در این فاصله تغییر کرده است؛ دوباره از فهرست اقدام کنید.'),
            'admin.web_only' => $m('Admin web-only section', 'این بخش فقط در پنل وب در دسترس است.'),
            'admin.web_only_hint' => $m('Admin web-only hint', 'ℹ️ ساخت و ویرایش کامل در پنل وب.'),
            'admin.list_empty' => $m('Admin empty list', 'موردی برای نمایش وجود ندارد.'),
            'admin.page' => $m('Admin page number', 'صفحه {{ page }} از {{ pages }}', ['page', 'pages']),
            'admin.prompt_hint' => $m('Admin prompt hint', '(برای لغو دکمه «انصراف» را بزنید؛ این درخواست بعد از {{ minutes }} دقیقه منقضی می‌شود.)', ['minutes']),
            'admin.prompt_expired' => $m('Admin prompt expired', 'زمان پاسخ تمام شد. دوباره از منو شروع کنید.'),
            'admin.invalid_number' => $m('Admin invalid number', 'عدد معتبر نیست. یک عدد بین {{ min }} و {{ max }} بفرستید یا «انصراف» را بزنید.', ['min', 'max']),
            // Dashboard.
            'admin.dashboard' => $m('Admin dashboard', "📊 خلاصه وضعیت\n\nسفارش‌های امروز: {{ orders_today }}\nپرداخت‌های در انتظار: {{ pending }}\nنیازمند بررسی: {{ needs_review }}\nساخت سرویس ناموفق: {{ provisioning_failed }}\nسرویس‌های فعال: {{ active_services }}\nانقضا تا ۳ روز آینده: {{ expiring }}", ['orders_today', 'pending', 'needs_review', 'provisioning_failed', 'active_services', 'expiring']),
            // Orders.
            'admin.orders.title' => $m('Admin orders list', '🧾 سفارش‌ها ({{ filter }}) — {{ page_info }}', ['filter', 'page_info']),
            'admin.orders.line' => $m('Admin orders list line', "#{{ id }} | {{ status }} | {{ type }} | {{ amount }} تومان\n{{ user }} | {{ plan }} | {{ date }}", ['id', 'status', 'type', 'amount', 'user', 'plan', 'date']),
            'admin.orders.detail' => $m('Admin order detail', "🧾 سفارش #{{ id }}\nکد پیگیری: {{ tracking }}\nکاربر: {{ user }}\nپلن: {{ plan }}\nنوع: {{ type }}\nمبلغ: {{ amount }} تومان\nوضعیت: {{ status }}\nسرویس: {{ service }}\nثبت: {{ date }}", ['id', 'tracking', 'user', 'plan', 'type', 'amount', 'status', 'service', 'date']),
            'admin.orders.filter_title' => $m('Admin orders filter', 'وضعیت سفارش را انتخاب کنید:'),
            // Payments.
            'admin.payments.confirm_approve' => $m('Admin confirm approve payment', 'تایید پرداخت #{{ id }} به مبلغ {{ amount }} تومان ({{ user }})', ['id', 'amount', 'user']),
            'admin.payments.confirm_retry' => $m('Admin confirm retry provisioning', 'تلاش مجدد ساخت سرویس برای پرداخت #{{ id }} ({{ user }})', ['id', 'user']),
            'admin.payments.confirm_reject' => $m('Admin confirm reject payment', 'رد پرداخت #{{ id }} به مبلغ {{ amount }} تومان ({{ user }})', ['id', 'amount', 'user']),
            'admin.payments.confirm_refunded' => $m('Admin confirm reject refunded', 'رد پرداخت #{{ id }} با علامت «بازپرداخت شده» ({{ amount }} تومان، {{ user }})', ['id', 'amount', 'user']),
            'admin.payments.rejected_refunded' => $m('Admin payment rejected as refunded', '❌ پرداخت رد شد (بازپرداخت شده).'),
            // Customers.
            'admin.customers.title' => $m('Admin customers list', '👥 مشتریان — {{ page_info }}', ['page_info']),
            'admin.customers.line' => $m('Admin customers list line', '#{{ id }} | {{ name }} | @{{ username }} | {{ telegram_id }} | {{ mobile }}', ['id', 'name', 'username', 'telegram_id', 'mobile']),
            'admin.customers.search_prompt' => $m('Admin customer search prompt', 'شناسه تلگرام، نام کاربری (با @)، نام یا شماره موبایل مشتری را بفرستید:'),
            'admin.customers.search_results' => $m('Admin customer search results', '🔍 نتایج جستجو برای «{{ query }}»:', ['query']),
            'admin.customers.search_empty' => $m('Admin customer search empty', 'مشتری‌ای با «{{ query }}» پیدا نشد.', ['query']),
            // Plans and trial claims.
            'admin.plans.title' => $m('Admin plans list', '📋 پلن‌ها — {{ page_info }}', ['page_info']),
            'admin.plans.line' => $m('Admin plans list line', '{{ state }} #{{ id }} {{ title }} | {{ price }} تومان', ['state', 'id', 'title', 'price']),
            'admin.plans.detail' => $m('Admin plan detail', "📋 پلن #{{ id }}: {{ title }}\nوضعیت: {{ state }}\nقیمت: {{ price }} تومان\nمدت: {{ days }} روز | حجم: {{ traffic }} GB", ['id', 'title', 'state', 'price', 'days', 'traffic']),
            'admin.plans.confirm_activate' => $m('Admin confirm activate plan', 'فعال کردن پلن «{{ title }}» (برای فروش نمایش داده می‌شود)', ['title']),
            'admin.plans.confirm_deactivate' => $m('Admin confirm deactivate plan', 'غیرفعال کردن پلن «{{ title }}» (دیگر فروخته نمی‌شود)', ['title']),
            'admin.plans.price_prompt' => $m('Admin plan price prompt', "قیمت جدید پلن «{{ title }}» را به تومان بفرستید.\nقیمت فعلی: {{ price }} — مجاز: {{ min }} تا {{ max }}", ['title', 'price', 'min', 'max']),
            'admin.plans.confirm_price' => $m('Admin confirm plan price', 'تغییر قیمت پلن «{{ title }}»: {{ old }} ← {{ new }} تومان', ['title', 'old', 'new']),
            'admin.plans.price_done' => $m('Admin plan price changed', '✅ قیمت پلن «{{ title }}» از {{ old }} به {{ new }} تومان تغییر کرد.', ['title', 'old', 'new']),
            'admin.plans.bulk_prompt' => $m('Admin bulk price prompt', 'درصد تغییر همه قیمت‌ها (قیمت، قیمت هر گیگ و هر روز) را بفرستید؛ مثلاً 10 برای افزایش یا -5 برای کاهش (بین {{ min }} و {{ max }}):', ['min', 'max']),
            'admin.plans.confirm_bulk' => $m('Admin confirm bulk price', 'تغییر {{ percent }}٪ قیمت‌ها: {{ changes }} قیمت در {{ plans }} پلن تغییر می‌کند.', ['percent', 'changes', 'plans']),
            'admin.plans.bulk_none' => $m('Admin bulk price no change', 'با این درصد هیچ قیمتی تغییر نمی‌کند.'),
            'admin.plans.bulk_done' => $m('Admin bulk price done', '✅ {{ changes }} قیمت تغییر کرد.', ['changes']),
            'admin.trial_claims.title' => $m('Admin trial claims list', '🎁 درخواست‌های تست — {{ page_info }}', ['page_info']),
            'admin.trial_claims.line' => $m('Admin trial claims list line', '#{{ id }} | {{ status }} | {{ plan }} | {{ user }} | {{ date }}', ['id', 'status', 'plan', 'user', 'date']),
            // Discount codes.
            'admin.discounts.title' => $m('Admin discount codes list', '🏷 کدهای تخفیف — {{ page_info }}', ['page_info']),
            'admin.discounts.line' => $m('Admin discount codes list line', '{{ state }} {{ code }} | {{ value }} | استفاده: {{ used }} از {{ max }}', ['state', 'code', 'value', 'used', 'max']),
            'admin.discounts.confirm_activate' => $m('Admin confirm activate discount', 'فعال کردن کد تخفیف {{ code }}', ['code']),
            'admin.discounts.confirm_deactivate' => $m('Admin confirm deactivate discount', 'غیرفعال کردن کد تخفیف {{ code }}', ['code']),
            // Payment methods and gateways.
            'admin.methods.title' => $m('Admin payment methods list', '💳 روش‌های پرداخت (به ترتیب نمایش به مشتری):'),
            'admin.methods.line' => $m('Admin payment methods list line', '{{ state }} {{ position }}. {{ title }} ({{ gateway }})', ['state', 'position', 'title', 'gateway']),
            'admin.methods.confirm_activate' => $m('Admin confirm activate method', 'فعال کردن روش پرداخت «{{ title }}»', ['title']),
            'admin.methods.confirm_deactivate' => $m('Admin confirm deactivate method', 'غیرفعال کردن روش پرداخت «{{ title }}»', ['title']),
            'admin.methods.confirm_up' => $m('Admin confirm move method up', 'بردن «{{ title }}» یک ردیف بالاتر', ['title']),
            'admin.methods.confirm_down' => $m('Admin confirm move method down', 'بردن «{{ title }}» یک ردیف پایین‌تر', ['title']),
            'admin.methods.cannot_move' => $m('Admin method cannot move', 'این روش پرداخت در همین جهت جابه‌جا نمی‌شود.'),
            'admin.gateways.title' => $m('Admin gateways list', '🏦 درگاه‌های پرداخت:'),
            'admin.gateways.line' => $m('Admin gateways list line', '{{ state }} #{{ id }} {{ title }} ({{ type }})', ['state', 'id', 'title', 'type']),
            'admin.gateways.confirm_activate' => $m('Admin confirm activate gateway', 'فعال کردن درگاه «{{ title }}»', ['title']),
            'admin.gateways.confirm_deactivate' => $m('Admin confirm deactivate gateway', 'غیرفعال کردن درگاه «{{ title }}»', ['title']),
            'admin.gateways.web_only' => $m('Admin gateways web only', 'ℹ️ تنظیمات و کلیدهای درگاه فقط در پنل وب.'),
            // Settings.
            'admin.settings.title' => $m('Admin settings list', '⚙️ تنظیمات (برای تغییر روی هر مورد بزنید):'),
            'admin.settings.line' => $m('Admin settings list line', '{{ label }}: {{ value }}', ['label', 'value']),
            'admin.settings.confirm_change' => $m('Admin confirm setting change', '{{ label }}: {{ old }} ← {{ new }}', ['label', 'old', 'new']),
            'admin.settings.prompt' => $m('Admin setting value prompt', "مقدار جدید «{{ label }}» را بفرستید.\nمقدار فعلی: {{ value }} — مجاز: {{ min }} تا {{ max }}", ['label', 'value', 'min', 'max']),
            'admin.setting.sales_new_orders' => $m('Setting: new orders', 'فروش سرویس جدید'),
            'admin.setting.sales_renewals' => $m('Setting: renewals', 'تمدید سرویس'),
            'admin.setting.sales_add_traffic' => $m('Setting: add traffic sales', 'فروش حجم اضافه'),
            'admin.setting.traffic_addon' => $m('Setting: traffic add-on', 'امکان خرید حجم اضافه'),
            'admin.setting.auto_sync_usage' => $m('Setting: usage sync', 'همگام‌سازی خودکار مصرف'),
            'admin.setting.auto_check_expiry' => $m('Setting: expiry check', 'بررسی خودکار انقضا'),
            'admin.setting.auto_notifications' => $m('Setting: notifications', 'ارسال خودکار اعلان‌ها'),
            'admin.setting.auto_broadcasts' => $m('Setting: scheduled broadcasts', 'ارسال خودکار پیام‌های زمان‌بندی‌شده'),
            'admin.setting.auto_suspend_expired' => $m('Setting: suspend expired', 'تعلیق خودکار سرویس‌های منقضی'),
            'admin.setting.auto_suspend_traffic' => $m('Setting: suspend exhausted', 'تعلیق خودکار با اتمام حجم'),
            'admin.setting.auto_expire_orders' => $m('Setting: expire incomplete orders', 'لغو خودکار سفارش‌های ناتمام'),
            'admin.setting.batch_limit' => $m('Setting: batch limit', 'تعداد در هر نوبت اتوماسیون'),
            'admin.setting.incomplete_hours' => $m('Setting: incomplete order hours', 'مهلت سفارش ناتمام (ساعت)'),
            'admin.setting.addon_min_gb' => $m('Setting: add-on min GB', 'حداقل حجم اضافه (GB)'),
            'admin.setting.addon_max_gb' => $m('Setting: add-on max GB', 'حداکثر حجم اضافه (GB)'),
            'admin.setting.addon_price_per_gb' => $m('Setting: add-on price per GB', 'قیمت هر گیگ حجم اضافه (تومان)'),
            'admin.setting.global_discount' => $m('Setting: global discount', 'تخفیف سراسری (٪)'),
            // Panels.
            'admin.panels.title' => $m('Admin panels list', '🖥 پنل‌ها:'),
            'admin.panels.line' => $m('Admin panels list line', '{{ state }} #{{ id }} {{ title }} | {{ mode }} | {{ health }}', ['state', 'id', 'title', 'mode', 'health']),
            'admin.panels.degraded' => $m('Admin panel degraded', '⚠️ اختلال (فروش متوقف)'),
            'admin.panels.test_result' => $m('Admin panel test result', "🔌 نتیجه تست پنل «{{ title }}»\nدسترسی: {{ reachable }}\nTLS: {{ tls }}\nاحراز هویت: {{ auth }}\nنوع API: {{ mode }}\nتاخیر: {{ latency }}\nتعداد اینباند: {{ inbounds }}\n\n{{ verdict }}", ['title', 'reachable', 'tls', 'auth', 'mode', 'latency', 'inbounds', 'verdict']),
            'admin.panels.test_ok' => $m('Admin panel test ok', '✅ اتصال برقرار است.'),
            'admin.panels.test_failed' => $m('Admin panel test failed', '❌ اتصال ناموفق ({{ failure }}). جزئیات در پنل وب.', ['failure']),
            'admin.panels.confirm_activate' => $m('Admin confirm activate panel', 'فعال کردن پنل «{{ title }}»', ['title']),
            'admin.panels.confirm_deactivate' => $m('Admin confirm deactivate panel', 'غیرفعال کردن پنل «{{ title }}» (پلن‌هایش دیگر فروخته نمی‌شوند)', ['title']),
            // Broadcasts.
            'admin.broadcasts.title' => $m('Admin broadcasts list', '📣 آخرین پیام‌های همگانی:'),
            'admin.broadcasts.line' => $m('Admin broadcasts list line', "#{{ id }} {{ title }} | {{ status }}\nکل: {{ total }} | ارسال: {{ sent }} | ناموفق: {{ failed }} | رد شده: {{ skipped }}", ['id', 'title', 'status', 'total', 'sent', 'failed', 'skipped']),
            'admin.broadcasts.compose_web' => $m('Admin broadcasts compose web', 'ℹ️ ساخت پیام همگانی جدید فقط از پنل وب.'),
            'admin.broadcasts.confirm_cancel' => $m('Admin confirm cancel broadcast', 'لغو ارسال پیام همگانی #{{ id }} «{{ title }}»', ['id', 'title']),
            'admin.broadcasts.confirm_retry' => $m('Admin confirm retry broadcast', 'ارسال مجدد {{ failed }} پیام ناموفق از #{{ id }} «{{ title }}»', ['id', 'title', 'failed']),
            'admin.broadcasts.retry_done' => $m('Admin broadcast retry done', 'ارسال مجدد: {{ sent }} موفق، {{ failed }} ناموفق، {{ pending }} در صف.', ['sent', 'failed', 'pending']),
        ];
    }

    /**
     * @return array<string, array{label: string, buttonType: string, category: string}>
     */
    public static function buttons(): array
    {
        $b = static fn (string $label): array => ['label' => $label, 'buttonType' => 'inline', 'category' => 'admin'];

        return [
            'button.admin.payments_attention' => $b('⚠️ پرداخت‌های نیازمند رسیدگی'),
            'button.admin.payments_pending' => $b('💳 پرداخت‌های در انتظار'),
            'button.admin.dashboard' => $b('📊 خلاصه وضعیت'),
            'button.admin.orders' => $b('🧾 سفارش‌ها'),
            'button.admin.customers' => $b('👥 مشتریان'),
            'button.admin.plans' => $b('📋 پلن‌ها'),
            'button.admin.trial_claims' => $b('🎁 درخواست‌های تست'),
            'button.admin.discounts' => $b('🏷 کدهای تخفیف'),
            'button.admin.payment_methods' => $b('💳 روش‌های پرداخت'),
            'button.admin.gateways' => $b('🏦 درگاه‌ها'),
            'button.admin.settings' => $b('⚙️ تنظیمات'),
            'button.admin.panels' => $b('🖥 پنل‌ها'),
            'button.admin.broadcasts' => $b('📣 پیام‌های همگانی'),
            'button.admin.back' => $b('🔙 بازگشت'),
            'button.admin.prev' => $b('« قبلی'),
            'button.admin.next' => $b('بعدی »'),
            'button.admin.confirm' => $b('✅ تایید'),
            'button.admin.cancel' => $b('❌ انصراف'),
            'button.admin.activate' => $b('▶️ فعال کردن'),
            'button.admin.deactivate' => $b('⏸ غیرفعال کردن'),
            'button.admin.change_price' => $b('💰 تغییر قیمت'),
            'button.admin.bulk_price' => $b('📈 تغییر گروهی قیمت (٪)'),
            'button.admin.search' => $b('🔍 جستجو'),
            'button.admin.filter' => $b('🔎 فیلتر وضعیت'),
            'button.admin.all' => $b('همه'),
            'button.admin.up' => $b('⬆️'),
            'button.admin.down' => $b('⬇️'),
            'button.admin.reject_refunded' => $b('↩️ رد (بازپرداخت شده)'),
        ];
    }
}
