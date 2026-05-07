<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

final class Sanaei3xuiRemoteIdParser
{
    public function format(?int $panelId, ?int $localInboundId, string $remoteInboundId, string $clientId, string $email, ?string $subId = null): string
    {
        $base = sprintf(
            'sanaei_3xui|panel=%d|inbound=%d|remoteInbound=%s|uuid=%s|email=%s',
            $panelId ?? 0,
            $localInboundId ?? 0,
            rawurlencode(trim($remoteInboundId)),
            rawurlencode(trim($clientId)),
            rawurlencode(trim($email))
        );

        if (null === $subId || '' === trim($subId)) {
            return $base;
        }

        return sprintf('%s|subId=%s', $base, rawurlencode(trim($subId)));
    }

    public function parse(string $remoteId): ?Sanaei3xuiRemoteClientRef
    {
        $trimmed = trim($remoteId);
        if ('' === $trimmed) {
            return null;
        }

        if (str_starts_with($trimmed, 'sanaei_3xui|')) {
            return $this->parseStructuredRemoteId($trimmed);
        }

        if (str_starts_with($trimmed, 'sanaei_3xui:')) {
            $parts = explode(':', $trimmed, 4);
            if (4 !== count($parts)) {
                return null;
            }

            [, $inboundId, $clientId, $email] = $parts;

            return $this->toRef($inboundId, $clientId, $email);
        }

        $parts = explode('|', $trimmed, 3);
        if (3 !== count($parts)) {
            return null;
        }

        [$inboundId, $clientId, $email] = $parts;

        return $this->toRef($inboundId, $clientId, $email);
    }

    private function toRef(string $inboundId, string $clientId, string $email, ?int $panelId = null, ?int $localInboundId = null, ?string $subId = null): ?Sanaei3xuiRemoteClientRef
    {
        $parsedInboundId = trim($inboundId);
        $parsedClientId = trim($clientId);
        $parsedEmail = trim($email);

        if ('' === $parsedInboundId || '' === $parsedClientId || '' === $parsedEmail) {
            return null;
        }

        return new Sanaei3xuiRemoteClientRef($parsedInboundId, $parsedClientId, $parsedEmail, $panelId, $localInboundId, $subId);
    }

    private function parseStructuredRemoteId(string $remoteId): ?Sanaei3xuiRemoteClientRef
    {
        $parts = explode('|', $remoteId);
        if (count($parts) < 6) {
            return null;
        }

        $fields = [];
        foreach (array_slice($parts, 1) as $item) {
            if (!str_contains($item, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $item, 2);
            $fields[trim($key)] = rawurldecode(trim($value));
        }

        $remoteInbound = (string) ($fields['remoteInbound'] ?? '');
        $uuid = (string) ($fields['uuid'] ?? '');
        $email = (string) ($fields['email'] ?? '');

        if ('' === trim($remoteInbound) || '' === trim($uuid) || '' === trim($email)) {
            return null;
        }

        $panelId = isset($fields['panel']) ? (int) $fields['panel'] : null;
        $localInboundId = isset($fields['inbound']) ? (int) $fields['inbound'] : null;

        $subId = isset($fields['subId']) ? trim((string) $fields['subId']) : null;

        return $this->toRef($remoteInbound, $uuid, $email, $panelId, $localInboundId, $subId);
    }
}
