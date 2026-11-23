<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Domain\CapabilitySet;
use App\Provisioning\Domain\VpnDriverInterface;

/** A Fake3xui-backed driver whose declarations can be reduced independently of its implemented methods. */
final class CapabilityLimitedVpnDriver implements
    \App\Provisioning\Domain\VpnDriverInterface,
    \App\Provisioning\Domain\ExistingClientAwareDriverInterface,
    \App\Provisioning\Domain\ServiceActivationDriverInterface,
    \App\Provisioning\Domain\TrafficLimitDriverInterface,
    \App\Provisioning\Domain\BatchUsageDriverInterface,
    \App\Provisioning\Domain\InboundAttachDriverInterface,
    \App\Provisioning\Domain\InboundMoveDriverInterface,
    \App\Provisioning\Domain\InboundDetachDriverInterface,
    \App\Provisioning\Domain\PanelLinkSourceDriverInterface,
    \App\Provisioning\Domain\CredentialRotationDriverInterface,
    \App\Provisioning\Domain\ClientOwnerDriverInterface
{
    /** @var list<string> Every attempted operation, including reads. */
    public array $calls = [];

    public function __construct(private readonly VpnDriverInterface $inner, private readonly CapabilitySet $declared)
    {
    }

    public function code(): string
    {
        return 'capability_fake';
    }

    public function module(): string
    {
        return 'vpn';
    }

    public function contractVersion(): int
    {
        return VpnDriverInterface::VERSION;
    }

    public function capabilities(): CapabilitySet
    {
        return $this->declared;
    }

    public function supports(?\App\Entity\VpnPanel $panel): bool
    {
        return true;
    }

    public function supportsProvisioning(?\App\Entity\VpnPanel $panel): bool
    {
        $this->calls[] = 'supportsProvisioning';
        $inner = $this->inner;
        return $inner->supportsProvisioning($panel);
    }

    public function createService(\App\Provisioning\Domain\Dto\CreateVpnServiceRequest $request, ?\App\Entity\VpnPanel $panel = NULL): \App\Provisioning\Domain\Dto\CreatedVpnService
    {
        $this->calls[] = 'createService';
        $inner = $this->inner;
        return $inner->createService($request, $panel);
    }

    public function suspendService(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'suspendService';
        $inner = $this->inner;
        $inner->suspendService($remoteId, $panel);
    }

    public function renewService(string $remoteId, \App\Provisioning\Domain\Dto\RenewVpnServiceRequest $request, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'renewService';
        $inner = $this->inner;
        $inner->renewService($remoteId, $request, $panel);
    }

    public function deleteService(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'deleteService';
        $inner = $this->inner;
        $inner->deleteService($remoteId, $panel);
    }

    public function resetUsage(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'resetUsage';
        $inner = $this->inner;
        $inner->resetUsage($remoteId, $panel);
    }

    public function getUsage(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): \App\Provisioning\Domain\Dto\VpnUsage
    {
        $this->calls[] = 'getUsage';
        $inner = $this->inner;
        return $inner->getUsage($remoteId, $panel);
    }

    public function findExistingService(\App\Provisioning\Domain\Dto\CreateVpnServiceRequest $request, ?\App\Entity\VpnPanel $panel = NULL): ?\App\Provisioning\Domain\Dto\AdoptedVpnService
    {
        $this->calls[] = 'findExistingService';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\ExistingClientAwareDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->findExistingService($request, $panel);
    }

    public function activateService(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'activateService';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\ServiceActivationDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        $inner->activateService($remoteId, $panel);
    }

    public function addTrafficLimit(string $remoteId, int $trafficLimitGb, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'addTrafficLimit';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\TrafficLimitDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        $inner->addTrafficLimit($remoteId, $trafficLimitGb, $panel);
    }

    public function getUsages(\App\Entity\VpnPanel $panel, array $remoteIds): array
    {
        $this->calls[] = 'getUsages';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\BatchUsageDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->getUsages($panel, $remoteIds);
    }

    public function attachInbounds(string $remoteId, array $inbounds, ?\App\Entity\VpnPanel $panel = NULL, bool $recordHealth = true, ?float $deadline = NULL): array
    {
        $this->calls[] = 'attachInbounds';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\InboundAttachDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->attachInbounds($remoteId, $inbounds, $panel, $recordHealth, $deadline);
    }

    public function clientInboundIds(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL, bool $recordHealth = true): ?array
    {
        $this->calls[] = 'clientInboundIds';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\InboundAttachDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->clientInboundIds($remoteId, $panel, $recordHealth);
    }

    public function attachForMove(string $remoteId, \App\Entity\VpnInbound $target, ?\App\Entity\VpnPanel $panel = NULL, bool $recordHealth = true, ?int $expectedFrom = NULL): \App\Provisioning\Domain\Dto\InboundMoveAttachResult
    {
        $this->calls[] = 'attachForMove';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\InboundMoveDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->attachForMove($remoteId, $target, $panel, $recordHealth, $expectedFrom);
    }

    public function panelHasInbound(\App\Entity\VpnPanel $panel, int $remoteInboundId, bool $recordHealth = true): ?bool
    {
        $this->calls[] = 'panelHasInbound';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\InboundMoveDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->panelHasInbound($panel, $remoteInboundId, $recordHealth);
    }

    public function detachInbounds(string $remoteId, ?\App\Entity\VpnInbound $localPrimary, array $inbounds, ?\App\Entity\VpnPanel $panel = NULL, bool $recordHealth = true): \App\Provisioning\Domain\Dto\InboundDetachResult
    {
        $this->calls[] = 'detachInbounds';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\InboundDetachDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->detachInbounds($remoteId, $localPrimary, $inbounds, $panel, $recordHealth);
    }

    public function panelLinks(string $remoteId, ?string $subId, ?\App\Entity\VpnPanel $panel = NULL, bool $recordHealth = true): \App\Provisioning\Domain\Dto\PanelLinks
    {
        $this->calls[] = 'panelLinks';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\PanelLinkSourceDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->panelLinks($remoteId, $subId, $panel, $recordHealth);
    }

    public function canRotateCredentials(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): bool
    {
        $this->calls[] = 'canRotateCredentials';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\CredentialRotationDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->canRotateCredentials($remoteId, $panel);
    }

    public function rotateCredentials(string $remoteId, string $newUuid, string $newSubId, ?\App\Entity\VpnPanel $panel = NULL): \App\Provisioning\Domain\Dto\RotatedCredentials
    {
        $this->calls[] = 'rotateCredentials';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\CredentialRotationDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->rotateCredentials($remoteId, $newUuid, $newSubId, $panel);
    }

    public function currentCredentials(string $remoteId, ?\App\Entity\VpnPanel $panel = NULL): ?\App\Provisioning\Domain\Dto\RotatedCredentials
    {
        $this->calls[] = 'currentCredentials';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\CredentialRotationDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        return $inner->currentCredentials($remoteId, $panel);
    }

    public function updateClientOwner(string $remoteId, int $telegramId, string $comment, ?\App\Entity\VpnPanel $panel = NULL): void
    {
        $this->calls[] = 'updateClientOwner';
        $inner = $this->inner;
        if (!$inner instanceof \App\Provisioning\Domain\ClientOwnerDriverInterface) {
            throw new \LogicException('The backing driver does not implement the operation.');
        }

        $inner->updateClientOwner($remoteId, $telegramId, $comment, $panel);
    }
}
