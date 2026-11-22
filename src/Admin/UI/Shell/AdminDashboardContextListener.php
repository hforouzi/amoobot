<?php

declare(strict_types=1);

namespace App\Admin\UI\Shell;

use App\Admin\UI\AdminLocaleController;
use App\Admin\UI\AdminSecurityController;
use App\Admin\UI\DashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Modern layout only: the admin pages on plain #[Route]s (confirm pages, panel setup, gateway install, plugin upload,
 * ...) get EasyAdmin's dashboard context, so they render on the EasyAdmin layout with the modern sidebar instead of
 * the standalone custom_page.html.twig shell (docs/design/admin-redesign.md, 5.1). Route, controller, permission
 * (AdminPermissionListener) and CSRF checks are unchanged: only the layout around the page differs.
 *
 * Classic: nothing is added, the pages render exactly as before.
 *
 * Priority 2: after the firewall (8) and the preview flag (4), before EasyAdmin creates its context (1).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
final class AdminDashboardContextListener
{
    /** Pages that stay outside the layout: login/logout and the locale switch (a redirect). */
    private const OUTSIDE = [AdminSecurityController::class, AdminLocaleController::class];

    public function __construct(private readonly AdminUiMode $mode)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->getBoolean(EA::ROUTE_CREATED_BY_EASYADMIN)) {
            return;
        }
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, 'App\\Admin\\UI\\')) {
            return;
        }
        $class = explode('::', $controller, 2)[0];
        if (in_array($class, self::OUTSIDE, true) || null === AdminSections::of(null, $class) || !$this->mode->isModern()) {
            return;
        }

        $request->attributes->set(EA::ROUTE_CREATED_BY_EASYADMIN, true);
        $request->attributes->set(EA::DASHBOARD_CONTROLLER_FQCN, DashboardController::class);
    }
}
