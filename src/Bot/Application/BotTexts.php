<?php

declare(strict_types=1);

namespace App\Bot\Application;

final class BotTexts
{
    public const MAIN_MENU = 'منوی اصلی:';
    public const WELCOME = "خوش آمدید 🌟\nاز منوی زیر می‌توانید سرویس خریداری کنید یا سرویس‌های خود را ببینید.";
    public const SUPPORT = 'برای پشتیبانی با ادمین در ارتباط باشید.';
    public const NO_PLANS = 'در حال حاضر پلن فعالی موجود نیست.';
    public const SELECT_PLAN = 'لطفا یک پلن را انتخاب کنید:';
    public const NO_SERVICES = 'شما هنوز سرویس فعالی ندارید.';
    public const RECEIPT_SUBMITTED = 'اطلاعات پرداخت شما ثبت شد و پس از بررسی تایید می‌شود.';
    public const INVALID_PLAN = 'پلن انتخاب شده معتبر نیست.';
    public const UNKNOWN_COMMAND = 'دستور نامعتبر است. لطفا از منو استفاده کنید.';
    public const PAYMENT_CONFIRMED_TEMPLATE = "✅ پرداخت شما تایید شد.\n\nSubscription URL:\n%s\n\nConfig:\n%s";
    public const PAYMENT_REJECTED = '❌ پرداخت شما رد شد. لطفا مجدد رسید معتبر ارسال کنید یا با پشتیبانی تماس بگیرید.';
    public const ADMIN_UNAUTHORIZED = 'Unauthorized';
    public const ADMIN_PAYMENT_NOT_FOUND = 'پرداخت مورد نظر یافت نشد.';
    public const ADMIN_PAYMENT_CONFIRMED = '✅ پرداخت تایید شد.';
    public const ADMIN_PAYMENT_REJECTED = '❌ پرداخت رد شد.';
    public const ADMIN_PAYMENT_ALREADY_PROCESSED = 'این پرداخت قبلا پردازش شده است.';
}
