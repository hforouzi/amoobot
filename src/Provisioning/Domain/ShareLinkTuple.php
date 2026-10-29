<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * A share link reduced to what app:service:verify-links compares (docs/design/multi-inbound-plans.md 9.6, step 3):
 * scheme, address, port, security, sni, type, host header, path, remark, and the credential (VLESS/VMess/TUIC UUID,
 * Trojan/Shadowsocks password). The credential is only compared, never shown: describe() leaves it out.
 */
final readonly class ShareLinkTuple
{
    public function __construct(
        public string $scheme,
        public string $address,
        public int $port,
        public string $security,
        public string $sni,
        public string $type,
        public string $host,
        public string $path,
        public string $remark,
        public string $credential,
    ) {
    }

    public static function parse(string $link): ?self
    {
        $scheme = strtolower((string) (parse_url($link, PHP_URL_SCHEME) ?? ''));
        if ('vmess' === $scheme) {
            $json = json_decode((string) base64_decode(substr($link, strlen('vmess://')), true), true);
            if (!is_array($json)) {
                return null;
            }
            $s = static fn (string $k): string => is_scalar($json[$k] ?? null) ? (string) $json[$k] : '';

            return new self('vmess', strtolower($s('add')), (int) $s('port'), '' === $s('tls') ? 'none' : $s('tls'), $s('sni'), $s('net'), $s('host'), $s('path'), $s('ps'), strtolower($s('id')));
        }
        $parts = parse_url($link);
        if (!is_array($parts) || '' === (string) ($parts['host'] ?? '')) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $q = static fn (string $k): string => is_string($query[$k] ?? null) ? $query[$k] : '';
        $credential = rawurldecode((string) ($parts['user'] ?? ''));
        if (isset($parts['pass'])) {
            $credential .= ':'.rawurldecode((string) $parts['pass']);
        }
        if ('ss' === $scheme) {
            // SIP002: base64(method:password) or, for 2022 methods, percent-encoded method:password.
            $decoded = base64_decode(strtr($credential, '-_', '+/'), true);
            if (false !== $decoded && str_contains($decoded, ':')) {
                $credential = $decoded;
            }
        }

        return new self(
            $scheme,
            strtolower(trim((string) $parts['host'], '[]')),
            (int) ($parts['port'] ?? 0),
            '' === $q('security') ? 'none' : $q('security'),
            $q('sni'),
            $q('type'),
            $q('host'),
            $q('path'),
            rawurldecode((string) ($parts['fragment'] ?? '')),
            in_array($scheme, ['vless', 'vmess', 'tuic'], true) ? strtolower($credential) : $credential,
        );
    }

    /**
     * The UUID with bytes 6-7 masked: a host's "VLESS route" rewrites them in the link (3x-ui applyVlessRoute), so a
     * link still belongs to the client when everything else matches.
     */
    public static function maskedUuid(string $uuid): string
    {
        $uuid = strtolower(explode(':', $uuid)[0]);

        return 1 === preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-)[0-9a-f]{4}(-[0-9a-f]{4}-[0-9a-f]{12})$/', $uuid, $m) ? $m[1].'xxxx'.$m[2] : $uuid;
    }

    /**
     * The comparison key (step 4): sni, address and remark can be left out where the panel picks them per request
     * (SNI pools, CIDR addresses, remark templates).
     */
    public function key(bool $withSni = true, bool $withAddress = true, bool $withRemark = true): string
    {
        return implode('|', [
            $this->scheme, $withAddress ? $this->address : '*', $this->port, $this->security, $withSni ? $this->sni : '*', $this->type, $this->host, $this->path,
            $withRemark ? $this->remark : '*', in_array($this->scheme, ['vless', 'vmess', 'tuic'], true) ? self::maskedUuid($this->credential) : hash('sha256', $this->credential),
        ]);
    }

    /** For people: no credential. */
    public function describe(): string
    {
        return sprintf('%s %s:%d security=%s sni=%s type=%s host=%s path=%s #%s', $this->scheme, $this->address, $this->port, $this->security, '' === $this->sni ? '-' : $this->sni,
            '' === $this->type ? '-' : $this->type, '' === $this->host ? '-' : $this->host, '' === $this->path ? '-' : $this->path, mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $this->remark), 0, 60));
    }
}
