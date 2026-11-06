<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The two kinds of absolute URL of a shop with a brand domain. The router alone cannot be asked for either: inside a
 * request it answers with the host that request came in on, so a payment or delivery run from the Mini App on the brand
 * host would hand out links that host does not serve.
 *
 * With no brand both are the router's plain answer, unchanged.
 */
final class SiteUrls
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly BrandDomain $brand,
    ) {
    }

    /** The Mini App and the payment return page: the brand base. */
    public function customer(string $route, array $parameters = []): string
    {
        return $this->on($this->brand->base(), $route, $parameters);
    }

    /** Everything else (subscription pages, downloads, callbacks, webhooks): DEFAULT_URI, whatever host is asking. */
    public function operational(string $route, array $parameters = []): string
    {
        return $this->on($this->brand->operationalBase(), $route, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function on(?string $base, string $route, array $parameters): string
    {
        if (null === $base) {
            return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $requestContext = $this->urlGenerator->getContext();
        $this->urlGenerator->setContext(RequestContext::fromUri($base)->setParameters($requestContext->getParameters()));
        try {
            return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->urlGenerator->setContext($requestContext);
        }
    }
}
