<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AdminLocaleSubscriber implements EventSubscriberInterface
{
    /**
     * @param string[] $supportedLocales
     */
    public function __construct(
        private readonly string $defaultLocale,
        private readonly array $supportedLocales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isAdminRequest($request)) {
            return;
        }

        $locale = $this->resolveLocale($request);
        if (!in_array($locale, $this->supportedLocales, true)) {
            $locale = $this->defaultLocale;
        }

        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $locale);
        }

        $request->setLocale($locale);
    }

    private function isAdminRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/admin');
    }

    private function resolveLocale(Request $request): string
    {
        $queryLocale = strtolower(trim((string) $request->query->get('_locale', '')));
        if (in_array($queryLocale, $this->supportedLocales, true)) {
            return $queryLocale;
        }

        if ($request->hasSession()) {
            $sessionLocale = strtolower(trim((string) $request->getSession()->get('_locale', '')));
            if (in_array($sessionLocale, $this->supportedLocales, true)) {
                return $sessionLocale;
            }
        }

        return $this->defaultLocale;
    }
}
