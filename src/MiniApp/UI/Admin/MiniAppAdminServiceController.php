<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\Application\ServiceTabs;
use App\Bot\Application\ServiceManagementService;
use App\Entity\VpnService;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\ServiceConfigRegeneration;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminAuditLogger;
use App\Subscription\Application\SubscriptionPageUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin services in the Mini App: the list (the web panel's tabs plus "low traffic", search), a service's detail and
 * its actions, each through the code the bot and the web panel use:
 *  - SERVICES_ASSIST: resend the config into the customer's chat (ServiceManagementService, as the customer's own
 *    "resend config"), sync usage from the panel (ServiceUsageSyncService);
 *  - SERVICES_MANAGE: suspend, activate, extend, add traffic, reset usage (ServiceAdministration, as in the bot);
 *  - PANELS_MANAGE: regenerate config (ServiceConfigRegenerator, as on the web, which gates it the same way);
 *  - SERVICES_DELETE: delete, after typing the service's name.
 * Amounts accept Persian digits and are range-checked here and again by ServiceAdministration. Every action is audited
 * (source miniapp) with its outcome.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminServiceController extends AbstractController
{
    public const EXTEND_PRESETS = [7, 30, 90];
    public const TRAFFIC_PRESETS = [10, 50, 100];
    public const ASSIST = ['miniapp_admin_service_resend_config', 'miniapp_admin_service_sync_usage'];
    public const MANAGE = ['miniapp_admin_service_suspend', 'miniapp_admin_service_activate', 'miniapp_admin_service_extend', 'miniapp_admin_service_add_traffic', 'miniapp_admin_service_reset_usage', 'miniapp_admin_service_regenerate_config'];
    public const DELETE = 'miniapp_admin_service_delete';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminLists $lists,
        private readonly AdminRouteAccess $access,
        private readonly AdminActions $actions,
        private readonly AdminAuditLogger $auditLogger,
        private readonly ServiceAdministration $administration,
        private readonly ServiceConfigRegenerator $regenerator,
        private readonly ServiceUsageSyncService $usageSync,
        private readonly ServiceManagementService $serviceManagement,
        private readonly SubscriptionPageUrl $pageUrl,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/admin/services', name: 'miniapp_admin_services', defaults: ['_miniapp_section' => 'admin.services'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = ServiceTabs::normalize($request->query->getString('tab', 'all'), AdminLists::SERVICE_FILTERS);
        $query = AdminQuery::text($request);

        return $this->fragments->render($request, 'miniapp/admin/services.html.twig', [
            'tab' => $filter,
            'tabs' => AdminLists::SERVICE_FILTERS,
            'counts' => ServiceTabs::countByTab($this->entityManager, $this->clock->now()),
            'query' => $query,
            'list' => $this->lists->services($filter, $query, AdminQuery::page($request)),
            'now' => $this->clock->now(),
        ]);
    }

    #[Route('/app/admin/services/{id}', name: 'miniapp_admin_service', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['GET'])]
    public function detail(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $service = $this->service($id);

        return $this->fragments->render($request, 'miniapp/admin/service.html.twig', [
            's' => $this->view($service),
            'can' => $this->access->map($user, [...self::ASSIST, ...self::MANAGE, self::DELETE, 'miniapp_admin_customer', 'miniapp_admin_order']),
            'extendPresets' => self::EXTEND_PRESETS,
            'trafficPresets' => self::TRAFFIC_PRESETS,
            'maxDays' => ServiceAdministration::MAX_DAYS,
            'maxTrafficGb' => ServiceAdministration::MAX_TRAFFIC_GB,
        ]);
    }

    /** The configs into the customer's own chat with the bot (never into this response). */
    #[Route('/app/api/admin/services/{id}/resend-config', name: 'miniapp_admin_service_resend_config', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function resendConfig(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $service = $this->service($id);

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $service, $id): array {
            $account = $service->getUser()->getTelegramAccount();
            if (null === $account) {
                return $this->answer($request, 'service.no_chat', Response::HTTP_CONFLICT);
            }
            $this->serviceManagement->resendConfig($account, $id, $account->getTelegramId(), '', false);
            $this->audit($user, 'service.resend_config', $id);

            return $this->answer($request, 'service.config_sent');
        });
    }

    #[Route('/app/api/admin/services/{id}/sync-usage', name: 'miniapp_admin_service_sync_usage', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function syncUsage(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $service = $this->service($id);

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $service, $id): array {
            try {
                $failed = $this->usageSync->syncOne($service)->isFailed();
            } catch (\Throwable) {
                $failed = true;
            }
            $this->audit($user, 'service.sync_usage', $id, ['outcome' => $failed ? 'failed' : 'done']);

            return $failed ? $this->answer($request, 'service.sync_failed', Response::HTTP_BAD_GATEWAY) : $this->answer($request, 'service.synced');
        });
    }

    #[Route('/app/api/admin/services/{id}/suspend', name: 'miniapp_admin_service_suspend', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function suspend(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->change($request, $user, $id, 'suspend', [], fn (VpnService $s): ServiceAdminOutcome => $this->administration->suspend($s));
    }

    #[Route('/app/api/admin/services/{id}/activate', name: 'miniapp_admin_service_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function activate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->change($request, $user, $id, 'activate', [], fn (VpnService $s): ServiceAdminOutcome => $this->administration->activate($s));
    }

    #[Route('/app/api/admin/services/{id}/reset-usage', name: 'miniapp_admin_service_reset_usage', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function resetUsage(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->change($request, $user, $id, 'reset_usage', [], fn (VpnService $s): ServiceAdminOutcome => $this->administration->resetUsage($s));
    }

    #[Route('/app/api/admin/services/{id}/extend', name: 'miniapp_admin_service_extend', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function extend(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $days = AdminText::integer(self::body($request)['days'] ?? null);
        if (null === $days || $days < 1 || $days > ServiceAdministration::MAX_DAYS) {
            return $this->invalid($request, 'service.invalid_days', ['%max%' => ServiceAdministration::MAX_DAYS]);
        }

        return $this->change($request, $user, $id, 'extend', ['%days%' => $days], fn (VpnService $s): ServiceAdminOutcome => $this->administration->extend($s, $days), ['days' => $days]);
    }

    #[Route('/app/api/admin/services/{id}/add-traffic', name: 'miniapp_admin_service_add_traffic', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function addTraffic(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $gb = AdminText::integer(self::body($request)['trafficGb'] ?? null);
        if (null === $gb || $gb < 1 || $gb > ServiceAdministration::MAX_TRAFFIC_GB) {
            return $this->invalid($request, 'service.invalid_traffic', ['%max%' => ServiceAdministration::MAX_TRAFFIC_GB]);
        }

        return $this->change($request, $user, $id, 'add_traffic', ['%gb%' => $gb], fn (VpnService $s): ServiceAdminOutcome => $this->administration->addTraffic($s, $gb), ['trafficGb' => $gb]);
    }

    #[Route('/app/api/admin/services/{id}/regenerate-config', name: 'miniapp_admin_service_regenerate_config', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function regenerateConfig(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $service = $this->service($id);
        $locale = MiniAppLocale::fromRequest($request);
        $confirm = $this->translator->trans('admin.service.confirm.regenerate_config', ['%name%' => self::name($service)], 'miniapp', $locale);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $service, $id): array {
            $result = $this->regenerator->regenerate($service, 'miniapp_admin_regenerate_config');
            $this->audit($user, 'service.regenerate_config', $id, ['outcome' => $result->outcome, 'links' => $result->linkCount]);

            return $this->answer($request, 'service.regenerate.'.$result->outcome, $result->succeeded() ? Response::HTTP_OK : (ServiceConfigRegeneration::NO_IDENTITY === $result->outcome ? Response::HTTP_CONFLICT : Response::HTTP_BAD_GATEWAY), ['%count%' => $result->linkCount])
                + ['detail' => AdminText::safe($result->error)];
        });
    }

    /** Needs the service's name typed back (the page asks for it), then Telegram's confirm dialog. */
    #[Route('/app/api/admin/services/{id}/delete', name: 'miniapp_admin_service_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $service = $this->service($id);
        $typed = trim(AdminText::latinDigits((string) (self::body($request)['confirmName'] ?? '')));
        if (0 !== strcasecmp($typed, self::name($service))) {
            return $this->invalid($request, 'service.delete_name_mismatch');
        }

        return $this->change($request, $user, $id, 'delete', [], fn (VpnService $s): ServiceAdminOutcome => $this->administration->delete($s));
    }

    /**
     * A SERVICES_MANAGE / SERVICES_DELETE change: confirmed (token), rate limited, once per key, audited with its outcome.
     *
     * @param array<string, int|string>        $confirmParameters
     * @param callable(VpnService): ServiceAdminOutcome $change
     * @param array<string, mixed>             $details
     */
    private function change(Request $request, MiniAppUser $user, int $id, string $action, array $confirmParameters, callable $change, array $details = []): JsonResponse
    {
        $service = $this->service($id);
        $confirm = $this->translator->trans('admin.service.confirm.'.$action, $confirmParameters + ['%name%' => self::name($service)], 'miniapp', MiniAppLocale::fromRequest($request));

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $service, $id, $action, $change, $details): array {
            $outcome = $change($service);
            $this->audit($user, 'service.'.$action, $id, $details + ['outcome' => $outcome->value]);

            return $this->answer($request, $outcome->isDone() ? 'service.done.'.$action : 'service.outcome.'.$outcome->value, match ($outcome) {
                ServiceAdminOutcome::DONE => Response::HTTP_OK,
                ServiceAdminOutcome::PANEL_FAILED => Response::HTTP_BAD_GATEWAY,
                ServiceAdminOutcome::INVALID_AMOUNT, ServiceAdminOutcome::LIMIT_OVERFLOW => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_CONFLICT,
            });
        });
    }

    /**
     * @param array<string, mixed> $details
     */
    private function audit(MiniAppUser $user, string $action, int $id, array $details = []): void
    {
        $this->auditLogger->record($action, 'VpnService', $id, $details, $user->admin);
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return array{message: string, status: int}
     */
    private function answer(Request $request, string $key, int $status = Response::HTTP_OK, array $parameters = []): array
    {
        return ['message' => $this->translator->trans('admin.'.$key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request)), 'status' => $status];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function invalid(Request $request, string $key, array $parameters = []): JsonResponse
    {
        return AdminActions::json(['error' => 'invalid_input', 'message' => $this->translator->trans('admin.'.$key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request))], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function service(int $id): VpnService
    {
        $service = $this->entityManager->find(VpnService::class, $id);
        if (!$service instanceof VpnService) {
            throw new NotFoundHttpException();
        }

        return $service;
    }

    /** What an admin types to delete it: the service's username, or its number when it has none. */
    public static function name(VpnService $service): string
    {
        $username = trim((string) $service->getUsername());

        return '' !== $username ? $username : (string) $service->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        $data = 'json' === $request->getContentTypeFormat() ? json_decode($request->getContent(), true) : $request->request->all();

        return is_array($data) ? $data : [];
    }

    /**
     * What the detail page shows: no panel address, credentials, client ids or config links.
     *
     * @return array<string, mixed>
     */
    private function view(VpnService $service): array
    {
        $limit = $service->getTrafficLimitBytes();
        $used = $service->getTrafficUsedBytes() ?? 0;
        $now = $this->clock->now();
        $expires = $service->getExpiresAt();

        return [
            'id' => (int) $service->getId(),
            'name' => self::name($service),
            'status' => $service->getStatus(),
            'deleted' => VpnServiceStatus::DELETED === $service->getStatus(),
            'plan' => $service->getOrder()?->getPlan()->getTitle(),
            'orderId' => $service->getOrder()?->getId(),
            'panel' => $service->getPanel()?->getTitle(),
            'inbound' => $service->getInbound()?->getTitle(),
            'expiresAt' => $expires,
            'daysLeft' => null === $expires ? null : (int) floor(($expires->getTimestamp() - $now->getTimestamp()) / 86400),
            'used' => $used,
            'limit' => $limit,
            'percent' => null === $limit || $limit <= 0 ? null : min(100, (int) round($used * 100 / $limit)),
            'lastSync' => $service->getLastUsageSyncedAt(),
            'pageUrl' => VpnServiceStatus::DELETED === $service->getStatus() ? null : $this->pageUrl->forService($service),
            'customer' => AdminCustomerView::summary($service->getUser()),
        ];
    }
}
