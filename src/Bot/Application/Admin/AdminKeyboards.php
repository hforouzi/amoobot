<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\BotTextResolver;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminPermission;

/**
 * Bot admin keyboards, built from the admin's permissions: a button is shown only when its action is allowed.
 * Labels come from the button-label system (BotTextResolver, defaults in AdminBotTexts).
 */
final class AdminKeyboards
{
    public function __construct(
        private readonly BotTextResolver $texts,
        private readonly AdminAccessPolicy $policy,
    ) {
    }

    /**
     * The admin menu ("admin.menu"); "store management" when at least one of its screens is allowed.
     *
     * @param list<array{0: string, 1: string, 2: list<AdminPermission>}> $storeItems
     */
    public function menu(?AdminActor $admin, array $storeItems): array
    {
        $items = [
            ['button.admin.store_management', 'admin.store_menu', [] === $this->visible($admin, $storeItems) ? [] : null],
            ['button.admin.payments', 'admin_pending_payments', [AdminPermission::PAYMENTS_VIEW]],
            ['button.admin.payments_attention', 'admin_attention_payments', [AdminPermission::PAYMENTS_VIEW]],
            ['button.admin.services', 'admin_services', [AdminPermission::SERVICES_VIEW]],
            ['button.admin.users', 'admin_users', [AdminPermission::CUSTOMERS_VIEW]],
        ];
        $buttons = [];
        foreach ($items as [$label, $callback, $permissions]) {
            if (null === $permissions || ([] !== $permissions && $this->policy->canAny($admin, $permissions))) {
                $buttons[] = $this->button($label, $callback);
            }
        }

        return $this->grid($buttons, 2, [$this->button('button.common.back_to_main_menu', 'main_menu')]);
    }

    /**
     * A menu of [label key, callback, any-of permissions] items, filtered for this admin, with a back row.
     *
     * @param list<array{0: string, 1: string, 2: list<AdminPermission>}> $items
     */
    public function screenMenu(?AdminActor $admin, array $items, string $backCallback = 'admin.menu', string $backLabel = 'button.admin.back_to_management'): array
    {
        $buttons = [];
        foreach ($this->visible($admin, $items) as [$label, $callback]) {
            $buttons[] = $this->button($label, $callback);
        }

        return $this->grid($buttons, 2, [$this->button($backLabel, $backCallback)]);
    }

    /**
     * @param list<array{0: string, 1: string, 2: list<AdminPermission>}> $items
     *
     * @return list<array{0: string, 1: string, 2: list<AdminPermission>}>
     */
    public function visible(?AdminActor $admin, array $items): array
    {
        return array_values(array_filter($items, fn (array $item): bool => $this->policy->canAny($admin, $item[2])));
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    public function button(string $labelKey, string $callback): array
    {
        return ['text' => $this->texts->button($labelKey), 'callback_data' => $callback];
    }

    /**
     * @param list<array{text: string, callback_data: string}>      $buttons
     * @param list<array{text: string, callback_data: string}>|null $lastRow
     */
    public function grid(array $buttons, int $columns, ?array $lastRow = null): array
    {
        $rows = array_chunk($buttons, $columns);
        if (null !== $lastRow && [] !== $lastRow) {
            $rows[] = $lastRow;
        }

        return ['inline_keyboard' => $rows];
    }
}
