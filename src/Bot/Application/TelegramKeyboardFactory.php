<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\Plan;

class TelegramKeyboardFactory
{
    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function mainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🛒 خرید سرویس', 'callback_data' => 'buy_service'],
                ],
                [
                    ['text' => '📦 سرویسهای من', 'callback_data' => 'my_services'],
                ],
                [
                    ['text' => '🎧 پشتیبانی', 'callback_data' => 'support'],
                ],
            ],
        ];
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
            'inline_keyboard' => [[
                ['text' => '✅ تایید پرداخت', 'callback_data' => 'admin_confirm_payment:'.$paymentId],
                ['text' => '❌ رد پرداخت', 'callback_data' => 'admin_reject_payment:'.$paymentId],
            ]],
        ];
    }
}
