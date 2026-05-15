<?php

declare(strict_types=1);

namespace App\Admin\UI;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class AdminLocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'admin_locale_switch', methods: ['GET'], requirements: ['locale' => 'fa|en'])]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $session->set('_locale', $locale);
        $request->setLocale($locale);

        $redirectTo = trim((string) $request->query->get('redirect_to', ''));
        if ($this->isSafeRedirect($redirectTo, $request)) {
            return new RedirectResponse($redirectTo);
        }

        $referer = trim((string) $request->headers->get('referer', ''));
        if ($this->isSafeRedirect($referer, $request)) {
            return new RedirectResponse($referer);
        }

        return $this->redirectToRoute('admin');
    }

    private function isSafeRedirect(string $url, Request $request): bool
    {
        if ('' === $url) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $targetParts = parse_url($url);
        if (!is_array($targetParts)) {
            return false;
        }

        $targetHost = strtolower((string) ($targetParts['host'] ?? ''));
        $targetScheme = strtolower((string) ($targetParts['scheme'] ?? ''));
        $requestHost = strtolower((string) $request->getHost());
        $requestScheme = strtolower((string) $request->getScheme());

        return '' !== $targetHost && $targetHost === $requestHost && $targetScheme === $requestScheme;
    }
}
