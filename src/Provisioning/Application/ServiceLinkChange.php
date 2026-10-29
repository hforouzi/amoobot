<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * One service's links before and after a console regeneration (ServiceLinkRegenerator), applied or not (dry run).
 * $source says where the new links come from: "panel" (modern 3x-ui subLinks, Hosts applied), "legacy" (the legacy
 * 3x-ui driver) or "generated" (built from the inbound, panels whose driver cannot render links).
 */
final readonly class ServiceLinkChange
{
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    /** The panel gave no links: the stored ones were kept. */
    public const KEPT = 'kept';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    public function __construct(
        public string $outcome,
        public string $source,
        public array $before,
        public array $after,
        public bool $applied,
        public ?string $error = null,
    ) {
    }

    /** @return list<string> */
    public function added(): array
    {
        return array_values(array_diff($this->after, $this->before));
    }

    /** @return list<string> */
    public function removed(): array
    {
        return self::UPDATED === $this->outcome ? array_values(array_diff($this->before, $this->after)) : [];
    }

    /**
     * A link as an admin may see it in a terminal: scheme, address:port and remark, never the UUID or password.
     */
    public static function describe(string $link): string
    {
        $scheme = strtolower((string) (parse_url($link, PHP_URL_SCHEME) ?? '?'));
        if ('vmess' === $scheme) {
            $json = json_decode((string) base64_decode(substr($link, 8), true), true);
            if (is_array($json)) {
                return sprintf('vmess %s:%s #%s', self::clean($json['add'] ?? '?'), self::clean($json['port'] ?? '?'), self::clean($json['ps'] ?? ''));
            }
        }
        $host = parse_url($link, PHP_URL_HOST);
        $port = parse_url($link, PHP_URL_PORT);
        $remark = rawurldecode((string) (parse_url($link, PHP_URL_FRAGMENT) ?? ''));

        return sprintf('%s %s:%s #%s', $scheme, self::clean($host ?? '?'), self::clean($port ?? '?'), self::clean($remark));
    }

    private static function clean(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '?';

        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text), 0, 80);
    }
}
