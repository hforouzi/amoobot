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
}
