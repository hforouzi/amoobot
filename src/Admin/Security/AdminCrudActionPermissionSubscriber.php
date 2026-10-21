<?php

declare(strict_types=1);

namespace App\Admin\Security;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * New/edit need the page's "manage" permission, delete/batch delete its "delete" permission (AdminCrudPermissions).
 * EasyAdmin then both refuses those actions (403) and hides their buttons. A permission the controller sets itself
 * for one of these actions is kept.
 */
#[AsEventListener(event: BeforeCrudActionEvent::class)]
final class AdminCrudActionPermissionSubscriber
{
    public function __invoke(BeforeCrudActionEvent $event): void
    {
        $crud = $event->getAdminContext()?->getCrud();
        $permission = null === $crud ? null : AdminCrudPermissions::forController((string) $crud->getControllerFqcn());
        if (null === $crud || null === $permission) {
            return;
        }

        $actions = $crud->getActionsConfig();
        $configured = $actions->getActionPermissions();
        foreach ([Action::NEW => $permission->manage, Action::EDIT => $permission->manage, Action::DELETE => $permission->delete, Action::BATCH_DELETE => $permission->delete] as $action => $required) {
            if (!isset($configured[$action])) {
                $actions->setActionPermission($action, $required->value);
            }
        }
    }
}
