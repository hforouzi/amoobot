<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\UI\Support\PanelHealth;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Provisioning\Application\PanelAdministration;
use App\Provisioning\Application\PanelConnectionReport;
use App\Provisioning\Application\PanelSubscriptionUrlCheck;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
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
 * Panels in the Mini App admin (PANELS_MANAGE, the super admin's, as on the web): each panel's health (PanelHealth:
 * the last connection test and the degraded flag), last check, active switch, whether customers get a usable
 * subscription URL (PanelSubscriptionUrlCheck), plan count; the connection test (PanelConnectionTester, through
 * PanelAdministration), the active switch, and the panel's inbounds, read only, with whether each is attached for
 * sale and its plans. A panel is shown by its title only: no URL, host, path, token, password or inbound address ever
 * leaves the server, and the test report carries the web's explanation of a failure, never the panel's answer.
 * Adding a panel or changing its connection stays in the web panel setup.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminPanelController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelAdministration $panels,
        private readonly AdminActions $actions,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/admin/panels', name: 'miniapp_admin_panels', defaults: ['_miniapp_section' => 'admin.panels'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        /** @var list<VpnPanel> $panels */
        $panels = $this->entityManager->getRepository(VpnPanel::class)->findBy([], ['id' => 'ASC']);
        $plans = $this->planCounts('IDENTITY(i.panel)');
        $inbounds = [];
        foreach ($this->entityManager->createQueryBuilder()->select('IDENTITY(i.panel) AS panel', 'COUNT(i.id) AS n')->from(VpnInbound::class, 'i')->groupBy('i.panel')->getQuery()->getArrayResult() as $row) {
            $inbounds[(int) $row['panel']] = (int) $row['n'];
        }

        return $this->fragments->render($request, 'miniapp/admin/panels.html.twig', [
            'panels' => array_map(fn (VpnPanel $panel): array => $this->summary($request, $panel) + [
                'plans' => $plans[(int) $panel->getId()] ?? 0,
                'inbounds' => $inbounds[(int) $panel->getId()] ?? 0,
            ], $panels),
        ]);
    }

    #[Route('/app/admin/panels/{id}', name: 'miniapp_admin_panel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $panel = $this->panel($id);
        $plans = $this->planCounts('i.id');
        $inbounds = array_map(static fn (VpnInbound $inbound): array => [
            'title' => $inbound->getTitle(),
            'protocol' => $inbound->getProtocol(),
            'network' => $inbound->getNetwork(),
            'security' => $inbound->getSecurity(),
            'attached' => $inbound->isActive(),
            'plans' => $plans[(int) $inbound->getId()] ?? 0,
            'syncedAt' => $inbound->getLastSyncedAt(),
        ], $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel], ['id' => 'ASC']));

        return $this->fragments->render($request, 'miniapp/admin/panel.html.twig', [
            'panel' => $this->summary($request, $panel) + ['plans' => array_sum(array_column($inbounds, 'plans')), 'inbounds' => count($inbounds)],
            'inbounds' => $inbounds,
        ]);
    }

    /** Contacts the panel (no confirmation: it changes nothing but the stored probe result). */
    #[Route('/app/api/admin/panels/{id}/test', name: 'miniapp_admin_panel_test', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['POST'])]
    public function test(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $panel = $this->panel($id);

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $panel): array {
            $report = $this->panels->test($panel);
            // The bot's audit (AdminPanelsHandler).
            $this->auditLogger->record('vpn_panel.test', 'VpnPanel', $panel->getId(), ['ok' => $report->isOk(), 'failure' => $report->failureKey()], $user->admin);
            $answer = $this->report($request, $report);

            return [
                'message' => $report->isOk() ? $this->message($request, 'admin.panel.test_ok') : $this->message($request, 'admin.panel.test_failed', ['%failure%' => (string) $answer['failure']]),
                'report' => $answer,
            ];
        });
    }

    #[Route('/app/api/admin/panels/{id}/activate', name: 'miniapp_admin_panel_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['POST'])]
    public function activate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, true);
    }

    #[Route('/app/api/admin/panels/{id}/deactivate', name: 'miniapp_admin_panel_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['POST'])]
    public function deactivate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, false);
    }

    private function toggle(Request $request, MiniAppUser $user, int $id, bool $active): JsonResponse
    {
        $panel = $this->panel($id);
        $confirm = $this->message($request, $active ? 'admin.panel.confirm.activate' : 'admin.panel.confirm.deactivate', ['%title%' => $panel->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $panel, $active): array {
            if (!$this->panels->setActive($panel, $active)) {
                return ['error' => 'state_changed', 'message' => $this->message($request, 'admin.form.nothing_changed'), 'status' => Response::HTTP_CONFLICT];
            }
            $this->auditLogger->record($active ? 'vpn_panel.activate' : 'vpn_panel.deactivate', 'VpnPanel', $panel->getId(), ['title' => $panel->getTitle()], $user->admin);

            return ['message' => $this->message($request, $active ? 'admin.panel.activated' : 'admin.panel.deactivated')];
        });
    }

    /**
     * What the list and the page show of a panel: never an address.
     *
     * @return array{id: int, title: string, active: bool, health: string, checkedAt: ?\DateTimeImmutable, degradedSince: ?\DateTimeImmutable, mode: string, subscription: ?string, lastFailure: ?string, lastFix: ?string}
     */
    private function summary(Request $request, VpnPanel $panel): array
    {
        $status = $panel->getProbeStatus();
        $failure = null !== $status && 'ok' !== $status ? $this->failureText($request, $status, 'title') : null;

        return [
            'id' => (int) $panel->getId(),
            'title' => $panel->getTitle(),
            'active' => $panel->isActive(),
            'health' => PanelHealth::of($panel),
            'checkedAt' => $panel->getProbedAt(),
            'degradedSince' => $panel->getDegradedSince(),
            'mode' => $panel->getEffectiveIntegrationMode()->value,
            'subscription' => 'sanaei_3xui' === $panel->getType() ? PanelSubscriptionUrlCheck::problem($panel) ?? 'ok' : null,
            'lastFailure' => $failure,
            'lastFix' => null === $failure ? null : $this->failureText($request, (string) $status, 'fix'),
        ];
    }

    /**
     * The report without the panel's URL or answer: the checks, and the web's explanation of a failure.
     *
     * @return array<string, mixed>
     */
    private function report(Request $request, PanelConnectionReport $report): array
    {
        $key = $report->failureKey();

        return [
            'ok' => $report->isOk(),
            'reachable' => $report->reachable,
            'authenticated' => $report->authenticated,
            'tls' => $this->translator->trans('panel_connection.tls.'.$report->tls, [], 'admin', MiniAppLocale::fromRequest($request)),
            'mode' => $report->detectedMode?->value,
            'latencyMs' => $report->latencyMs,
            'inbounds' => $report->inboundCount,
            'failure' => null === $key ? null : $this->failureText($request, $key, 'title'),
            'fix' => null === $key ? null : $this->failureText($request, $key, 'fix'),
        ];
    }

    /** The web's text for a failure (panel_connection.failure.<key>.title|fix), or a generic one for an unknown key. */
    private function failureText(Request $request, string $key, string $part): ?string
    {
        $id = 'panel_connection.failure.'.$key.'.'.$part;
        $text = $this->translator->trans($id, [], 'admin', MiniAppLocale::fromRequest($request));
        if ($text !== $id) {
            return $text;
        }

        return 'title' === $part ? $this->message($request, 'admin.panel.failure_unknown') : null;
    }

    /**
     * Plans by inbound or by panel.
     *
     * @return array<int, int>
     */
    private function planCounts(string $groupBy): array
    {
        $counts = [];
        $rows = $this->entityManager->createQueryBuilder()->select($groupBy.' AS target', 'COUNT(p.id) AS n')->from(Plan::class, 'p')
            ->join('p.inbound', 'i')->groupBy('target')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['target']] = (int) $row['n'];
        }

        return $counts;
    }

    private function panel(int $id): VpnPanel
    {
        return $this->entityManager->find(VpnPanel::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}
