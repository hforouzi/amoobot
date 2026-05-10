<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

use App\Entity\VpnInbound;

final class Sanaei3xuiConfigGenerator
{
    public function generateConfigText(VpnInbound $inbound, string $uuid, string $email, string $subId): string
    {
        $uuid = trim($uuid);
        if ('' === $uuid) {
            return '';
        }

        $protocol = strtolower(trim((string) ($inbound->getProtocol() ?? '')));
        if ('vless' !== $protocol) {
            return '';
        }

        [$streamSettings, $externalProxies] = $this->normalizeConfig($inbound);
        if ([] === $externalProxies) {
            return '';
        }

        $network = strtolower(trim((string) ($streamSettings['network'] ?? $inbound->getNetwork() ?? '')));
        if ('ws' !== $network) {
            return '';
        }

        $security = strtolower(trim((string) ($streamSettings['security'] ?? $inbound->getSecurity() ?? 'none')));
        if ('' === $security) {
            $security = 'none';
        }

        $wsSettings = $this->toArray($streamSettings['wsSettings'] ?? null);
        $wsHeaders = $this->toArray($wsSettings['headers'] ?? null);
        $wsPath = trim((string) ($wsSettings['path'] ?? $inbound->getPath() ?? '/'));
        if ('' === $wsPath) {
            $wsPath = '/';
        }
        $wsHost = trim((string) ($wsSettings['host'] ?? $wsHeaders['Host'] ?? $wsHeaders['host'] ?? $inbound->getHostHeader() ?? ''));

        $inboundRemark = trim((string) ($inbound->getRemark() ?? $inbound->getTitle() ?? $email));
        if ('' === $inboundRemark) {
            $inboundRemark = 'usr';
        }

        $links = [];
        foreach ($externalProxies as $entry) {
            $address = trim((string) ($entry['dest'] ?? ''));
            $port = is_numeric($entry['port'] ?? null) ? (int) $entry['port'] : null;
            if ('' === $address || null === $port || $port < 1 || $port > 65535) {
                continue;
            }

            $entrySecurity = $security;
            $forceTls = strtolower(trim((string) ($entry['forceTls'] ?? 'same')));
            if ('tls' === $forceTls) {
                $entrySecurity = 'tls';
            } elseif ('none' === $forceTls) {
                $entrySecurity = 'none';
            }

            $remark = trim((string) ($entry['remark'] ?? ''));
            if ('' === $remark) {
                $remark = sprintf('%s-%s', $inboundRemark, $address);
            }

            $query = [
                'type' => 'ws',
                'security' => $entrySecurity,
                'path' => $wsPath,
            ];
            if ('' !== $wsHost) {
                $query['host'] = $wsHost;
            }

            $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $links[] = sprintf(
                'vless://%s@%s:%d?%s#%s',
                rawurlencode($uuid),
                $address,
                $port,
                $queryString,
                rawurlencode($remark)
            );

            error_log(sprintf(
                '[Sanaei3xuiConfigGenerator] generated_link address="%s" port=%d security="%s" sub_id_present=%s',
                $address,
                $port,
                $entrySecurity,
                '' !== trim($subId) ? 'yes' : 'no'
            ));
        }

        error_log(sprintf(
            '[Sanaei3xuiConfigGenerator] external_proxy_count=%d generated_link_count=%d inbound_id=%d',
            count($externalProxies),
            count($links),
            $inbound->getId() ?? 0
        ));

        return implode("\n", $links);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function normalizeConfig(VpnInbound $inbound): array
    {
        $config = is_array($inbound->getConfig()) ? $inbound->getConfig() : [];
        $raw = $this->toArray($config['raw'] ?? null);

        $streamSettings = [];
        $streamCandidates = [
            $config['streamSettings'] ?? null,
            $raw['streamSettings'] ?? null,
            $raw['stream_settings'] ?? null,
        ];

        foreach ($streamCandidates as $candidate) {
            $decoded = $this->toArray($candidate);
            if ([] !== $decoded) {
                $streamSettings = $decoded;
                break;
            }
        }

        $externalProxies = $this->normalizeExternalProxyList($streamSettings['externalProxy'] ?? null);
        if ([] === $externalProxies) {
            $externalProxies = $this->normalizeExternalProxyList($streamSettings['external_proxy'] ?? null);
        }
        if ([] === $externalProxies) {
            $externalProxies = $this->normalizeExternalProxyList($config['externalProxy'] ?? null);
        }
        if ([] === $externalProxies) {
            $externalProxies = $this->normalizeExternalProxyList($config['externalProxyList'] ?? null);
        }

        return [$streamSettings, $externalProxies];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeExternalProxyList(mixed $value): array
    {
        $candidate = $this->toArray($value);
        if ([] === $candidate || !array_is_list($candidate)) {
            return [];
        }

        $result = [];
        foreach ($candidate as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dest = trim((string) ($item['dest'] ?? ''));
            $port = is_numeric($item['port'] ?? null) ? (int) $item['port'] : null;
            if ('' === $dest || null === $port || $port < 1 || $port > 65535) {
                continue;
            }
            $result[] = [
                'dest' => $dest,
                'port' => $port,
                'forceTls' => trim((string) ($item['forceTls'] ?? 'same')),
                'remark' => trim((string) ($item['remark'] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) ? $decoded : [];
    }
}
