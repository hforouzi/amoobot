<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Application\SettingsAdministration;
use App\Admin\UI\Support\AdminTabs;
use App\Entity\RequiredChannel;
use App\Entity\Setting;
use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\PlanInboundUsage;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One settings page (SETTINGS_MANAGE) with tabs, ?tab=...: sales & renewal pricing, automation, wallet, resellers, plans (the
 * multi-inbound switch, web only), required channels
 * (the RequiredChannel list; create/edit stay on its CRUD pages) and advanced (the raw Setting keys, behind a
 * warning). Each form tab saves only its own keys and validates as the separate pages did; the old pages redirect
 * here (LegacyAdminRoutes, and the RequiredChannel / Setting CRUD index()). The two forms are SettingsAdministration's
 * (shared with the Mini App settings).
 */
final class SettingsAdminController extends AbstractController
{
    use TranslatedFlashes;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsAdministration $settings,
        private readonly AdminTabs $tabs,
        private readonly PlanInboundUsage $planInboundUsage,
    ) {
    }

    #[AdminRoute(path: '/settings', name: 'settings', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request, AdminAuditLogger $auditLogger): Response
    {
        $tab = (string) $request->query->get('tab', 'sales');
        if (!array_key_exists($tab, AdminTabs::SETTINGS)) {
            $tab = 'sales';
        }

        if ($request->isMethod('POST')) {
            match ($tab) {
                'sales' => $this->saveSales($request, $auditLogger),
                'automation' => $this->saveAutomation($request, $auditLogger),
                'wallet' => $this->saveWallet($request, $auditLogger),
                'resellers' => $this->saveReseller($request, $auditLogger),
                'plans' => $this->savePlans($request, $auditLogger),
                // The lists have no form of their own (their rows are edited on the CRUD pages).
                default => null,
            };
        }

        return $this->render('admin/settings/index.html.twig', [
            'tab' => $tab,
            'tabs' => $this->tabs->settings($tab),
        ] + match ($tab) {
            'sales' => $this->salesParameters(),
            'automation' => ['values' => $this->settings->automationValues()],
            'wallet' => ['values' => $this->settings->walletValues()],
            'resellers' => ['values' => $this->settings->resellerValues()],
            'plans' => ['values' => $this->settings->plansValues()],
            'channels' => ['channels' => $this->entityManager->getRepository(RequiredChannel::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC'])],
            'advanced' => ['settings' => $this->entityManager->getRepository(Setting::class)->findBy([], ['keyName' => 'ASC'])],
        });
    }

    private function saveSales(Request $request, AdminAuditLogger $auditLogger): void
    {
        $problem = $this->settings->saveSales($request->request->all());
        if (null !== $problem) {
            $this->flash('danger', $problem['message']);

            return;
        }
        $auditLogger->record('settings.update', 'Setting', null, ['page' => 'renewal_pricing']);
        $this->flash('success', 'flash.settings.sales_saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function salesParameters(): array
    {
        return [
            'values' => $this->settings->salesValues(),
            'keys' => [
                'sales_new_orders_enabled' => SalesSettingsProvider::NEW_ORDERS_ENABLED,
                'sales_renewals_enabled' => SalesSettingsProvider::RENEWALS_ENABLED,
                'sales_add_traffic_enabled' => SalesSettingsProvider::ADD_TRAFFIC_ENABLED,
                'sales_disabled_message' => SalesSettingsProvider::DISABLED_MESSAGE,
            ],
        ];
    }

    private function saveWallet(Request $request, AdminAuditLogger $auditLogger): void
    {
        $problem = $this->settings->saveWallet($request->request->all());
        if (null !== $problem) {
            $this->flash('danger', $problem['message']);

            return;
        }
        $auditLogger->record('settings.update', 'Setting', null, ['page' => 'wallet'] + $this->settings->walletValues());
        $this->flash('success', 'flash.settings.wallet_saved');
    }

    private function saveReseller(Request $request, AdminAuditLogger $auditLogger): void
    {
        $problem = $this->settings->saveReseller($request->request->all());
        if (null !== $problem) {
            $this->flash('danger', $problem['message']);

            return;
        }
        $auditLogger->record('settings.update', 'Setting', null, ['page' => 'resellers'] + $this->settings->resellerValues());
        $this->flash('success', 'flash.settings.reseller_saved');
    }

    /** Turning the switch off while plans keep additional inbounds: they stay unsold, and the admin is told which. */
    private function savePlans(Request $request, AdminAuditLogger $auditLogger): void
    {
        $problem = $this->settings->savePlans($request->request->all());
        if (null !== $problem) {
            $this->flash('danger', $problem['message']);

            return;
        }
        $auditLogger->record('settings.update', 'Setting', null, ['page' => 'plans'] + $this->settings->plansValues());
        $this->flash('success', 'flash.settings.plans_saved');
        if (!$this->settings->plansValues()['plans_multi_inbound_enabled']) {
            $plans = $this->planInboundUsage->plansWithAnyAdditional();
            if ([] !== $plans) {
                $this->flash('warning', 'flash.settings.plans_still_multi_inbound', ['%plans%' => PlanInboundUsage::names($plans)]);
            }
        }
    }

    private function saveAutomation(Request $request, AdminAuditLogger $auditLogger): void
    {
        $problem = $this->settings->saveAutomation($request->request->all());
        if (null !== $problem) {
            $this->flash('danger', $problem['message']);

            return;
        }
        $auditLogger->record('settings.update', 'Setting', null, ['page' => 'automation']);
        $this->flash('success', 'flash.settings.automation_saved');
    }
}
