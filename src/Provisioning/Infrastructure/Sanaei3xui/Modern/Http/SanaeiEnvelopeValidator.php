<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;

final class SanaeiEnvelopeValidator
{
    public function toHttpResult(JsonResponse $response, bool $requireEnvelope = false): HttpResult
    {
        if (204 === $response->statusCode) {
            return new HttpResult(true, null, null, '', $response->statusCode, $response->headers, $response->requestId);
        }

        if (!is_array($response->decoded)) {
            throw new InvalidResponseException('Sanaei Modern response JSON must be an object or array.', $response->statusCode, $response->requestId);
        }

        $hasEnvelope = array_key_exists('success', $response->decoded)
            || array_key_exists('msg', $response->decoded)
            || array_key_exists('obj', $response->decoded);

        if (!$hasEnvelope) {
            if ($requireEnvelope) {
                throw new InvalidResponseException('Sanaei Modern response envelope is missing.', $response->statusCode, $response->requestId);
            }

            return new HttpResult(
                success: $response->statusCode >= 200 && $response->statusCode < 300,
                message: null,
                payload: $response->decoded,
                rawResponse: $response->rawResponse,
                statusCode: $response->statusCode,
                headers: $response->headers,
                requestId: $response->requestId
            );
        }

        $success = $response->decoded['success'] ?? null;
        if (!is_bool($success)) {
            throw new InvalidResponseException('Sanaei Modern response envelope field "success" must be boolean.', $response->statusCode, $response->requestId);
        }

        $message = $response->decoded['msg'] ?? null;
        if (null !== $message && !is_scalar($message)) {
            throw new InvalidResponseException('Sanaei Modern response envelope field "msg" must be scalar or null.', $response->statusCode, $response->requestId);
        }

        return new HttpResult(
            success: $success,
            message: null === $message ? null : (string) $message,
            payload: $response->decoded['obj'] ?? null,
            rawResponse: $response->rawResponse,
            statusCode: $response->statusCode,
            headers: $response->headers,
            requestId: $response->requestId,
            enveloped: true
        );
    }
}
