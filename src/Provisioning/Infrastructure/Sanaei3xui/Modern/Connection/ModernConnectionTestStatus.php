<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection;

enum ModernConnectionTestStatus: string
{
    case SUCCESS = 'success';
    case MISSING_CONFIGURATION = 'missing_configuration';
    case AUTHENTICATION_FAILED = 'authentication_failed';
    case AUTHORIZATION_FAILED = 'authorization_failed';
    case UNAVAILABLE = 'unavailable';
    case TIMEOUT = 'timeout';
    case RATE_LIMITED = 'rate_limited';
    case INVALID_RESPONSE = 'invalid_response';
    case INCOMPATIBLE_API = 'incompatible_api';
    case UNKNOWN_ERROR = 'unknown_error';
}
