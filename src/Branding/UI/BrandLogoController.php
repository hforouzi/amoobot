<?php

declare(strict_types=1);

namespace App\Branding\UI;

use App\Branding\BrandingSettings;
use App\Branding\BrandLogoStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The brand logo (/brand/logo/{name}), public: streamed from outside public/ (BrandLogoStorage). Only the current
 * logo's stored name answers; any other name, a logo that is not set and a missing file are 404. A new upload gets a
 * new random name, so the file behind a URL never changes: it is cached for a year (immutable) with the name as ETag.
 */
final class BrandLogoController
{
    public function __construct(
        private readonly BrandingSettings $branding,
        private readonly BrandLogoStorage $storage,
    ) {
    }

    #[Route('/brand/logo/{name}', name: 'brand_logo', requirements: ['name' => '[a-f0-9]{32}\.(?:png|jpg|webp)'], methods: ['GET', 'HEAD'])]
    public function logo(Request $request, string $name): Response
    {
        if ($this->branding->current()->logo !== $name || !$this->storage->exists($name)) {
            return $this->headers(new Response('Not found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']));
        }

        $response = new BinaryFileResponse($this->storage->path($name), Response::HTTP_OK, [], true, null, false, false);
        $response->headers->set('Content-Type', BrandLogoStorage::contentType($name));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'logo.'.pathinfo($name, PATHINFO_EXTENSION));
        $response->setEtag(substr($name, 0, 32));
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        if (!$response->isNotModified($request)) {
            $response->prepare($request);
        }

        return $this->headers($response);
    }

    private function headers(Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Opened on its own, the image can run nothing and load nothing.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }
}
