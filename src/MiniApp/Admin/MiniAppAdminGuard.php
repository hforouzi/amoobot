<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\MiniApp\Security\MiniAppAuthenticator;
use App\MiniApp\Security\MiniAppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Server-side gate of the Mini App admin area (/app/admin/**, /app/api/admin/**): every request is checked against
 * AdminRouteAccess before its controller runs; 401 without a session or once the admin session is too old (the app
 * signs in again), 403 for anyone else, for an unmapped route, and for a permission the admin does not have now.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 16)]
final class MiniAppAdminGuard
{
    public function __construct(
        private readonly AdminRouteAccess $access,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!AdminRoutePermissions::isAdminPath($request->getPathInfo())) {
            return;
        }

        $user = $this->security->getUser();
        $route = (string) $request->attributes->get('_route');
        $denial = match (true) {
            !$user instanceof MiniAppUser => ['unauthenticated', Response::HTTP_UNAUTHORIZED],
            !$this->access->isAdmin($user) || null === AdminRoutePermissions::forRoute($route) => ['forbidden', Response::HTTP_FORBIDDEN],
            !$this->access->isSessionFresh($user) => ['admin_session_expired', Response::HTTP_UNAUTHORIZED],
            !$this->access->allows($user, $route) => ['forbidden', Response::HTTP_FORBIDDEN],
            default => null,
        };
        if (null !== $denial) {
            $event->setController(static fn (): Response => MiniAppAuthenticator::error($denial[0], $denial[1]));
        }
    }
}
