<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Mapper;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Dto\ModernInboundDto;

final class ModernInboundMapper
{
    /**
     * @return list<ModernInboundDto>
     */
    public function mapList(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new ModernInboundMappingException('Modern inbound list payload must be an array.');
        }

        $inbounds = [];
        foreach ($payload as $index => $item) {
            if (!is_array($item)) {
                throw new ModernInboundMappingException(sprintf('Modern inbound record at index %s must be an object.', (string) $index));
            }

            /** @var array<string, mixed> $record */
            $record = $item;
            $inbounds[] = $this->mapRecord($record, (int) $index);
        }

        return $inbounds;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function mapRecord(array $record, int $index): ModernInboundDto
    {
        $remoteId = $this->requiredPositiveInt($record['id'] ?? null, sprintf('Modern inbound at index %d has an invalid id.', $index));
        $context = sprintf('remote inbound %d', $remoteId);
        $protocol = trim((string) ($record['protocol'] ?? ''));
        if ('' === $protocol) {
            throw new ModernInboundMappingException(sprintf('Modern %s has an invalid protocol.', $context));
        }

        $port = $this->optionalPort($record['port'] ?? null, $context);

        return new ModernInboundDto(
            remoteId: $remoteId,
            remark: $this->stringValue($record['remark'] ?? ''),
            protocol: $protocol,
            port: $port,
            enabled: $this->boolValue($record['enable'] ?? true),
            expiryTime: $this->intValue($record['expiryTime'] ?? 0),
            listen: $this->stringValue($record['listen'] ?? ''),
            tag: $this->stringValue($record['tag'] ?? ''),
            total: $this->intValue($record['total'] ?? 0),
            up: $this->intValue($record['up'] ?? 0),
            down: $this->intValue($record['down'] ?? 0),
            settings: $this->optionalJsonObject($record['settings'] ?? null, 'settings', $context),
            streamSettings: $this->optionalJsonObject($record['streamSettings'] ?? null, 'streamSettings', $context),
            sniffing: $this->optionalJsonObject($record['sniffing'] ?? null, 'sniffing', $context),
            clientStats: is_array($record['clientStats'] ?? null) ? $record['clientStats'] : [],
            raw: $record,
        );
    }

    private function requiredPositiveInt(mixed $value, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit(trim($value)) && (int) $value > 0) {
            return (int) $value;
        }

        throw new ModernInboundMappingException($message);
    }

    private function optionalPort(mixed $value, string $context): int
    {
        if (null === $value || '' === $value) {
            return 0;
        }

        $port = $this->intValue($value);
        if ($port < 1 || $port > 65535) {
            throw new ModernInboundMappingException(sprintf('Modern %s has an invalid port.', $context));
        }

        return $port;
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) $value;
        }

        return 0;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return 1 === $value;
        }
        if (!is_scalar($value)) {
            return true;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionalJsonObject(mixed $value, string $field, string $context): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }
        if (!is_string($value)) {
            throw new ModernInboundMappingException(sprintf('Modern %s has invalid %s.', $context, $field));
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ModernInboundMappingException(sprintf('Modern %s has malformed %s JSON.', $context, $field));
        }

        if (!is_array($decoded)) {
            throw new ModernInboundMappingException(sprintf('Modern %s has invalid %s.', $context, $field));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
