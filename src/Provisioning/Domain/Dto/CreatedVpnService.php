<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

class CreatedVpnService
{
    public function __construct(
        public readonly string $remoteId,
        public readonly string $username,
        public readonly ?string $subscriptionUrl,
        public readonly ?string $configText,
        public readonly ?string $clientUuid = null,
        public readonly ?string $clientEmail = null,
        public readonly ?string $subId = null,
        public readonly ?int $ipLimit = null,
        public readonly ?array $configLinks = null,
        /**
         * One per additional inbound of the request, attached or not (the primary is not listed: without it there is
         * no service).
         *
         * @var list<InboundAttachment>
         */
        public readonly array $attachments = [],
    ) {
    }
}
