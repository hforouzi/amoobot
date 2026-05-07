<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\Plan;

class TelegramKeyboardFactory
{
    /**
     * @return array<string, mixed>
     */
    public function mainReplyKeyboard(bool $isAdmin): array
    {
        $keyboard = [
            [
                ['text' => '🛒 خرید سرویس'],
                ['text' => '📦 سرویسهای من'],
            ],
            [
                ['text' => '🎧 پشتیبانی'],
            ],
        ];

        if ($isAdmin) {
            $keyboard[1][] = ['text' => '🛠 مدیریت'];
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function removeReplyKeyboard(): array
    {
        return [
            'remove_keyboard' => true,
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function mainMenu(bool $isAdmin = false): array
    {
        $rows = [
            [
                ['text' => '🛒 خرید سرویس', 'callback_data' => 'buy_service'],
            ],
            [
                ['text' => '📦 سرویسهای من', 'callback_data' => 'my_services'],
            ],
            [
                ['text' => '🎧 پشتیبانی', 'callback_data' => 'support'],
            ],
        ];

        if ($isAdmin) {
            $rows[] = [
                ['text' => '🛠 مدیریت', 'callback_data' => 'admin_menu'],
            ];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param list<Plan> $plans
     *
     * @return array<string, array<array<array<string, string>>>>
     */
    public function plansMenu(array $plans): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            $rows[] = [[
                'text' => sprintf('%s - %d تومان', $plan->getTitle(), $plan->getPrice()),
                'callback_data' => 'select_plan:'.$plan->getId(),
            ]];
        }

        $rows[] = [[
            'text' => '⬅️ منوی اصلی',
            'callback_data' => 'main_menu',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function backToMainMenu(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '⬅️ منوی اصلی', 'callback_data' => 'main_menu'],
            ]],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function paymentInstructionsMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ بازگشت به پلن‌ها', 'callback_data' => 'buy_service'],
                ],
                [
                    ['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function adminPaymentActions(int $paymentId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '✅ تایید پرداخت',
                    'callback_data' => 'admin_confirm_payment:'.$paymentId,
                ], [
                    'text' => '❌ رد پرداخت',
                    'callback_data' => 'admin_reject_payment:'.$paymentId,
                ]],
                [[
                    'text' => '🔙 پرداختهای در انتظار',
                    'callback_data' => 'admin_pending_payments',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function adminMenu(): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '💳 پرداختهای در انتظار',
                    'callback_data' => 'admin_pending_payments',
                ]],
                [[
                    'text' => '👥 لیست کاربران',
                    'callback_data' => 'admin_users',
                ]],
                [[
                    'text' => '📦 لیست سرویسها',
                    'callback_data' => 'admin_services',
                ]],
                [[
                    'text' => '🧾 آخرین سفارشها',
                    'callback_data' => 'admin_orders',
                ]],
                [[
                    'text' => '🔙 بازگشت به منوی اصلی',
                    'callback_data' => 'main_menu',
                ]],
            ],
        ];
    }

    /**
     * @param list<int> $paymentIds
     *
     * @return array<string, array<array<array<string, string>>>>
     */
    public function adminPendingPayments(array $paymentIds): array
    {
        $rows = [];
        foreach ($paymentIds as $paymentId) {
            $rows[] = [[
                'text' => sprintf('مشاهده پرداخت #%d', $paymentId),
                'callback_data' => 'admin_view_payment:'.$paymentId,
            ]];
        }

        $rows[] = [[
            'text' => '🔙 بازگشت به مدیریت',
            'callback_data' => 'admin_menu',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function backToAdminMenu(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'admin_menu'],
            ]],
        ];
    }

    /**
     * @param list<int> $serviceIds
     *
     * @return array<string, array<array<array<string, string>>>>
     */
    public function userServicesList(array $serviceIds): array
    {
        $rows = [];
        foreach ($serviceIds as $serviceId) {
            $rows[] = [[
                'text' => sprintf('📦 سرویس #%d', $serviceId),
                'callback_data' => 'service_view:'.$serviceId,
            ]];
        }

        $rows[] = [[
            'text' => '🏠 منوی اصلی',
            'callback_data' => 'main_menu',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function userServiceDetail(int $serviceId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '🔗 لینک اشتراک',
                    'callback_data' => 'service_subscription:'.$serviceId,
                ]],
                [[
                    'text' => '📨 ارسال مجدد کانفیگ',
                    'callback_data' => 'service_resend_config:'.$serviceId,
                ]],
                [[
                    'text' => '🔄 بروزرسانی اطلاعات',
                    'callback_data' => 'service_refresh:'.$serviceId,
                ]],
                [[
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'my_services',
                ]],
            ],
        ];
    }

    /**
     * @param list<int> $serviceIds
     *
     * @return array<string, array<array<array<string, string>>>>
     */
    public function adminServicesList(array $serviceIds): array
    {
        $rows = [];
        foreach ($serviceIds as $serviceId) {
            $rows[] = [[
                'text' => sprintf('⚙️ سرویس #%d', $serviceId),
                'callback_data' => 'admin_service_view:'.$serviceId,
            ]];
        }

        $rows[] = [[
            'text' => '🔙 بازگشت به مدیریت',
            'callback_data' => 'admin_menu',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function adminServiceDetail(int $serviceId, int $userId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '⏸ غیرفعال',
                    'callback_data' => 'service_suspend:'.$serviceId,
                ], [
                    'text' => '▶️ فعال',
                    'callback_data' => 'service_activate:'.$serviceId,
                ]],
                [[
                    'text' => '📅 تمدید',
                    'callback_data' => 'service_extend_menu:'.$serviceId,
                ], [
                    'text' => '➕ افزایش حجم',
                    'callback_data' => 'service_add_traffic_menu:'.$serviceId,
                ]],
                [[
                    'text' => '🔄 ریست مصرف',
                    'callback_data' => 'service_reset_usage:'.$serviceId,
                ], [
                    'text' => '📨 ارسال مجدد',
                    'callback_data' => 'service_resend_config:'.$serviceId,
                ]],
                [[
                    'text' => '🗑 حذف سرویس',
                    'callback_data' => 'service_delete:'.$serviceId,
                ]],
                [[
                    'text' => '👤 مشاهده کاربر',
                    'callback_data' => 'admin_user_view:'.$userId,
                ], [
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'admin_services',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function serviceExtendMenu(int $serviceId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '+7 روز',
                    'callback_data' => 'service_extend:'.$serviceId.':7',
                ]],
                [[
                    'text' => '+30 روز',
                    'callback_data' => 'service_extend:'.$serviceId.':30',
                ]],
                [[
                    'text' => '+90 روز',
                    'callback_data' => 'service_extend:'.$serviceId.':90',
                ]],
                [[
                    'text' => '🔙 بازگشت به سرویس',
                    'callback_data' => 'admin_service_view:'.$serviceId,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function serviceAddTrafficMenu(int $serviceId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '+10GB',
                    'callback_data' => 'service_add_traffic:'.$serviceId.':10',
                ]],
                [[
                    'text' => '+50GB',
                    'callback_data' => 'service_add_traffic:'.$serviceId.':50',
                ]],
                [[
                    'text' => '+100GB',
                    'callback_data' => 'service_add_traffic:'.$serviceId.':100',
                ]],
                [[
                    'text' => '🔙 بازگشت به سرویس',
                    'callback_data' => 'admin_service_view:'.$serviceId,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function adminUserDetail(int $userId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '📦 سرویسهای کاربر',
                    'callback_data' => 'admin_user_services:'.$userId,
                ]],
                [[
                    'text' => '🧾 سفارشهای کاربر',
                    'callback_data' => 'admin_user_orders:'.$userId,
                ]],
                [[
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'admin_services',
                ]],
            ],
        ];
    }
}
