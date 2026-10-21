<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Server-side gate for every admin controller action (EasyAdmin pretty URLs, autocomplete, batch actions and the
 * controllers' own #[Route]s): the permission from AdminCrudPermissions, checked before the action runs (and before
 * its entity is loaded). An admin controller with no permission is closed (fail-safe for new pages).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 0)]
final class AdminPermissionListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $class = match (true) {
            is_array($controller) && is_object($controller[0]) => $controller[0]::class,
            is_array($controller) => $controller[0],
            is_object($controller) && !$controller instanceof \Closure => $controller::class,
            default => null,
        };
        if (null === $class || !str_starts_with($class, 'App\\Admin\\')) {
            return;
        }

        $permission = AdminCrudPermissions::forRoute((string) $event->getRequest()->attributes->get('_route'))
            ?? AdminCrudPermissions::forController($class)?->view;
        if (null === $permission) {
            if (AdminCrudPermissions::isOpen($class)) {
                return;
            }
            throw new AccessDeniedException(sprintf('%s has no admin permission.', $class));
        }

        if (!$this->security->isGranted($permission->value)) {
            $exception = new AccessDeniedException(sprintf('Access Denied: %s.', $permission->name));
            $exception->setAttributes([$permission->value]);

            throw $exception;
        }
    }
}
