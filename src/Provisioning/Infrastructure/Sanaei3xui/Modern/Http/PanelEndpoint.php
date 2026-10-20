<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;

/**
 * The panel's normalised base URL: scheme, host (IPv6 kept in brackets), port and web base path.
 *
 * Accepts what operators paste: with or without a trailing slash, the address bar URL of the panel page
 * (https://host:2053/secret/panel/inbounds -> base path /secret), and the old separate "base path" setting.
 * Rejects URLs without http(s)://, with credentials, a query or a fragment.
 */
final readonly class PanelEndpoint
{
    private function __construct(
        public string $scheme,
        /** Host as written in a URL: IPv6 literals keep their brackets. */
        public string $host,
        public ?int $port,
        /** "" or "/segment[/segment]", never a trailing slash. */
        public string $basePath,
    ) {
    }

    public static function fromPanel(VpnPanel $panel): self
    {
        return self::fromParts((string) $panel->getBaseUrl(), $panel->getBasePath());
    }

    public static function fromParts(string $baseUrl, ?string $extraBasePath = null): self
    {
        $baseUrl = trim($baseUrl);
        if ('' === $baseUrl) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'The panel URL is empty.');
        }
        if (1 !== preg_match('#^https?://#i', $baseUrl)) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'The panel URL must start with https:// (or http:// for a panel on this server or a private network).');
        }
        $parts = parse_url($baseUrl);
        if (false === $parts || '' === (string) ($parts['host'] ?? '')) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'The panel URL is not a valid URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'The panel URL must not contain a user name or password.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'The panel URL must not contain "?" or "#" parts.');
        }

        $path = self::normalisePath((string) ($parts['path'] ?? ''));
        // The address bar URL of a panel page: everything from the /panel segment on is the panel's own routing.
        if (1 === preg_match('#^(.*?)/panel(?:/.*)?$#', $path, $m)) {
            $path = $m[1];
        }
        $extra = self::normalisePath((string) $extraBasePath);
        if ('' !== $extra && !str_ends_with($path, $extra)) {
            $path .= $extra;
        }

        return new self(strtolower((string) $parts['scheme']), strtolower((string) $parts['host']), isset($parts['port']) ? (int) $parts['port'] : null, $path);
    }

    /** Host without IPv6 brackets (for IP checks). */
    public function bareHost(): string
    {
        return trim($this->host, '[]');
    }

    public function isHttps(): bool
    {
        return 'https' === $this->scheme;
    }

    /**
     * https:// always; http:// only to "localhost" or a loopback/private/link-local IP literal (a panel on this
     * server, e.g. http://127.0.0.1:2096/, or on the same private network). Any other http:// URL would send the
     * full-admin bearer token in clear text over the internet, so it is refused. A host name other than localhost
     * is refused for http even if it resolves privately today: DNS can change.
     */
    public function assertTransportAllowed(): void
    {
        if ($this->isHttps() || self::isLocalHost($this->bareHost())) {
            return;
        }

        throw new PanelApiException(PanelFailure::NOT_CONFIGURED, sprintf('Refusing http://%s: the API token would travel in clear text. Use https://, or http:// only with 127.0.0.1 / a private IP when the panel runs on this server or the same private network.', $this->host));
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ('localhost' === $host) {
            return true;
        }
        if (false === filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Valid IP that is NOT public: private (10/8, 172.16/12, 192.168/16, fc00::/7) or reserved (127/8, ::1, 169.254/16, fe80::/10).
        return false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public function effectivePort(): int
    {
        return $this->port ?? ($this->isHttps() ? 443 : 80);
    }

    /** scheme://host[:port]/basePath/ */
    public function root(): string
    {
        return sprintf('%s://%s%s%s/', $this->scheme, $this->host, null === $this->port ? '' : ':'.$this->port, $this->basePath);
    }

    /**
     * @param array<string, scalar> $query
     */
    public function url(string $path, array $query = []): string
    {
        $url = rtrim($this->root(), '/').'/'.ltrim($path, '/');

        return [] === $query ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function normalisePath(string $path): string
    {
        $path = trim($path);
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s && '.' !== $s));

        return [] === $segments ? '' : '/'.implode('/', $segments);
    }
}
