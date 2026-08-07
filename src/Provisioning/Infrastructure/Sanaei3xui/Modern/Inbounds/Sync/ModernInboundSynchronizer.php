<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Sync;

use App\Entity\VpnInbound;
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
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Api\ModernInboundApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Dto\ModernInboundDto;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Mapper\ModernInboundMapper;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Mapper\ModernInboundMappingException;
use Doctrine\ORM\EntityManagerInterface;

final class ModernInboundSynchronizer
{
    public function __construct(
        private readonly ModernPanelConfigurationFactory $configurationFactory,
        private readonly ModernInboundApi $api,
        private readonly ModernInboundMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function sync(VpnPanel $panel): ModernInboundSyncResult
    {
        $configuration = $this->configurationFactory->resolve($panel);
        if (!$configuration->isValid()) {
            return ModernInboundSyncResult::failure(ModernInboundSyncStatus::MISSING_CONFIGURATION, $configuration->message);
        }

        $startedAt = microtime(true);
        try {
            $response = $this->api->list($panel);
            $remoteInbounds = $this->mapper->mapList($response->payload);
        } catch (AuthenticationFailedException $exception) {
            return $this->exceptionResult($exception, ModernInboundSyncStatus::AUTHENTICATION_FAILED, $startedAt);
        } catch (AuthorizationFailedException $exception) {
            return $this->exceptionResult($exception, ModernInboundSyncStatus::AUTHORIZATION_FAILED, $startedAt);
        } catch (TimeoutException $exception) {
            return $this->exceptionResult($exception, ModernInboundSyncStatus::TIMEOUT, $startedAt);
        } catch (RateLimitException $exception) {
            return $this->exceptionResult($exception, ModernInboundSyncStatus::RATE_LIMITED, $startedAt);
        } catch (PanelUnavailableException|NetworkException $exception) {
            return $this->exceptionResult($exception, ModernInboundSyncStatus::UNAVAILABLE, $startedAt);
        } catch (InvalidJsonException|InvalidResponseException $exception) {
            return $this->exceptionResult($exception, ModernInboundSyncStatus::INCOMPATIBLE_RESPONSE, $startedAt);
        } catch (ModernInboundMappingException $exception) {
            return ModernInboundSyncResult::failure(ModernInboundSyncStatus::MALFORMED_INBOUND_PAYLOAD, $exception->getMessage(), null, $this->durationMs($startedAt));
        } catch (ApiException $exception) {
            $status = null !== $exception->statusCode && $exception->statusCode >= 500
                ? ModernInboundSyncStatus::UNAVAILABLE
                : ModernInboundSyncStatus::INCOMPATIBLE_RESPONSE;

            return $this->exceptionResult($exception, $status, $startedAt);
        }

        $requestId = $response->requestId;
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $counts = $this->applyRemoteInbounds($panel, $remoteInbounds);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            return ModernInboundSyncResult::failure(
                ModernInboundSyncStatus::PERSISTENCE_FAILURE,
                'Modern inbound synchronization could not be persisted.',
                $requestId,
                $this->durationMs($startedAt)
            );
        }

        return new ModernInboundSyncResult(
            success: true,
            status: ModernInboundSyncStatus::SUCCESS,
            message: sprintf(
                'Modern inbound synchronization completed: %d fetched, %d created, %d updated, %d unchanged.',
                count($remoteInbounds),
                $counts['created'],
                $counts['updated'],
                $counts['unchanged']
            ),
            fetchedCount: count($remoteInbounds),
            createdCount: $counts['created'],
            updatedCount: $counts['updated'],
            unchangedCount: $counts['unchanged'],
            missingCount: $counts['missing'],
            deactivatedCount: $counts['deactivated'],
            requestId: $requestId,
            durationMs: $this->durationMs($startedAt),
        );
    }

    /**
     * @param list<ModernInboundDto> $remoteInbounds
     *
     * @return array{created:int, updated:int, unchanged:int, missing:int, deactivated:int}
     */
    private function applyRemoteInbounds(VpnPanel $panel, array $remoteInbounds): array
    {
        /** @var list<VpnInbound> $localInbounds */
        $localInbounds = $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel]);
        $localByRemoteId = [];
        foreach ($localInbounds as $localInbound) {
            $localByRemoteId[$localInbound->getRemoteInboundId()] = $localInbound;
        }

        $now = new \DateTimeImmutable();
        $seenRemoteIds = [];
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($remoteInbounds as $remoteInbound) {
            $remoteId = (string) $remoteInbound->remoteId;
            $seenRemoteIds[$remoteId] = true;
            $localInbound = $localByRemoteId[$remoteId] ?? null;
            $isNew = false;

            if (!$localInbound instanceof VpnInbound) {
                $localInbound = (new VpnInbound())
                    ->setPanel($panel)
                    ->setRemoteInboundId($remoteId)
                    ->setTitle($this->titleFor($remoteInbound));
                $this->entityManager->persist($localInbound);
                $isNew = true;
            }

            $changed = $this->applyRemoteInbound($localInbound, $remoteInbound, $isNew);
            if ($isNew) {
                $localInbound->setLastSyncedAt($now)->setUpdatedAt($now);
                ++$created;
            } elseif ($changed) {
                $localInbound->setLastSyncedAt($now)->setUpdatedAt($now);
                ++$updated;
            } else {
                ++$unchanged;
            }
        }

        $missing = 0;
        $deactivated = 0;
        foreach ($localInbounds as $localInbound) {
            if (isset($seenRemoteIds[$localInbound->getRemoteInboundId()])) {
                continue;
            }

            ++$missing;
            if ($localInbound->isActive()) {
                $localInbound->setIsActive(false)->setUpdatedAt($now);
                ++$deactivated;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'missing' => $missing,
            'deactivated' => $deactivated,
        ];
    }

    private function applyRemoteInbound(VpnInbound $localInbound, ModernInboundDto $remoteInbound, bool $isNew): bool
    {
        $changed = false;
        if ('' === trim($localInbound->getTitle())) {
            $localInbound->setTitle($this->titleFor($remoteInbound));
            $changed = true;
        }

        $changed = $this->setIfDifferent($localInbound, 'getRemark', 'setRemark', '' === $remoteInbound->remark ? null : $remoteInbound->remark) || $changed;
        $changed = $this->setIfDifferent($localInbound, 'getProtocol', 'setProtocol', $remoteInbound->protocol) || $changed;
        $changed = $this->setIfDifferent($localInbound, 'getPort', 'setPort', $remoteInbound->port > 0 ? $remoteInbound->port : null) || $changed;
        $changed = $this->setIfDifferent($localInbound, 'isActive', 'setIsActive', $remoteInbound->enabled) || $changed;

        $config = $this->configFor($remoteInbound);
        if ($localInbound->getConfig() !== $config) {
            $localInbound->setConfig($config);
            $changed = true;
        }

        return $isNew || $changed;
    }

    private function setIfDifferent(VpnInbound $localInbound, string $getter, string $setter, mixed $value): bool
    {
        if ($localInbound->{$getter}() === $value) {
            return false;
        }

        $localInbound->{$setter}($value);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(ModernInboundDto $remoteInbound): array
    {
        return [
            'source' => 'sanaei_modern_inbound_sync',
            'remote' => [
                'id' => $remoteInbound->remoteId,
                'tag' => $remoteInbound->tag,
                'listen' => $remoteInbound->listen,
                'expiryTime' => $remoteInbound->expiryTime,
                'total' => $remoteInbound->total,
                'up' => $remoteInbound->up,
                'down' => $remoteInbound->down,
                'clientStatsCount' => count($remoteInbound->clientStats),
            ],
            'settings' => $this->withoutClients($this->redactSensitive($remoteInbound->settings)),
            'streamSettings' => $this->redactSensitive($remoteInbound->streamSettings),
            'sniffing' => $this->redactSensitive($remoteInbound->sniffing),
        ];
    }

    /**
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    private function withoutClients(?array $value): ?array
    {
        if (null === $value) {
            return null;
        }

        unset($value['clients']);

        return $value;
    }

    /**
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    private function redactSensitive(?array $value): ?array
    {
        if (null === $value) {
            return null;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/token|password|secret|authorization|cookie|session/i', $key)) {
                $value[$key] = '[redacted]';
                continue;
            }

            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $value[$key] = $this->redactSensitive($item);
            }
        }

        return $value;
    }

    private function titleFor(ModernInboundDto $remoteInbound): string
    {
        return '' !== $remoteInbound->remark ? $remoteInbound->remark : sprintf('Inbound %d', $remoteInbound->remoteId);
    }

    private function exceptionResult(ApiException $exception, ModernInboundSyncStatus $status, float $startedAt): ModernInboundSyncResult
    {
        return ModernInboundSyncResult::failure(
            $status,
            $exception->getMessage(),
            $exception->requestId,
            $this->durationMs($startedAt)
        );
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
