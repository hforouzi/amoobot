<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Sync;

enum ModernInboundSyncStatus: string
{
    case SUCCESS = 'success';
    case MISSING_CONFIGURATION = 'missing_configuration';
    case AUTHENTICATION_FAILED = 'authentication_failed';
    case AUTHORIZATION_FAILED = 'authorization_failed';
    case TIMEOUT = 'timeout';
    case UNAVAILABLE = 'unavailable';
    case RATE_LIMITED = 'rate_limited';
    case INCOMPATIBLE_RESPONSE = 'incompatible_response';
    case MALFORMED_INBOUND_PAYLOAD = 'malformed_inbound_payload';
    case PERSISTENCE_FAILURE = 'persistence_failure';
}
