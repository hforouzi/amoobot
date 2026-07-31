<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Auth\ModernPanelConfigurationFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\ApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthenticationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthorizationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidJsonException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\NetworkException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\PanelUnavailableException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\RateLimitException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\TimeoutException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\HttpResult;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernHttpClientInterface;

final class SanaeiModernConnectionTester implements ModernConnectionTesterInterface
{
    public const STATUS_ENDPOINT = '/panel/api/server/status';

    public function __construct(
        private readonly ModernPanelConfigurationFactory $configurationFactory,
        private readonly ModernHttpClientInterface $httpClient,
    ) {
    }

    public function test(VpnPanel $panel): ModernConnectionTestResult
    {
        $configuration = $this->configurationFactory->resolve($panel);
        if (!$configuration->isValid()) {
            return ModernConnectionTestResult::failure(
                ModernConnectionTestStatus::MISSING_CONFIGURATION,
                $configuration->message
            );
        }

        $startedAt = microtime(true);
        try {
            $result = $this->httpClient->request($panel, 'GET', self::STATUS_ENDPOINT);
        } catch (AuthenticationFailedException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::AUTHENTICATION_FAILED, true, false, false, $startedAt);
        } catch (AuthorizationFailedException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::AUTHORIZATION_FAILED, true, true, false, $startedAt);
        } catch (TimeoutException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::TIMEOUT, false, false, false, $startedAt);
        } catch (RateLimitException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::RATE_LIMITED, true, true, false, $startedAt);
        } catch (PanelUnavailableException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::UNAVAILABLE, false, false, false, $startedAt);
        } catch (NetworkException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::UNAVAILABLE, false, false, false, $startedAt);
        } catch (InvalidJsonException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::INVALID_RESPONSE, true, true, false, $startedAt);
        } catch (InvalidResponseException $exception) {
            return $this->exceptionResult($exception, ModernConnectionTestStatus::INVALID_RESPONSE, true, true, false, $startedAt);
        } catch (ApiException $exception) {
            $status = null !== $exception->statusCode && $exception->statusCode >= 500
                ? ModernConnectionTestStatus::UNAVAILABLE
                : ModernConnectionTestStatus::UNKNOWN_ERROR;

            return $this->exceptionResult($exception, $status, true, true, false, $startedAt);
        }

        $durationMs = $this->durationMs($startedAt);
        if (!$result->enveloped) {
            return ModernConnectionTestResult::failure(
                ModernConnectionTestStatus::INCOMPATIBLE_API,
                'Modern Sanaei server status response envelope is missing.',
                true,
                true,
                false,
                $result->requestId,
                $durationMs
            );
        }

        if (!$result->success) {
            return ModernConnectionTestResult::failure(
                ModernConnectionTestStatus::INCOMPATIBLE_API,
                $result->message ?: 'Modern Sanaei server status probe returned success=false.',
                true,
                true,
                true,
                $result->requestId,
                $durationMs
            );
        }

        if (!$this->hasCompatibleServerStatusPayload($result)) {
            return ModernConnectionTestResult::failure(
                ModernConnectionTestStatus::INCOMPATIBLE_API,
                'Modern Sanaei server status payload does not contain expected status fields.',
                true,
                true,
                false,
                $result->requestId,
                $durationMs
            );
        }

        return ModernConnectionTestResult::success(
            'Modern Sanaei API connection succeeded.',
            $result->requestId,
            $durationMs,
            $this->scalarString($this->payloadValue($result->payload, ['version', 'panelVersion'])),
            $this->scalarString($this->payloadValue($result->payload, ['xrayVersion', 'xray.version', 'xray_version'])),
            $this->scalarString($this->payloadValue($result->payload, ['xray.state', 'xrayState', 'xray_state']))
        );
    }

    private function exceptionResult(
        ApiException $exception,
        ModernConnectionTestStatus $status,
        bool $panelReachable,
        bool $authenticated,
        bool $compatibleApi,
        float $startedAt,
    ): ModernConnectionTestResult {
        return ModernConnectionTestResult::failure(
            $status,
            $exception->getMessage(),
            $panelReachable,
            $authenticated,
            $compatibleApi,
            $exception->requestId,
            $this->durationMs($startedAt)
        );
    }

    private function hasCompatibleServerStatusPayload(HttpResult $result): bool
    {
        if (!is_array($result->payload)) {
            return false;
        }

        foreach (['cpu', 'mem', 'xray', 'load'] as $key) {
            if (array_key_exists($key, $result->payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     */
    private function payloadValue(mixed $payload, array $paths): mixed
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach ($paths as $path) {
            $cursor = $payload;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$segment];
            }
            if (null !== $cursor) {
                return $cursor;
            }
        }

        return null;
    }

    private function scalarString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
