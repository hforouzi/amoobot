<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\BrandDomainConfig;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The optional customer-facing domain (BRAND_PUBLIC_BASE_URL). It serves only the Mini App and the payment return page;
 * everything else stays on DEFAULT_URI, which is never replaced. Not set, or not valid: every method answers "no brand"
 * and the shop works on one domain, as before.
 */
final class BrandDomain
{
    private const WARNING_REPEAT_SECONDS = 86400;

    private readonly BrandDomainConfig $config;

    public function __construct(
        #[Autowire('%env(default::BRAND_PUBLIC_BASE_URL)%')]
        ?string $brandBaseUrl,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        ?string $defaultUri,
        private readonly RequestStack $requestStack,
        LoggerInterface $logger,
        CacheItemPoolInterface $cache,
    ) {
        $this->config = BrandDomainConfig::parse($brandBaseUrl, $defaultUri);

        if (null !== $this->config->problem) {
            // This object is built on every request: the cache keeps the log to one line for a given value.
            $warned = $cache->getItem('brand_domain.invalid.'.sha1((string) $brandBaseUrl));
            if (!$warned->isHit()) {
                $logger->warning('BRAND_PUBLIC_BASE_URL is ignored: '.$this->config->problem.'.');
                $cache->save($warned->set(true)->expiresAfter(self::WARNING_REPEAT_SECONDS));
            }
        }
    }

    public function isActive(): bool
    {
        return $this->config->isActive();
    }

    /** The brand's origin without a trailing slash, or null when there is no brand. */
    public function base(): ?string
    {
        return $this->config->brandBase;
    }

    /** DEFAULT_URI, or null when there is no brand (the router then decides, as it always did). */
    public function operationalBase(): ?string
    {
        return $this->config->operationalBase;
    }

    /** Whether the current HTTP request arrived on the brand's host; false outside a request and with no brand. */
    public function isBrandRequest(): bool
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && $this->config->isActive() && $this->config->brandHost === strtolower($request->getHost());
    }
}
