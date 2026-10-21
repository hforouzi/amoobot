<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Admin\UI\Crud\DiscountCodeCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PlanCrudController;
use App\Admin\UI\Crud\SettingCrudController;
use App\Admin\UI\Crud\StorePaymentMethodCrudController;
use App\Admin\UI\DashboardController;
use App\Bot\Application\BotTextResolver;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Security\AdminPermission;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bot admin "store management": links to the web admin pages (to be replaced by in-bot screens).
 */
final class AdminStoreLinks
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly BotTextResolver $botTextResolver,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly RequestStack $requestStack,
        private readonly AdminBotReplies $replies,
    ) {
    }

    public function menu(string $chatId, ?string $callbackId = null): void
    {
        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, 'مدیریت فروشگاه:', $this->keyboardFactory->adminStoreManagementMenu());
    }

    public function action(string $chatId, string $action, ?string $callbackId = null): void
    {
        $sections = $this->sections();
        if (!isset($sections[$action])) {
            $this->replies->ack($callbackId);
            $this->telegramApiClient->sendMessage($chatId, 'این بخش از پنل ادمین قابل مدیریت است.', $this->keyboardFactory->backToAdminStoreManagementMenu());

            return;
        }

        $this->replies->ack($callbackId);

        try {
            $url = $this->adminCrudUrl($sections[$action]['controller']);
        } catch (\Throwable $e) {
            $this->replies->log(sprintf('admin_store_url_failed action="%s" message="%s"', $action, $e->getMessage()));
            $url = $this->manualAdminCrudUrl($sections[$action]['controller']);
        }

        if (null === $url) {
            $this->telegramApiClient->sendMessage($chatId, 'این بخش از پنل ادمین قابل مدیریت است.', $this->keyboardFactory->backToAdminStoreManagementMenu());

            return;
        }

        $label = $this->botTextResolver->button($sections[$action]['button']);
        $message = sprintf(
            "%s:\n%s",
            $this->replies->html($label),
            $this->formatAdminUrl($url)
        );

        $this->telegramApiClient->sendHtmlMessage($chatId, $message, $this->keyboardFactory->backToAdminStoreManagementMenu());
    }

    /**
     * @return array<string, array{button:string, controller:class-string, permission:AdminPermission}>
     */
    public function sections(): array
    {
        return [
            'admin.store.settings' => [
                'button' => 'button.admin.store.settings',
                'controller' => SettingCrudController::class,
                'permission' => AdminPermission::SETTINGS_MANAGE,
            ],
            'admin.store.plans' => [
                'button' => 'button.admin.store.plans',
                'controller' => PlanCrudController::class,
                'permission' => AdminPermission::PLANS_MANAGE,
            ],
            'admin.store.discounts' => [
                'button' => 'button.admin.store.discounts',
                'controller' => DiscountCodeCrudController::class,
                'permission' => AdminPermission::DISCOUNTS_MANAGE,
            ],
            'admin.store.payment_methods' => [
                'button' => 'button.admin.store.payment_methods',
                'controller' => StorePaymentMethodCrudController::class,
                'permission' => AdminPermission::GATEWAYS_MANAGE,
            ],
            'admin.store.payment_gateways' => [
                'button' => 'button.admin.store.payment_gateways',
                'controller' => PaymentGatewayCrudController::class,
                'permission' => AdminPermission::GATEWAYS_MANAGE,
            ],
            'admin.store.orders' => [
                'button' => 'button.admin.store.orders',
                'controller' => OrderCrudController::class,
                'permission' => AdminPermission::ORDERS_VIEW,
            ],
        ];
    }

    /**
     * @param class-string $crudController
     */
    private function adminCrudUrl(string $crudController): ?string
    {
        $relativeUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController($crudController)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $relativeUrl = trim($relativeUrl);
        if ('' === $relativeUrl) {
            return null;
        }

        if (str_starts_with($relativeUrl, 'http://') || str_starts_with($relativeUrl, 'https://')) {
            return $relativeUrl;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return $relativeUrl;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($relativeUrl, '/');
    }

    /**
     * @param class-string $crudController
     */
    private function manualAdminCrudUrl(string $crudController): string
    {
        $path = '/admin?'.http_build_query([
            'crudAction' => Action::INDEX,
            'crudControllerFqcn' => $crudController,
        ], '', '&', PHP_QUERY_RFC3986);

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return $path;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').$path;
    }

    private function formatAdminUrl(string $url): string
    {
        $escaped = $this->replies->html($url);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return sprintf('<a href="%s">%s</a>', $escaped, $escaped);
        }

        return sprintf('<code>%s</code>', $escaped);
    }
}
