<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Provisioning\Domain\PublicUrlPolicy;

/**
 * BRAND_PUBLIC_BASE_URL read against DEFAULT_URI: either both hosts are known and the brand is active, or nothing is.
 * There is one verdict for every reader (URL builders, the host guard, trusted hosts), so a value can never switch one
 * of them and not the others. Empty is the normal state; an invalid value acts as empty and carries a reason.
 */
final class BrandDomainConfig
{
    /** https:// + a DNS name or IPv4 address + an optional port, and nothing after it: no user, path, query or fragment. */
    private const BASE_PATTERN = '#^https://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?::([0-9]{1,5}))?$#D';

    private function __construct(
        public readonly ?string $brandBase = null,
        public readonly ?string $brandHost = null,
        public readonly ?string $operationalBase = null,
        public readonly ?string $operationalHost = null,
        /** Why a non-empty value was ignored (fixed text: never the value, which may hold anything). */
        public readonly ?string $problem = null,
    ) {
    }

    public static function parse(?string $brandBaseUrl, ?string $defaultUri): self
    {
        $value = strtolower(trim((string) $brandBaseUrl));
        if ('' === $value) {
            return new self();
        }
        if (1 !== preg_match(self::BASE_PATTERN, $value, $match)) {
            return new self(problem: 'it must be https:// and a host with an optional port, without a path, query or fragment');
        }

        $host = $match[1];
        $port = (int) ($match[2] ?? 443);
        if ($port < 1 || $port > 65535) {
            return new self(problem: 'the port is out of range');
        }
        if (!PublicUrlPolicy::isPubliclyUsableUrl($value)) {
            // Telegram refuses a web_app button to such an address, so the Mini App links could not follow.
            return new self(problem: 'the host is not a public address');
        }

        $operationalBase = trim((string) $defaultUri);
        $operationalHost = strtolower((string) parse_url($operationalBase, PHP_URL_HOST));
        if ('' === $operationalHost) {
            return new self(problem: 'DEFAULT_URI has no host to keep the operational URLs on');
        }
        if ($operationalHost === $host) {
            return new self(problem: 'its host is the DEFAULT_URI host');
        }

        return new self('https://'.$host.(443 === $port ? '' : ':'.$port), $host, $operationalBase, $operationalHost);
    }

    public function isActive(): bool
    {
        return null !== $this->brandBase;
    }
}
