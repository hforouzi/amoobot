<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\Setting;
use App\Provisioning\Application\AutomationSettingsProvider;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Security\AdminPermission;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: settings ("admin.s", SETTINGS_MANAGE). Only the allow-list below can be read or changed here (sales and
 * automation switches, a few numbers); nothing secret is on it. Values are read through the settings providers, so
 * the effective defaults are shown. Callbacks: l, t:<i> / tk:<i>:<0|1>, n:<i> (prompt) / nk:<i>:<value>.
 */
final class AdminSettingsHandler implements AdminArea
{
    private const VALUE_PROMPT = 'setting_value';

    /** @var list<array{key: string, label: string, type: 'bool'|'int'|'decimal', min?: float, max?: float, read: \Closure(): (bool|int|float)}> */
    private array $settings;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminScreen $screen,
        SalesSettingsProvider $sales,
        AutomationSettingsProvider $automation,
        TrafficAddonSettingsProvider $trafficAddon,
        IncompleteOrderSettingsProvider $incompleteOrders,
        RenewalSettingsProvider $renewal,
    ) {
        $bool = static fn (string $key, string $label, \Closure $read): array => ['key' => $key, 'label' => $label, 'type' => 'bool', 'read' => $read];
        $this->settings = [
            $bool(SalesSettingsProvider::NEW_ORDERS_ENABLED, 'admin.setting.sales_new_orders', $sales->newOrdersEnabled(...)),
            $bool(SalesSettingsProvider::RENEWALS_ENABLED, 'admin.setting.sales_renewals', $sales->renewalsEnabled(...)),
            $bool(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'admin.setting.sales_add_traffic', $sales->addTrafficEnabled(...)),
            $bool('traffic_addon.enabled', 'admin.setting.traffic_addon', $trafficAddon->enabled(...)),
            $bool('automation.sync_usage_enabled', 'admin.setting.auto_sync_usage', $automation->syncUsageEnabled(...)),
            $bool('automation.check_expiry_enabled', 'admin.setting.auto_check_expiry', $automation->checkExpiryEnabled(...)),
            $bool('automation.send_notifications_enabled', 'admin.setting.auto_notifications', $automation->sendNotificationsEnabled(...)),
            $bool('automation.broadcasts_enabled', 'admin.setting.auto_broadcasts', $automation->broadcastsEnabled(...)),
            $bool('automation.auto_suspend_expired_enabled', 'admin.setting.auto_suspend_expired', $automation->autoSuspendExpiredEnabled(...)),
            $bool('automation.auto_suspend_traffic_exhausted_enabled', 'admin.setting.auto_suspend_traffic', $automation->autoSuspendTrafficExhaustedEnabled(...)),
            $bool('automation.expire_incomplete_orders_enabled', 'admin.setting.auto_expire_orders', $automation->expireIncompleteOrdersEnabled(...)),
            ['key' => 'automation.batch_limit', 'label' => 'admin.setting.batch_limit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'read' => $automation->batchLimit(...)],
            ['key' => 'orders.incomplete_expire_hours', 'label' => 'admin.setting.incomplete_hours', 'type' => 'int', 'min' => 1, 'max' => 720, 'read' => $incompleteOrders->expireHours(...)],
            ['key' => 'traffic_addon.min_gb', 'label' => 'admin.setting.addon_min_gb', 'type' => 'int', 'min' => 1, 'max' => 10000, 'read' => $trafficAddon->minGb(...)],
            ['key' => 'traffic_addon.max_gb', 'label' => 'admin.setting.addon_max_gb', 'type' => 'int', 'min' => 1, 'max' => 10000, 'read' => $trafficAddon->maxGb(...)],
            ['key' => 'traffic_addon.price_per_gb', 'label' => 'admin.setting.addon_price_per_gb', 'type' => 'int', 'min' => 0, 'max' => 100000000, 'read' => $trafficAddon->pricePerGb(...)],
            ['key' => 'pricing.global_discount_percent', 'label' => 'admin.setting.global_discount', 'type' => 'decimal', 'min' => 0, 'max' => 90, 'read' => $renewal->globalDiscountPercent(...)],
        ];
    }

    public function area(): string
    {
        return 's';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 't', 'tk', 'n', 'nk'], true) ? [AdminPermission::SETTINGS_MANAGE] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $index = $request->intArg(0, -1);
        $setting = $this->settings[$index] ?? null;
        if ('l' === $request->verb || '' === $request->verb || null === $setting) {
            $this->list($request);

            return;
        }

        match ($request->verb) {
            't' => 'bool' === $setting['type']
                ? $this->screen->confirm($request, $this->screen->text('admin.settings.confirm_change', ['label' => $this->screen->text($setting['label']), 'old' => $this->format($setting, ($setting['read'])()), 'new' => $this->format($setting, !($setting['read'])())]), 'admin.s:tk:'.$index.':'.(($setting['read'])() ? '0' : '1'), 'admin.s:l')
                : $this->list($request),
            'tk' => $this->saveBool($request, $index, '1' === $request->arg(1)),
            'n' => 'bool' === $setting['type'] ? $this->list($request) : $this->screen->prompt($request, self::VALUE_PROMPT, (string) $index, $this->screen->text('admin.settings.prompt', [
                'label' => $this->screen->text($setting['label']), 'value' => $this->format($setting, ($setting['read'])()), 'min' => $setting['min'] ?? 0, 'max' => $setting['max'] ?? 0,
            ])),
            'nk' => $this->saveNumber($request, $index, $request->arg(1)),
            default => $this->list($request),
        };
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return self::VALUE_PROMPT === $kind ? AdminPermission::SETTINGS_MANAGE : null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
        $index = (int) $target;
        $setting = $this->settings[$index] ?? null;
        if (null === $setting || 'bool' === $setting['type']) {
            $this->screen->endPrompt($request);
            $this->list($request);

            return;
        }
        $value = $this->parse($setting, $text);
        if (null === $value) {
            $this->screen->send($request, $this->screen->text('admin.invalid_number', ['min' => $setting['min'] ?? 0, 'max' => $setting['max'] ?? 0]));

            return;
        }
        $this->screen->endPrompt($request);
        $this->screen->confirm($request, $this->screen->text('admin.settings.confirm_change', [
            'label' => $this->screen->text($setting['label']), 'old' => $this->format($setting, ($setting['read'])()), 'new' => $this->format($setting, $value),
        ]), 'admin.s:nk:'.$index.':'.$value, 'admin.s:l');
    }

    private function list(AdminRequest $request): void
    {
        $lines = [$this->screen->text('admin.settings.title')];
        $rows = [];
        foreach ($this->settings as $index => $setting) {
            $label = $this->screen->text($setting['label']);
            $value = $this->format($setting, ($setting['read'])());
            $lines[] = $this->screen->text('admin.settings.line', ['label' => $label, 'value' => $value]);
            $rows[] = [$this->screen->rawButton($label.': '.$value, ('bool' === $setting['type'] ? 'admin.s:t:' : 'admin.s:n:').$index)];
        }
        $lines[] = $this->screen->text('admin.web_only_hint');
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard($rows));
    }

    private function saveBool(AdminRequest $request, int $index, bool $value): void
    {
        $setting = $this->settings[$index];
        if ('bool' !== $setting['type']) {
            $this->list($request);

            return;
        }
        $before = (bool) ($setting['read'])();
        $this->write($setting['key'], $value ? 'true' : 'false', 'boolean');
        $this->screen->audit($request, 'setting.update', 'Setting', $setting['key'], ['before' => $before, 'after' => $value]);
        $this->screen->notice($request, 'admin.done', [], 'admin.s:l');
    }

    private function saveNumber(AdminRequest $request, int $index, string $raw): void
    {
        $setting = $this->settings[$index];
        $value = 'bool' === $setting['type'] ? null : $this->parse($setting, $raw);
        if (null === $value) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.s:l');

            return;
        }
        $before = ($setting['read'])();
        $this->write($setting['key'], (string) $value, 'number');
        $this->screen->audit($request, 'setting.update', 'Setting', $setting['key'], ['before' => $before, 'after' => $value]);
        $this->screen->notice($request, 'admin.done', [], 'admin.s:l');
    }

    /**
     * @param array{type: string, min?: float, max?: float} $setting
     */
    private function parse(array $setting, string $text): int|float|null
    {
        return 'int' === $setting['type']
            ? AdminScreen::parseInt($text, (int) ($setting['min'] ?? 0), (int) ($setting['max'] ?? 0))
            : AdminScreen::parseDecimal($text, (float) ($setting['min'] ?? 0), (float) ($setting['max'] ?? 0));
    }

    /**
     * @param array{type: string} $setting
     */
    private function format(array $setting, bool|int|float $value): string
    {
        return 'bool' === $setting['type'] ? ($value ? '✅' : '⏸') : (string) $value;
    }

    private function write(string $key, string $value, string $type): void
    {
        $setting = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $key]);
        if (!$setting instanceof Setting) {
            $setting = (new Setting())->setKeyName($key);
            $this->entityManager->persist($setting);
        }
        $setting->setValue($value)->setType($type)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
