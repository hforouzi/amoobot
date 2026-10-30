<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Crud;
use App\Security\AdminPermission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The tabs of the consolidated admin pages (templates/admin/_tabs.html.twig): each tab is shown when its page opens
 * for the current admin (the same permission the server checks).
 *
 * @phpstan-type Tab array{key: string, label: string, url: string, active: bool, count?: int|null}
 */
final class AdminTabs
{
    /** Pages made of several EasyAdmin lists: tab key => [CRUD controller, index route, label]. */
    private const CRUD_GROUPS = [
        'bot_texts' => [
            'messages' => [Crud\BotMessageTemplateCrudController::class, 'admin_bot_texts_messages_index', 'admin.bot_message_templates'],
            'buttons' => [Crud\BotButtonLabelCrudController::class, 'admin_bot_texts_buttons_index', 'admin.bot_button_labels'],
        ],
        'apps' => [
            'downloads' => [Crud\AppDownloadCrudController::class, 'admin_apps_downloads_index', 'admin.app_downloads'],
            'clients' => [Crud\ClientAppCrudController::class, 'admin_apps_clients_index', 'admin.client_apps'],
        ],
        'logs' => [
            'audit' => [Crud\AdminAuditLogCrudController::class, 'admin_logs_audit_index', 'admin.audit_log'],
            'notifications' => [Crud\ServiceNotificationLogCrudController::class, 'admin_logs_notifications_index', 'admin.notifications'],
            'bot_messages' => [Crud\BotMessageLogCrudController::class, 'admin_logs_bot_messages_index', 'admin.bot_logs'],
        ],
    ];

    /** Settings page (SettingsAdminController): tab key => label. */
    public const SETTINGS = [
        'sales' => 'settings_page.tabs.sales',
        'automation' => 'settings_page.tabs.automation',
        'wallet' => 'settings_page.tabs.wallet',
        'resellers' => 'settings_page.tabs.resellers',
        'plans' => 'settings_page.tabs.plans',
        'channels' => 'settings_page.tabs.channels',
        'advanced' => 'settings_page.tabs.advanced',
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    /**
     * The tabs of the page this EasyAdmin list belongs to (none if it is a page of its own).
     *
     * @return list<Tab>
     */
    #[AsTwigFunction('admin_tabs_for')]
    public function forCrud(?string $crudController): array
    {
        foreach (self::CRUD_GROUPS as $group) {
            if (!in_array($crudController, array_column($group, 0), true)) {
                continue;
            }
            $tabs = [];
            foreach ($group as $key => [$controller, $route, $label]) {
                if ($this->security->isGranted(AdminCrudPermissions::view($controller))) {
                    $tabs[] = ['key' => $key, 'label' => $label, 'url' => $this->urlGenerator->generate($route), 'active' => $controller === $crudController];
                }
            }

            return $tabs;
        }

        return [];
    }

    /**
     * @return list<Tab>
     */
    public function settings(string $active): array
    {
        if (!$this->security->isGranted(AdminPermission::SETTINGS_MANAGE->value)) {
            return [];
        }
        $tabs = [];
        foreach (self::SETTINGS as $key => $label) {
            $tabs[] = ['key' => $key, 'label' => $label, 'url' => $this->urlGenerator->generate('admin_settings', ['tab' => $key]), 'active' => $key === $active];
        }

        return $tabs;
    }
}
