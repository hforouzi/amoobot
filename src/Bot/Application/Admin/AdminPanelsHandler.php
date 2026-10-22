<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\VpnPanel;
use App\Provisioning\Application\PanelAdministration;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: panels ("admin.pn", PANELS_MANAGE): list with status, connection test, active on/off. Adding / editing a
 * panel stays in the web panel. The test result shows no URL, token or panel answer.
 * Callbacks: l, x:<id> (test), t:<id> / tk:<id>:<0|1>.
 */
final class AdminPanelsHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelAdministration $panels,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'pn';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 'x', 't', 'tk'], true) ? [AdminPermission::PANELS_MANAGE] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $id = $request->intArg(0);
        match ($request->verb) {
            'x' => $this->test($request, $id),
            't' => $this->askToggle($request, $id),
            'tk' => $this->toggle($request, $id, '1' === $request->arg(1)),
            default => $this->list($request),
        };
    }

    private function list(AdminRequest $request): void
    {
        /** @var list<VpnPanel> $panels */
        $panels = $this->entityManager->getRepository(VpnPanel::class)->findBy([], ['id' => 'ASC']);
        $lines = [$this->screen->text('admin.panels.title')];
        $rows = [];
        foreach ($panels as $panel) {
            $lines[] = $this->screen->text('admin.panels.line', [
                'state' => $panel->isActive() ? '✅' : '⏸',
                'id' => (int) $panel->getId(),
                'title' => $panel->getTitle(),
                'mode' => $panel->getEffectiveIntegrationMode()->value,
                'health' => $panel->isDegraded() ? $this->screen->text('admin.panels.degraded') : ($panel->getProbeStatus() ?? '-'),
            ]);
            $rows[] = [
                $this->screen->rawButton('🔌 '.$panel->getTitle(), 'admin.pn:x:'.$panel->getId()),
                $this->screen->button($panel->isActive() ? 'button.admin.deactivate' : 'button.admin.activate', 'admin.pn:t:'.$panel->getId()),
            ];
        }
        if ([] === $panels) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $lines[] = $this->screen->text('admin.web_only_hint');
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard($rows));
    }

    private function test(AdminRequest $request, int $id): void
    {
        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($id);
        if (!$panel instanceof VpnPanel) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pn:l');

            return;
        }
        $report = $this->panels->test($panel);
        $this->screen->audit($request, 'vpn_panel.test', 'VpnPanel', $id, ['ok' => $report->isOk(), 'failure' => $report->failureKey()]);

        $yesNo = fn (?bool $value): string => null === $value ? '—' : ($value ? '✅' : '❌');
        $this->screen->notice($request, 'admin.panels.test_result', [
            'title' => $panel->getTitle(),
            'reachable' => $yesNo($report->reachable),
            'tls' => $report->tls,
            'auth' => $yesNo($report->authenticated),
            'mode' => $report->detectedMode->value ?? '—',
            'latency' => null === $report->latencyMs ? '—' : $report->latencyMs.' ms',
            'inbounds' => $report->inboundCount ?? '—',
            'verdict' => $report->isOk() ? $this->screen->text('admin.panels.test_ok') : $this->screen->text('admin.panels.test_failed', ['failure' => $report->failureKey() ?? '-']),
        ], 'admin.pn:l');
    }

    private function askToggle(AdminRequest $request, int $id): void
    {
        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($id);
        if (!$panel instanceof VpnPanel) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pn:l');

            return;
        }
        $activate = !$panel->isActive();
        $this->screen->confirm($request, $this->screen->text($activate ? 'admin.panels.confirm_activate' : 'admin.panels.confirm_deactivate', ['title' => $panel->getTitle()]), 'admin.pn:tk:'.$id.':'.($activate ? '1' : '0'), 'admin.pn:l');
    }

    private function toggle(AdminRequest $request, int $id, bool $active): void
    {
        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($id);
        if (!$panel instanceof VpnPanel) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pn:l');

            return;
        }
        if (!$this->panels->setActive($panel, $active)) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.pn:l');

            return;
        }
        $this->screen->audit($request, $active ? 'vpn_panel.activate' : 'vpn_panel.deactivate', 'VpnPanel', $id, ['title' => $panel->getTitle()]);
        $this->screen->notice($request, 'admin.done', [], 'admin.pn:l');
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
