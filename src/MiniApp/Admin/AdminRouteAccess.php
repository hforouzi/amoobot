<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\MiniApp\Security\MiniAppUser;
use App\Security\AdminAccessPolicy;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one rule for the Mini App admin area, asked by the guard (every request) and by the pages (which buttons to
 * render): the user resolves to an active admin on this very request (MiniAppUser::$admin, re-read from the database
 * by the authenticator), the admin session is not older than the admin TTL, and the route's permission
 * (AdminRoutePermissions) is granted. An unmapped route is refused.
 */
final class AdminRouteAccess
{
    public function __construct(
        private readonly AdminAccessPolicy $policy,
        private readonly ClockInterface $clock,
        #[Autowire('%env(int:default:miniapp_admin_session_ttl_default:MINIAPP_ADMIN_SESSION_TTL)%')]
        private readonly int $adminSessionTtl,
    ) {
    }

    public function sessionTtl(): int
    {
        return $this->adminSessionTtl;
    }

    public function isAdmin(MiniAppUser $user): bool
    {
        return [] !== $this->policy->permissions($user->admin);
    }

    /** Admin rights end this long after sign-in, whatever the customer session allows. */
    public function isSessionFresh(MiniAppUser $user): bool
    {
        return $this->clock->now()->getTimestamp() - $user->session->getCreatedAt()->getTimestamp() < $this->adminSessionTtl;
    }

    public function allows(MiniAppUser $user, string $route): bool
    {
        $required = AdminRoutePermissions::forRoute($route);
        if (null === $required || !$this->isSessionFresh($user)) {
            return false;
        }

        return AdminRoutePermissions::ANY_ADMIN === $required ? $this->isAdmin($user) : $this->policy->can($user->admin, $required);
    }

    /**
     * Which of these routes the user may call: the pages render a button only for these.
     *
     * @param list<string> $routes
     *
     * @return array<string, bool>
     */
    public function map(MiniAppUser $user, array $routes): array
    {
        $allowed = [];
        foreach ($routes as $route) {
            $allowed[$route] = $this->allows($user, $route);
        }

        return $allowed;
    }
}
