<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\ServiceAction\ServiceActionContext;
use App\Bot\Application\ServiceAction\ServiceActionResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Security\AdminPermission;

/**
 * The bot's admin area: every admin_* / admin.* callback and the answers to admin prompts. The acting admin comes from
 * AdminAccessPolicy::botActor() (linked AdminUser or TELEGRAM_ADMIN_CHAT_ID); each callback needs its permission
 * (checked here, again when a change is confirmed, and used to build the keyboards). TelegramUpdateHandler only routes
 * here.
 */
final class AdminBotHandler
{
    /** Callbacks from before the "admin.<area>:<verb>" scheme (alerts and older messages still carry them). */
    private const LEGACY = [
        'admin_pending_payments' => 'admin.p:pend:0',
        'admin_attention_payments' => 'admin.p:att:0',
        'admin_orders' => 'admin.o:l:0:a',
        'admin_users' => 'admin.c:l:0',
    ];
    private const LEGACY_WITH_ID = [
        'admin_view_payment:' => 'admin.p:v:',
        'admin_confirm_payment:' => 'admin.p:ap:',
        'admin_reject_payment:' => 'admin.p:rj:',
    ];

    /** @var array<string, AdminArea> */
    private array $areas = [];

    public function __construct(
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly AdminAuditLogger $auditLogger,
        private readonly ServiceActionResolver $serviceActionResolver,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly BotTextResolver $botTextResolver,
        private readonly AdminBotReplies $replies,
        private readonly AdminKeyboards $keyboards,
        private readonly AdminScreen $screen,
        private readonly AdminPromptStore $prompts,
        AdminDashboardHandler $dashboard,
        AdminOrdersHandler $orders,
        AdminPaymentsHandler $payments,
        AdminCustomersHandler $customers,
        AdminPlansHandler $plans,
        AdminDiscountsHandler $discounts,
        AdminPaymentMethodsHandler $paymentMethods,
        AdminSettingsHandler $settings,
        AdminPanelsHandler $panels,
        AdminBroadcastsHandler $broadcasts,
    ) {
        foreach ([$dashboard, $orders, $payments, $customers, $plans, $discounts, $paymentMethods, $settings, $panels, $broadcasts] as $area) {
            $this->areas[$area->area()] = $area;
        }
    }

    public function supports(string $data): bool
    {
        return str_starts_with($data, 'admin_') || str_starts_with($data, 'admin.');
    }

    public function handle(TelegramAccount $account, string $actorId, string $chatId, string $callbackId, string $data): void
    {
        $admin = $this->accessPolicy->botActor($actorId);
        if (null === $admin) {
            $this->refuse($data, $actorId, $chatId, $callbackId);

            return;
        }
        $data = $this->modernize($data);
        $this->replies->log(sprintf('admin_callback_execute data="%s" actor_id="%s"', $data, $actorId));

        if ('admin_menu' === $data || 'admin.menu' === $data) {
            $this->showMenu($chatId, $admin, $callbackId);

            return;
        }
        if ('admin.store_menu' === $data) {
            $this->showStoreMenu($chatId, $admin, $callbackId);

            return;
        }
        if ('admin.x' === $data) {
            $this->prompts->clear($actorId);
            $this->screen->send(new AdminRequest($admin, $chatId, $callbackId, '', []), $this->screen->text('admin.cancelled'), $this->keyboards->screenMenu($admin, [], 'admin.store_menu', 'button.admin.back'));

            return;
        }
        if (str_starts_with($data, 'admin.store.')) {
            // A "store management" button from before the in-bot screens: those pages are web only now.
            $this->screen->notice(new AdminRequest($admin, $chatId, $callbackId, '', []), 'admin.web_only');

            return;
        }

        $parsed = AdminRequest::parse($data);
        if (null !== $parsed && isset($this->areas[$parsed[0]])) {
            [$area, $verb, $args] = $parsed;
            $permissions = $this->areas[$area]->permissions($verb);
            if ([] === $permissions || !$this->accessPolicy->canAny($admin, $permissions)) {
                $this->refuse($data, $actorId, $chatId, $callbackId);

                return;
            }
            $this->areas[$area]->handle(new AdminRequest($admin, $chatId, $callbackId, $verb, $args));

            return;
        }

        // Service and customer views (admin_services, admin_service_view:, admin_user_*): each action checks its own.
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
    }

    /**
     * A typed message while the admin was asked for a value. False when there is no such prompt (or the admin may no
     * longer answer it): the message is then handled as usual, never as an admin answer.
     */
    public function answerPrompt(string $actorId, string $chatId, string $text): bool
    {
        $prompt = $this->prompts->get($actorId);
        if (null === $prompt) {
            return false;
        }
        $admin = $this->accessPolicy->botActor($actorId);
        $area = $this->areaForPrompt($prompt['kind']);
        $permission = $area?->promptPermission($prompt['kind']);
        if (null === $admin || null === $area || null === $permission || !$this->accessPolicy->can($admin, $permission)) {
            $this->prompts->clear($actorId);

            return false;
        }
        $request = new AdminRequest($admin, $chatId, null, '', []);
        if ($prompt['expired']) {
            $this->prompts->clear($actorId);
            $this->screen->notice($request, 'admin.prompt_expired');

            return true;
        }
        $area->answer($request, $prompt['kind'], $prompt['target'], $text);

        return true;
    }

    /** Leaves a pending prompt (a command or a menu button was sent instead of a value). */
    public function dropPrompt(string $actorId): void
    {
        $this->prompts->clear($actorId);
    }

    /**
     * The admin menu, with only the buttons this admin may use.
     */
    public function showMenu(string $chatId, ?AdminActor $admin, ?string $callbackId = null): void
    {
        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('admin.menu_title'), $this->keyboards->menu($admin, $this->storeItems()));
    }

    private function showStoreMenu(string $chatId, AdminActor $admin, ?string $callbackId): void
    {
        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, $this->botTextResolver->message('admin.store_title'), $this->keyboards->screenMenu($admin, $this->storeItems()));
    }

    /**
     * The in-bot store screens as [label key, callback, any-of permissions].
     *
     * @return list<array{0: string, 1: string, 2: list<AdminPermission>}>
     */
    private function storeItems(): array
    {
        return [
            ['button.admin.dashboard', 'admin.dash', [AdminPermission::ORDERS_VIEW, AdminPermission::PAYMENTS_VIEW, AdminPermission::SERVICES_VIEW]],
            ['button.admin.orders', 'admin.o:l:0:a', [AdminPermission::ORDERS_VIEW]],
            ['button.admin.payments_pending', 'admin.p:pend:0', [AdminPermission::PAYMENTS_VIEW]],
            ['button.admin.payments_attention', 'admin.p:att:0', [AdminPermission::PAYMENTS_VIEW]],
            ['button.admin.customers', 'admin.c:l:0', [AdminPermission::CUSTOMERS_VIEW]],
            ['button.admin.plans', 'admin.pl:l:0', [AdminPermission::PLANS_MANAGE]],
            ['button.admin.trial_claims', 'admin.pl:tc:0', [AdminPermission::PLANS_MANAGE]],
            ['button.admin.discounts', 'admin.d:l:0', [AdminPermission::DISCOUNTS_MANAGE]],
            ['button.admin.payment_methods', 'admin.m:l', [AdminPermission::GATEWAYS_MANAGE]],
            ['button.admin.gateways', 'admin.m:g', [AdminPermission::GATEWAYS_MANAGE]],
            ['button.admin.settings', 'admin.s:l', [AdminPermission::SETTINGS_MANAGE]],
            ['button.admin.panels', 'admin.pn:l', [AdminPermission::PANELS_MANAGE]],
            ['button.admin.broadcasts', 'admin.b:l', [AdminPermission::BROADCAST_SEND]],
        ];
    }

    private function modernize(string $data): string
    {
        if (isset(self::LEGACY[$data])) {
            return self::LEGACY[$data];
        }
        foreach (self::LEGACY_WITH_ID as $prefix => $modern) {
            if (str_starts_with($data, $prefix)) {
                return $modern.(int) substr($data, strlen($prefix));
            }
        }

        return $data;
    }

    private function areaForPrompt(string $kind): ?AdminArea
    {
        foreach ($this->areas as $area) {
            if (null !== $area->promptPermission($kind)) {
                return $area;
            }
        }

        return null;
    }

    private function refuse(string $data, string $actorId, string $chatId, string $callbackId): void
    {
        $this->replies->log(sprintf('admin_callback_unauthorized data="%s" actor_id="%s" chat_id="%s"', $data, $actorId, $chatId));
        $this->telegramApiClient->answerCallbackQuery($callbackId, $this->botTextResolver->message('admin.unauthorized'), true);
    }
}
