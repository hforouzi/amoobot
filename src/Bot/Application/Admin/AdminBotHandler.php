<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\ServiceAction\ServiceActionContext;
use App\Bot\Application\ServiceAction\ServiceActionResolver;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;
use App\Security\AdminAccessPolicy;
use App\Security\AdminAuditLogger;
use App\Security\AdminPermission;

/**
 * The bot's admin area: every admin_* / admin.* callback. The acting admin comes from AdminAccessPolicy::botActor()
 * (linked AdminUser or TELEGRAM_ADMIN_CHAT_ID); each callback needs its permission. TelegramUpdateHandler only routes
 * here.
 */
final class AdminBotHandler
{
    public function __construct(
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly AdminAuditLogger $auditLogger,
        private readonly ServiceActionResolver $serviceActionResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly BotTextResolver $botTextResolver,
        private readonly AdminBotReplies $replies,
        private readonly AdminPaymentsHandler $payments,
        private readonly AdminOrdersHandler $orders,
        private readonly AdminCustomersHandler $customers,
        private readonly AdminStoreLinks $store,
    ) {
    }

    public function handle(TelegramAccount $account, string $actorId, string $chatId, string $callbackId, string $data): void
    {
        $admin = $this->accessPolicy->botActor($actorId);
        $permission = $this->permissionFor($data);
        if (null === $admin || (null !== $permission && !$this->accessPolicy->can($admin, $permission))) {
            $this->replies->log(sprintf('admin_callback_unauthorized data="%s" actor_id="%s" chat_id="%s"', $data, $actorId, $chatId));
            $this->telegramApiClient->answerCallbackQuery($callbackId, $this->botTextResolver->message('admin.unauthorized'), true);

            return;
        }

        $this->replies->log(sprintf('admin_callback_execute data="%s" actor_id="%s"', $data, $actorId));

        if ('admin_menu' === $data || 'admin.menu' === $data) {
            $this->showMenu($chatId, $callbackId);

            return;
        }

        if ('admin.store_menu' === $data) {
            $this->store->menu($chatId, $callbackId);

            return;
        }

        if (str_starts_with($data, 'admin.store.')) {
            $this->store->action($chatId, $data, $callbackId);

            return;
        }

        if ('admin_pending_payments' === $data) {
            $this->payments->pending($chatId, $callbackId);

            return;
        }

        if ('admin_attention_payments' === $data) {
            $this->payments->attention($chatId, $callbackId);

            return;
        }

        if ('admin_users' === $data) {
            $this->customers->recent($chatId, $callbackId);

            return;
        }

        if ('admin_orders' === $data) {
            $this->orders->recent($chatId, $callbackId);

            return;
        }

        if (str_starts_with($data, 'admin_view_payment:')) {
            $this->payments->view($chatId, (int) str_replace('admin_view_payment:', '', $data), $callbackId);

            return;
        }

        if (str_starts_with($data, 'admin_confirm_payment:')) {
            $this->payments->confirm($chatId, (int) str_replace('admin_confirm_payment:', '', $data), $callbackId, $admin);

            return;
        }

        if (str_starts_with($data, 'admin_reject_payment:')) {
            $this->payments->reject($chatId, (int) str_replace('admin_reject_payment:', '', $data), $callbackId, $admin);

            return;
        }

        if ($this->serviceActionResolver->dispatch(new ServiceActionContext(
            account: $account,
            actorId: $actorId,
            chatId: $chatId,
            callbackId: $callbackId,
            data: $data,
            admin: $admin,
            policy: $this->accessPolicy,
            auditLogger: $this->auditLogger,
        ))) {
            return;
        }

        $this->replies->ack($callbackId);
        $this->replies->log(sprintf('admin_callback_unknown data="%s"', $data));

        return;
    }

    public function showMenu(string $chatId, ?string $callbackId = null): void
    {
        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'منوی مدیریت:', $this->keyboardFactory->adminMenu());
    }

    /**
     * The permission an admin callback needs; null = any admin (menus), or checked by the service action itself
     * (admin_services, admin_service_view:, admin_user_*).
     */
    private function permissionFor(string $data): ?AdminPermission
    {
        if (str_starts_with($data, 'admin.store.')) {
            return $this->store->sections()[$data]['permission'] ?? null;
        }

        return match (true) {
            'admin_pending_payments' === $data, 'admin_attention_payments' === $data, str_starts_with($data, 'admin_view_payment:') => AdminPermission::PAYMENTS_VIEW,
            str_starts_with($data, 'admin_confirm_payment:'), str_starts_with($data, 'admin_reject_payment:') => AdminPermission::PAYMENTS_MODERATE,
            'admin_users' === $data => AdminPermission::CUSTOMERS_VIEW,
            'admin_orders' === $data => AdminPermission::ORDERS_VIEW,
            default => null,
        };
    }

    public function supports(string $data): bool
    {
        return str_starts_with($data, 'admin_') || str_starts_with($data, 'admin.');
    }
}
