<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Entity\Plan;

class TelegramKeyboardFactory
{
    /**
     * @return array<string, mixed>
     */
    public function mainReplyKeyboard(bool $isAdmin, bool $hasIncompleteOrder = false, bool $hasTrackableOrder = false): array
    {
        $keyboard = [];

        if ($hasIncompleteOrder) {
            $keyboard[] = [
                ['text' => '▶️ ادامه سفارش قبلی'],
                ['text' => '🗑 حذف سفارش ناتمام'],
            ];
        }

        if ($hasTrackableOrder) {
            $keyboard[] = [
                ['text' => '🔎 پیگیری سفارش'],
            ];
        }

        $keyboard[] = [
            ['text' => '🛒 خرید سرویس'],
            ['text' => '📦 سرویسهای من'],
        ];

        $supportRow = [['text' => '🎧 پشتیبانی']];
        if ($isAdmin) {
            $supportRow[] = ['text' => '🛠 مدیریت'];
        }
        $keyboard[] = $supportRow;

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * Chunk flat button list into rows of $columns columns.
     *
     * @param list<array<string, string>> $buttons
     *
     * @return list<list<array<string, string>>>
     */
    public function inlineKeyboardRows(array $buttons, int $columns = 2): array
    {
        return array_chunk($buttons, $columns);
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
        $planButtons = [];

        foreach ($plans as $plan) {
            $planPriceLabel = $plan->isCustomizable()
                ? 'سفارشی'
                : sprintf('%d تومان', $plan->getPrice());
            $planButtons[] = [
                'text' => sprintf('%s - %s', $plan->getTitle(), $planPriceLabel),
                'callback_data' => 'select_plan:'.$plan->getId(),
            ];
        }

        foreach ($this->inlineKeyboardRows($planButtons, 2) as $row) {
            $rows[] = $row;
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
    public function paymentMethodSelectionMenu(int $planId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '💳 کارت به کارت',
                    'callback_data' => 'select_payment_method:'.$planId.':manual_card',
                ]],
                [[
                    'text' => '🔙 بازگشت به پلنها',
                    'callback_data' => 'buy_service',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function paymentMethodSelectionForDraftMenu(int $draftId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '💳 کارت به کارت',
                    'callback_data' => 'select_payment_method_draft:'.$draftId.':manual_card',
                ]],
                [[
                    'text' => '❌ انصراف',
                    'callback_data' => 'custom_order_cancel:'.$draftId,
                ]],
            ],
        ];
    }

    /**
     * @param list<StorePaymentMethod> $methods
     *
     * @return array<string, array<array<array<string, string>>>>
     */
    public function paymentGatewaySelectionMenu(int $orderId, array $methods, string $backCallback, string $cancelCallback): array
    {
        $rows = [];
        $methodButtons = [];
        foreach ($methods as $method) {
            if (!$method instanceof StorePaymentMethod) {
                continue;
            }

            $gateway = $method->getGateway();
            $text = '' !== trim($method->getTitle()) ? $method->getTitle() : match ($gateway->getType()) {
                PaymentGatewayType::MANUAL_CARD => '💳 کارت به کارت',
                PaymentGatewayType::ZIBAL => '🌐 پرداخت آنلاین (زیبال)',
                PaymentGatewayType::CUSTOM_API => '🌐 پرداخت آنلاین',
                default => $gateway->getTitle(),
            };

            $methodButtons[] = ['text' => $text, 'callback_data' => 'select_store_payment_method:'.$orderId.':'.$method->getId()];
        }

        foreach ($this->inlineKeyboardRows($methodButtons, 2) as $row) {
            $rows[] = $row;
        }

        $rows[] = [
            ['text' => '🔙 بازگشت', 'callback_data' => $backCallback],
            ['text' => '❌ انصراف', 'callback_data' => $cancelCallback],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function customOrderSummaryMenu(int $draftId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '✅ تایید و انتخاب روش پرداخت',
                    'callback_data' => 'custom_order_confirm:'.$draftId,
                ]],
                [
                    ['text' => '🔙 بازگشت', 'callback_data' => 'draft_back:'.$draftId],
                    ['text' => '❌ انصراف', 'callback_data' => 'draft_cancel:'.$draftId],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function customOrderInputMenu(int $draftId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت', 'callback_data' => 'buy_service'],
                    ['text' => '❌ انصراف', 'callback_data' => 'custom_order_cancel:'.$draftId],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function customOrderUsernameInputMenu(int $draftId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '❌ انصراف',
                    'callback_data' => 'draft_cancel:'.$draftId,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function customOrderStepMenu(int $draftId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'draft_back:'.$draftId,
                ]],
                [[
                    'text' => '❌ انصراف',
                    'callback_data' => 'draft_cancel:'.$draftId,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function paymentActionMenu(int $paymentId, int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '✅ تایید و ارسال رسید',
                    'callback_data' => 'payment_submit_receipt:'.$paymentId,
                ]],
                [
                    ['text' => '🔙 بازگشت به روشهای پرداخت', 'callback_data' => 'order_payment_methods:'.$orderId],
                    ['text' => '🔙 بازگشت به خلاصه سفارش', 'callback_data' => 'order_summary:'.$orderId],
                ],
                [[
                    'text' => '❌ انصراف سفارش',
                    'callback_data' => 'order_cancel:'.$orderId,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function paymentOnlineActionMenu(int $paymentId, int $orderId, string $paymentUrl): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => 'پرداخت آنلاین',
                    'url' => $paymentUrl,
                ]],
                [[
                    'text' => '🔄 بررسی پرداخت',
                    'callback_data' => 'payment_check:'.$paymentId,
                ]],
                [
                    ['text' => '🔙 بازگشت به روشهای پرداخت', 'callback_data' => 'order_payment_methods:'.$orderId],
                    ['text' => '🔙 بازگشت به خلاصه سفارش', 'callback_data' => 'order_summary:'.$orderId],
                ],
                [[
                    'text' => '❌ انصراف سفارش',
                    'callback_data' => 'order_cancel:'.$orderId,
                ]],
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
                ], [
                    'text' => '👥 لیست کاربران',
                    'callback_data' => 'admin_users',
                ]],
                [[
                    'text' => '📦 لیست سرویسها',
                    'callback_data' => 'admin_services',
                ], [
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
    public function userServiceDetail(int $serviceId, bool $canRenew = true, bool $canAddTraffic = true): array
    {
        $rows = [
            [
                ['text' => '🔗 لینک اشتراک', 'callback_data' => 'service_subscription:'.$serviceId],
                ['text' => '📷 QR لینک اشتراک', 'callback_data' => 'service_subscription_qr:'.$serviceId],
            ],
            [
                ['text' => '📨 ارسال مجدد کانفیگ', 'callback_data' => 'service_resend_config:'.$serviceId],
                ['text' => '🔄 بروزرسانی مصرف', 'callback_data' => 'service_sync_usage:'.$serviceId],
            ],
        ];

        $actionButtons = [];
        if ($canRenew) {
            $actionButtons[] = ['text' => '🔄 تمدید سرویس', 'callback_data' => 'service_renew:'.$serviceId];
        }
        if ($canAddTraffic) {
            $actionButtons[] = ['text' => '➕ خرید حجم اضافه', 'callback_data' => 'service_add_traffic_order:'.$serviceId];
        }
        foreach ($this->inlineKeyboardRows($actionButtons, 2) as $row) {
            $rows[] = $row;
        }

        $rows[] = [[
            'text' => '🔙 بازگشت',
            'callback_data' => 'my_services',
        ]];

        return ['inline_keyboard' => $rows];
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
                    'text' => '🔄 تمدید سرویس',
                    'callback_data' => 'service_renew:'.$serviceId,
                ]],
                [[
                    'text' => '🔄 ریست مصرف',
                    'callback_data' => 'service_reset_usage:'.$serviceId,
                ], [
                    'text' => '🔄 بروزرسانی مصرف',
                    'callback_data' => 'service_sync_usage:'.$serviceId,
                ]],
                [[
                    'text' => '📨 ارسال مجدد',
                    'callback_data' => 'service_resend_config:'.$serviceId,
                ]],
                [[
                    'text' => '🗑 حذف سرویس',
                    'callback_data' => 'service_delete:'.$serviceId,
                ]],
                [[
                    'text' => '👤 مشاهده کاربر',
                    'callback_data' => 'admin_user_view:'.$userId.':'.$serviceId,
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
    public function adminUserDetail(int $userId, ?int $backServiceId = null): array
    {
        $suffix = null === $backServiceId ? '' : ':'.$backServiceId;
        $backCallback = null === $backServiceId ? 'admin_services' : 'admin_service_view:'.$backServiceId;

        return [
            'inline_keyboard' => [
                [[
                    'text' => '📦 سرویسهای کاربر',
                    'callback_data' => 'admin_user_services:'.$userId.$suffix,
                ]],
                [[
                    'text' => '🧾 سفارشهای کاربر',
                    'callback_data' => 'admin_user_orders:'.$userId.$suffix,
                ]],
                [[
                    'text' => '🔙 بازگشت',
                    'callback_data' => $backCallback,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function serviceDeleteConfirmation(int $serviceId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، حذف کن', 'callback_data' => 'service_delete_confirm:'.$serviceId],
                    ['text' => '❌ انصراف', 'callback_data' => 'admin_service_view:'.$serviceId],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function renewalSummary(int $serviceId, bool $adminMode = false): array
    {
        $cancelCallback = $adminMode ? 'admin_service_view:'.$serviceId : 'service_view:'.$serviceId;

        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و پرداخت تمدید', 'callback_data' => 'renewal_confirm:'.$serviceId],
                    ['text' => '❌ انصراف', 'callback_data' => $cancelCallback],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function addTrafficSummary(int $draftId, int $serviceId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و پرداخت', 'callback_data' => 'add_traffic_confirm:'.$draftId],
                    ['text' => '❌ انصراف', 'callback_data' => 'service_view:'.$serviceId],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function discountCodePrompt(int $draftId, string $cancelCallback): array
    {
        $rows = [];
        if (str_starts_with($cancelCallback, 'draft_cancel:')) {
            $rows[] = [[
                'text' => '🔙 بازگشت به خلاصه سفارش',
                'callback_data' => 'draft_back:'.$draftId,
            ]];
        }

        $rows[] = [
            ['text' => '🎟 وارد کردن کد تخفیف', 'callback_data' => 'discount_enter:'.$draftId],
            ['text' => 'ادامه بدون کد تخفیف', 'callback_data' => 'discount_skip:'.$draftId],
        ];
        $rows[] = [[
            'text' => '❌ انصراف',
            'callback_data' => $cancelCallback,
        ]];

        return [
            'inline_keyboard' => $rows,
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function discountCodePromptForOrder(int $orderId, string $cancelCallback): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '🔙 بازگشت به خلاصه سفارش',
                    'callback_data' => 'order_summary:'.$orderId,
                ]],
                [
                    ['text' => '🎟 وارد کردن کد تخفیف', 'callback_data' => 'discount_enter_order:'.$orderId],
                    ['text' => 'ادامه بدون کد تخفیف', 'callback_data' => 'discount_skip_order:'.$orderId],
                ],
                [[
                    'text' => '❌ انصراف',
                    'callback_data' => $cancelCallback,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function discountCodeInputMenu(int $draftId): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '🔙 بازگشت به انتخاب تخفیف',
                    'callback_data' => 'draft_back:'.$draftId,
                ]],
                [[
                    'text' => '❌ انصراف',
                    'callback_data' => 'draft_cancel:'.$draftId,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function discountCodeInputMenuForOrder(int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به خلاصه سفارش', 'callback_data' => 'order_summary:'.$orderId],
                    ['text' => '❌ انصراف', 'callback_data' => 'order_cancel:'.$orderId],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function incompleteOrderPrompt(int $id): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '▶️ ادامه سفارش', 'callback_data' => 'resume_incomplete_order:'.$id],
                    ['text' => '🗑 حذف سفارش ناتمام', 'callback_data' => 'cancel_incomplete_order:'.$id],
                ],
                [[
                    'text' => '➕ سفارش جدید',
                    'callback_data' => 'start_new_order',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function orderSummaryMenu(int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🎟 وارد کردن کد تخفیف', 'callback_data' => 'discount_enter_order:'.$orderId],
                    ['text' => 'ادامه بدون کد تخفیف', 'callback_data' => 'discount_skip_order:'.$orderId],
                ],
                [[
                    'text' => '❌ انصراف',
                    'callback_data' => 'order_cancel:'.$orderId,
                ]],
            ],
        ];
    }

    /**
     * @param list<array{text:string,callback_data:string}> $rows
     *
     * @return array<string, array<array<array<string, string>>>>
     */
    public function trackOrdersMenu(array $rows): array
    {
        $inlineRows = [];
        foreach ($rows as $row) {
            $inlineRows[] = [[
                'text' => $row['text'],
                'callback_data' => $row['callback_data'],
            ]];
        }
        $inlineRows[] = [[
            'text' => '🔙 بازگشت',
            'callback_data' => 'main_menu',
        ]];

        return ['inline_keyboard' => $inlineRows];
    }

    /**
     * @return array<string, array<array<array<string, string>>>>
     */
    public function trackOrderDetailMenu(int $orderId, ?int $serviceId, bool $canResume): array
    {
        $rows = [];
        if ($canResume || null !== $serviceId) {
            $row = [];
            if ($canResume) {
                $row[] = ['text' => 'ادامه پرداخت', 'callback_data' => 'order_resume:'.$orderId];
            }
            if (null !== $serviceId) {
                $row[] = ['text' => 'مشاهده سرویس', 'callback_data' => 'service_view:'.$serviceId];
            }
            if ([] !== $row) {
                $rows[] = $row;
            }
        }
        $rows[] = [
            ['text' => '🔙 بازگشت', 'callback_data' => 'track_orders'],
            ['text' => '❌ بستن', 'callback_data' => 'main_menu'],
        ];

        return ['inline_keyboard' => $rows];
    }
}
