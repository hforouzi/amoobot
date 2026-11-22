<?php

declare(strict_types=1);

namespace App\Admin\UI\Shell;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The preview of the modern layout before the flip (decision 2, AdminUiMode): on an /admin page, ?admin_ui=preview
 * turns the session flag on, for a super admin only (for anyone else the parameter is ignored and nothing is
 * stored); ?admin_ui=classic turns it off for anyone. It is a display preference of the admin's own session: no data
 * changes, so a GET is the right method.
 *
 * Priority 4: after the firewall (8, the admin is known), before EasyAdmin builds its context (1, the menu and the
 * title read AdminUiMode).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class AdminUiPreviewListener
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminUiMode $mode,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->query->has(AdminUiMode::QUERY) || !$request->hasSession()
            || !str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        $value = $request->query->get(AdminUiMode::QUERY);
        if (AdminUiMode::QUERY_CLASSIC === $value) {
            if ($request->getSession()->has(AdminUiMode::SESSION_PREVIEW)) {
                $request->getSession()->remove(AdminUiMode::SESSION_PREVIEW);
            }
        } elseif (AdminUiMode::QUERY_PREVIEW === $value && AdminUiMode::maySeePreview($this->security->getUser())) {
            $request->getSession()->set(AdminUiMode::SESSION_PREVIEW, true);
        }
        $this->mode->reset();
    }
}
