<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

/**
 * A client's new connection credentials after CredentialRotationDriverInterface::rotateCredentials(): what the service
 * row must store so its links work again.
 */
final class RotatedCredentials
{
    /**
     * @param list<string> $configLinks
     */
    public function __construct(
        public readonly string $remoteId,
        public readonly string $clientUuid,
        public readonly string $subId,
        public readonly ?string $subscriptionUrl,
        public readonly array $configLinks,
    ) {
    }
}
