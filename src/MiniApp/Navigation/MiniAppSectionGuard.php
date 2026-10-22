<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

use App\MiniApp\Security\MiniAppAuthenticator;
use App\MiniApp\Security\MiniAppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Server-side half of the role-aware navigation: a route with the default "_miniapp_section" runs only for a user
 * MiniAppSections::isAllowed() lets into that section (403 otherwise), whatever the menu showed.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final class MiniAppSectionGuard
{
    public function __construct(
        private readonly MiniAppSections $sections,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $section = $event->getRequest()->attributes->get('_miniapp_section');
        if (null === $section) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof MiniAppUser && $this->sections->isAllowed((string) $section, $user)) {
            return;
        }

        $status = $user instanceof MiniAppUser ? Response::HTTP_FORBIDDEN : Response::HTTP_UNAUTHORIZED;
        $event->setController(static fn (): Response => MiniAppAuthenticator::error(Response::HTTP_FORBIDDEN === $status ? 'forbidden' : 'unauthenticated', $status));
    }
}
