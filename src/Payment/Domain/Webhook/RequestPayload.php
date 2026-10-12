<?php

declare(strict_types=1);

namespace App\Payment\Domain\Webhook;

use Symfony\Component\HttpFoundation\Request;

final class RequestPayload
{
    /**
     * Query + form + JSON body of a request, merged (JSON wins).
     *
     * @return array<string, mixed>
     */
    public static function from(Request $request): array
    {
        $payload = array_merge($request->query->all(), $request->request->all());

        $content = trim((string) $request->getContent());
        if ('' !== $content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $payload = array_replace_recursive($payload, $decoded);
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $keys
     */
    public static function firstScalar(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && '' !== trim((string) $value)) {
                return trim((string) $value);
            }
        }

        return null;
    }
}
