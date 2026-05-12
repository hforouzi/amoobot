<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

final class ArrayPathReader
{
    public function get(array $payload, ?string $path): mixed
    {
        $path = trim((string) $path);
        if ('' === $path) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode('.', $path)), static fn (string $part): bool => '' !== $part));
        if ([] === $parts) {
            return null;
        }

        $current = $payload;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }
}
