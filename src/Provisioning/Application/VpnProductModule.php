<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Application\VpnAccessMessageFormatter;
use App\Entity\Order;
use App\Entity\VpnService;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActionResult;
use App\Module\Domain\CustomerActions;
use App\Module\Domain\ProductModuleInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Application\ServiceOwnership;
use App\Shop\Application\Checkout\ServiceOrderCheckout;
use App\Shop\Application\SalesSettingsProvider;
use App\Subscription\Application\SubscriptionPageUrl;
use App\Subscription\Application\SubscriptionPageViewFactory;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * The built-in 'vpn' product module (docs/design/product-modules.md 2.4): every method delegates to the existing
 * service, unchanged. PM1b routes settlement through it, PM1c the admin lifecycle actions and the suspension jobs,
 * PM1d the customer's view and actions.
 *
 * - provision: VpnProvisioningService::provisionOrder() with adoption on, as settlement does today, so a second call
 *   for the same order adopts the client it created (orderClientUuid(), findAdoptableClient()).
 * - renew: ServiceRenewalService::renew().
 * - suspend / resume / terminate: the admin path of ServiceAdministration (suspend(), activate(), delete()), as it
 *   stands, including its rule that suspend only calls the panel for sanaei_3xui.
 * - runLifecycleTask: the job path, ServiceAutoSuspendService::suspendExpiredServices() ('expiry') and
 *   suspendTrafficExhaustedServices() ('traffic'), as they stand: the batch query and the per-service driver calls
 *   stay there, and that path always resolves a driver. The two suspend paths are not unified (design 2.4).
 * - customerView: a VpnCustomerView over SubscriptionPageViewFactory, FinalConfigLinkProvider,
 *   VpnAccessMessageFormatter, ServiceConfigDeliveryRefresher, CustomerAccessGuard and ServiceInboundCounts.
 * - customerActions: the rules the bot's keyboard and the Mini App's service view used, each kept as it was (the two
 *   lists are not unified, design 2.4).
 * - runCustomerAction: the bot's delivery into the customer's chat (ServiceManagementService::sendSubscription(),
 *   sendSubscriptionQr(), resendConfig() without admin mode) and ServiceUsageSyncService::syncOne() for 'refresh'.
 */
final class VpnProductModule implements ProductModuleInterface
{
    public const TYPE = 'vpn';
    public const VERSION = 1;

    /**
     * @param \Closure(): ServiceManagementService $botServices the bot's service delivery, built on first use: it asks
     *                                                          the resolver for this module's views, so building it
     *                                                          with the module would tie it to the registry of that moment
     */
    public function __construct(
        private readonly VpnProvisioningService $provisioning,
        private readonly ServiceRenewalService $renewal,
        private readonly ServiceAdministration $administration,
        private readonly ServiceAutoSuspendService $autoSuspend,
        private readonly SubscriptionPageViewFactory $pages,
        private readonly FinalConfigLinkProvider $configLinks,
        private readonly VpnAccessMessageFormatter $accessMessages,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
        private readonly CustomerAccessGuard $accessGuard,
        private readonly ServiceInboundCounts $inboundCounts,
        private readonly SubscriptionPageUrl $pageUrl,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly ServiceOrderCheckout $serviceOrderCheckout,
        private readonly ServiceOwnership $serviceOwnership,
        private readonly ServiceUsageSyncService $usageSync,
        #[AutowireServiceClosure(ServiceManagementService::class)]
        private readonly \Closure $botServices,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function contractVersion(): int
    {
        return self::VERSION;
    }

    public function provision(Order $order, array $meta = []): VpnService
    {
        return $this->provisioning->provisionOrder($order, $meta, adoptExisting: true);
    }

    public function renew(VpnService $service, Order $order): RenewalResult
    {
        return $this->renewal->renew($service, $order);
    }

    public function suspend(VpnService $service): ServiceAdminOutcome
    {
        return $this->administration->suspend($service);
    }

    public function resume(VpnService $service): ServiceAdminOutcome
    {
        return $this->administration->activate($service);
    }

    public function terminate(VpnService $service): ServiceAdminOutcome
    {
        return $this->administration->delete($service);
    }

    public function runLifecycleTask(string $task, int $limit, bool $dryRun): AutoSuspendSummary
    {
        return match ($task) {
            self::LIFECYCLE_EXPIRY => $this->autoSuspend->suspendExpiredServices($limit, $dryRun),
            self::LIFECYCLE_TRAFFIC => $this->autoSuspend->suspendTrafficExhaustedServices($limit, $dryRun),
            default => throw new \InvalidArgumentException(sprintf('The "%s" module has no lifecycle task "%s".', self::TYPE, $task)),
        };
    }

    public function customerView(VpnService $service): VpnCustomerView
    {
        return new VpnCustomerView($service, $this->pages, $this->configLinks, $this->accessMessages, $this->deliveryRefresher, $this->accessGuard, $this->inboundCounts);
    }

    public function customerActions(VpnService $service, string $channel): CustomerActions
    {
        return match ($channel) {
            CustomerActions::CHANNEL_BOT => $this->botActions($service),
            CustomerActions::CHANNEL_MINIAPP => $this->miniAppActions($service),
            default => throw new \InvalidArgumentException(sprintf('The "%s" module has no customer channel "%s".', self::TYPE, $channel)),
        };
    }

    public function runCustomerAction(VpnService $service, string $action, CustomerActionInput $input): CustomerActionResult
    {
        $serviceId = (int) $service->getId();
        switch ($action) {
            case CustomerActions::SEND_LINK:
                ($this->botServices)()->sendSubscription($input->account, $serviceId, $input->chatId, $input->callbackId);
                break;
            case CustomerActions::SEND_QR:
                ($this->botServices)()->sendSubscriptionQr($input->account, $serviceId, $input->chatId, $input->callbackId);
                break;
            case CustomerActions::RESEND_CONFIG:
                ($this->botServices)()->resendConfig($input->account, $serviceId, $input->chatId, $input->callbackId, false);
                break;
            case CustomerActions::REFRESH:
                return $this->usageSync->syncOne($service)->isFailed() ? CustomerActionResult::failed() : CustomerActionResult::done();
            default:
                throw new \InvalidArgumentException(sprintf('The "%s" module has no customer action "%s".', self::TYPE, $action));
        }

        return CustomerActionResult::done();
    }

    /**
     * The bot's service view keyboard (TelegramKeyboardFactory::userServiceDetail()), the rules as they were in
     * ServiceManagementService, in the same order: renewal and extra traffic while their sales switch is on and the
     * service isn't deleted, the subscription page button for a service that isn't deleted, and new connection links
     * for an owner who got it through a claim link (D4).
     */
    private function botActions(VpnService $service): CustomerActions
    {
        $offered = [CustomerActions::SEND_LINK, CustomerActions::SEND_QR, CustomerActions::RESEND_CONFIG, CustomerActions::REFRESH];
        if (VpnServiceStatus::DELETED !== $service->getStatus() && $this->salesSettings->renewalsEnabled()) {
            $offered[] = CustomerActions::RENEW;
        }
        if (VpnServiceStatus::DELETED !== $service->getStatus() && $this->salesSettings->addTrafficEnabled()) {
            $offered[] = CustomerActions::ADD_TRAFFIC;
        }
        // A deleted service has no page.
        $pageUrl = VpnServiceStatus::DELETED !== $service->getStatus() ? $this->pageUrl->forTelegram($service) : null;
        if ($this->serviceOwnership->canResetLinks($service->getUser(), $service)) {
            $offered[] = CustomerActions::RESET_LINKS;
        }

        return new CustomerActions($offered, $pageUrl);
    }

    /**
     * The Mini App's service view, the rules as they were in MiniAppCustomerController: its page link, a renewal while
     * renewals are on and the service has a renewal quote, extra traffic while it is on, purchasable and the service
     * has a plan for it. The pages check them again.
     */
    private function miniAppActions(VpnService $service): CustomerActions
    {
        $pageUrl = $this->pageUrl->forService($service);
        $offered = [CustomerActions::SEND_LINK, CustomerActions::SEND_QR, CustomerActions::RESEND_CONFIG, CustomerActions::REFRESH];
        if ($this->salesSettings->renewalsEnabled() && null !== $this->serviceOrderCheckout->renewalQuote($service)) {
            $offered[] = CustomerActions::RENEW;
        }
        if ($this->salesSettings->addTrafficEnabled() && $this->trafficAddonSettings->canPurchase() && null !== $this->serviceOrderCheckout->addTrafficPlan($service)) {
            $offered[] = CustomerActions::ADD_TRAFFIC;
        }

        return new CustomerActions($offered, $pageUrl);
    }
}
