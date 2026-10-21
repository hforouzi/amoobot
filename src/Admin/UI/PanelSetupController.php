<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Form\PanelConnectionType;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Application\ModernModeGuard;
use App\Provisioning\Application\PanelConnectionTester;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelEndpoint;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guided 3x-ui panel setup: 1. name, URL, API token (+ collapsed advanced settings) and "Test connection";
 * 2. pick the inbounds to sell from (panel/api/inbounds/options); 3. map plans to those inbounds.
 * Every state change is a POST with a CSRF token; GET pages change nothing on the bot's side.
 */
#[Route('/admin/panel-setup')]
final class PanelSetupController extends AbstractController
{
    use EscapesFlashMessages;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelConnectionTester $tester,
        private readonly ModernModeGuard $modernModeGuard,
        private readonly ModernClientsApi $clientsApi,
        private readonly PanelCredentials $credentials,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/new', name: 'admin_panel_setup_new', methods: ['GET', 'POST'])]
    #[Route('/{id}/edit', name: 'admin_panel_setup_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ?VpnPanel $panel = null): Response
    {
        $isNew = null === $panel;
        $panel ??= (new VpnPanel())->setType('sanaei_3xui');
        $previousMode = $panel->getIntegrationModeValue();
        $legacyCredentials = !$isNew && $this->usesLegacyCredentials($panel);
        $form = $this->createForm(PanelConnectionType::class, $panel, ['legacy_credentials' => $legacyCredentials]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->validateConnectionSettings($form, $panel, $previousMode, $legacyCredentials);
            if (0 === count($form->getErrors(true))) {
                // New panels are 3x-ui; an existing panel keeps its type (this is also the only edit form).
                if ($isNew) {
                    $panel->setType('sanaei_3xui');
                }
                // The form skips the setter when the value is unchanged; an old row may still have the URL only in the
                // config copy (read through the getter's fallback): store it in its one place now.
                $panel->setSubscriptionBaseUrl($panel->getSubscriptionBaseUrl());
                $panel->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($panel);
                $this->entityManager->flush();
                $this->addFlash('success', $this->trans($isNew ? 'panel_connection.flash.created' : 'panel_connection.flash.saved'));

                return $this->redirectToRoute('admin_panel_setup_show', ['id' => $panel->getId()]);
            }
        }

        return $this->render('admin/panel_setup/edit.html.twig', [
            'form' => $form,
            'panel' => $panel,
            'is_new' => $isNew,
            'token_mask' => $isNew ? '' : $this->tokenMask($panel),
            'password_configured' => $panel->hasPassword(),
        ]);
    }

    #[Route('/{id}', name: 'admin_panel_setup_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(VpnPanel $panel): Response
    {
        return $this->renderShow($panel, null);
    }

    #[Route('/{id}/test', name: 'admin_panel_setup_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(VpnPanel $panel, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('panel_test_'.$panel->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->trans('panel_connection.flash.csrf'));

            return $this->redirectToRoute('admin_panel_setup_show', ['id' => $panel->getId()]);
        }
        $report = $this->tester->test($panel);
        $this->entityManager->flush();

        return $this->renderShow($panel, $report);
    }

    #[Route('/{id}/inbounds', name: 'admin_panel_setup_inbounds', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function inbounds(VpnPanel $panel, Request $request): Response
    {
        if (!$panel->isModern()) {
            $this->addFlash('warning', $this->trans('panel_connection.inbounds.needs_modern'));

            return $this->redirectToRoute('admin_panel_setup_show', ['id' => $panel->getId()]);
        }
        try {
            $options = $this->clientsApi->inboundOptions($panel);
        } catch (PanelApiException $e) {
            $this->entityManager->flush();

            return $this->render('admin/panel_setup/inbounds.html.twig', ['panel' => $panel, 'options' => [], 'selected' => [], 'error' => $e]);
        }
        /** @var array<int|string, VpnInbound> $local by remote inbound id (numeric ids become int keys) */
        $local = [];
        foreach ($this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel]) as $inbound) {
            $local[$inbound->getRemoteInboundId()] = $inbound;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('panel_inbounds_'.$panel->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', $this->trans('panel_connection.flash.csrf'));

                return $this->redirectToRoute('admin_panel_setup_inbounds', ['id' => $panel->getId()]);
            }
            $chosen = array_map('intval', $request->request->all('inbounds'));
            $count = 0;
            foreach ($options as $option) {
                $selected = $option['enable'] && in_array($option['id'], $chosen, true);
                $inbound = $local[$option['id']] ?? null;
                if (!$selected) {
                    // Kept (services may use it), only no longer offered.
                    $inbound?->setIsActive(false);
                    continue;
                }
                if (null === $inbound) {
                    $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $option['id']);
                    $this->entityManager->persist($inbound);
                }
                $inbound->setTitle('' !== $option['remark'] ? $option['remark'] : $option['tag'])
                    ->setRemark($option['remark'])
                    ->setProtocol($option['protocol'])
                    ->setPort($option['port'])
                    ->setIsActive(true);
                if ($option['tlsFlowCapable'] && 'vless' === strtolower($option['protocol']) && '' === trim((string) $inbound->getFlow())) {
                    $inbound->setFlow('xtls-rprx-vision');
                }
                ++$count;
            }
            $this->entityManager->flush();
            $this->addFlash('success', $this->trans('panel_connection.inbounds.saved', ['%count%' => $count]));

            return $this->redirectToRoute('admin_panel_setup_plans', ['id' => $panel->getId()]);
        }
        $this->entityManager->flush();

        $selected = array_keys(array_filter($local, static fn (VpnInbound $i): bool => $i->isActive()));

        return $this->render('admin/panel_setup/inbounds.html.twig', [
            'panel' => $panel,
            'options' => $options,
            'selected' => array_map('intval', $selected),
            'error' => null,
        ]);
    }

    #[Route('/{id}/plans', name: 'admin_panel_setup_plans', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function plans(VpnPanel $panel, Request $request): Response
    {
        $inbounds = $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel, 'isActive' => true], ['id' => 'ASC']);
        $plans = $this->entityManager->getRepository(Plan::class)->findBy([], ['isActive' => 'DESC', 'id' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('panel_plans_'.$panel->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', $this->trans('panel_connection.flash.csrf'));

                return $this->redirectToRoute('admin_panel_setup_plans', ['id' => $panel->getId()]);
            }
            $byId = [];
            foreach ($inbounds as $inbound) {
                $byId[(int) $inbound->getId()] = $inbound;
            }
            $changed = 0;
            foreach ($request->request->all('plan') as $planId => $inboundId) {
                $plan = $this->entityManager->find(Plan::class, (int) $planId);
                $inbound = $byId[(int) $inboundId] ?? null;
                if ($plan instanceof Plan && null !== $inbound && $plan->getInbound()?->getId() !== $inbound->getId()) {
                    $plan->setInbound($inbound);
                    ++$changed;
                }
            }
            $this->entityManager->flush();
            $this->addFlash('success', $this->trans('panel_connection.plans.saved', ['%count%' => $changed]));

            return $this->redirectToRoute('admin_panel_setup_plans', ['id' => $panel->getId()]);
        }

        return $this->render('admin/panel_setup/plans.html.twig', ['panel' => $panel, 'plans' => $plans, 'inbounds' => $inbounds]);
    }

    private function renderShow(VpnPanel $panel, ?\App\Provisioning\Application\PanelConnectionReport $report): Response
    {
        try {
            $root = PanelEndpoint::fromPanel($panel)->root();
        } catch (PanelApiException) {
            $root = (string) $panel->getBaseUrl();
        }

        return $this->render('admin/panel_setup/show.html.twig', [
            'panel' => $panel,
            'root_url' => $root,
            'token_mask' => $this->tokenMask($panel),
            'report' => $report,
            'used_by_active_plan' => $this->modernModeGuard->usedByActivePlan($panel),
        ]);
    }

    /**
     * A panel on the old username/password API: forced to legacy, detected as legacy, or already holding a username
     * or password (a panel added before the guided setup and never probed). A new or token-only panel is not.
     */
    private function usesLegacyCredentials(VpnPanel $panel): bool
    {
        if ($panel->isModern()) {
            return false;
        }

        return SanaeiIntegrationMode::LEGACY === $panel->getIntegrationMode()
            || SanaeiIntegrationMode::LEGACY === $panel->getDetectedIntegrationMode()
            || '' !== trim((string) $panel->getUsername())
            || $panel->hasPassword();
    }

    /**
     * URL (parsable, http only to local addresses), token present (or, on a legacy panel, a username and password),
     * TLS pin format, and the modern-mode rule.
     *
     * @param \Symfony\Component\Form\FormInterface<VpnPanel> $form
     */
    private function validateConnectionSettings(\Symfony\Component\Form\FormInterface $form, VpnPanel $panel, string $previousMode, bool $legacyCredentials): void
    {
        try {
            $endpoint = PanelEndpoint::fromParts((string) $panel->getBaseUrl(), $panel->getBasePath());
            try {
                $endpoint->assertTransportAllowed();
            } catch (PanelApiException) {
                $form->get('baseUrl')->addError(new FormError($this->trans('panel_connection.validation.url_plain_http')));
            }
        } catch (PanelApiException) {
            $form->get('baseUrl')->addError(new FormError($this->trans('panel_connection.validation.url_invalid')));
        }
        $legacyLogin = $legacyCredentials && '' !== trim((string) $panel->getUsername()) && $panel->hasPassword();
        if (!$panel->isApiTokenConfigured() && !$legacyLogin) {
            $form->get('plainApiToken')->addError(new FormError($this->trans($legacyCredentials ? 'panel_connection.validation.token_or_login_required' : 'panel_connection.validation.token_required')));
        }
        try {
            $panel->setTlsPinSha256((string) $form->get('tlsPin')->getData());
        } catch (\InvalidArgumentException) {
            $form->get('tlsPin')->addError(new FormError($this->trans('panel_connection.validation.tls_pin')));
        }
        if ($panel->getIntegrationModeValue() !== $previousMode) {
            $refusal = $this->modernModeGuard->refusal($panel, $panel->getIntegrationMode());
            if (null !== $refusal) {
                $form->get('integrationModeValue')->addError(new FormError($this->trans($refusal['key'], $refusal['params'])));
            }
        }
    }

    private function tokenMask(VpnPanel $panel): string
    {
        try {
            return SecretRedactor::mask($this->credentials->apiToken($panel));
        } catch (\RuntimeException) {
            return '⚠';
        }
    }

    /**
     * @param array<string, string|int> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'admin');
    }
}
