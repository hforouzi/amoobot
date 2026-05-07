<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

final class Sanaei3xuiRemoteIdParser
{
    public function format(int $inboundId, string $clientId, string $email): string
    {
        return sprintf('%d|%s|%s', $inboundId, $clientId, $email);
    }

    public function parse(string $remoteId): ?Sanaei3xuiRemoteClientRef
    {
        $trimmed = trim($remoteId);
        if ('' === $trimmed) {
            return null;
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

    private function toRef(string $inboundId, string $clientId, string $email): ?Sanaei3xuiRemoteClientRef
    {
        $parsedInboundId = (int) $inboundId;
        $parsedClientId = trim($clientId);
        $parsedEmail = trim($email);

        if ($parsedInboundId <= 0 || '' === $parsedClientId || '' === $parsedEmail) {
            return null;
        }

        return new Sanaei3xuiRemoteClientRef($parsedInboundId, $parsedClientId, $parsedEmail);
    }
}
