<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Auth;

enum ModernPanelConfigurationFailure: string
{
    case INVALID_PANEL_TYPE = 'invalid_panel_type';
    case INVALID_INTEGRATION_MODE = 'invalid_integration_mode';
    case MISSING_BASE_URL = 'missing_base_url';
    case INVALID_BASE_URL = 'invalid_base_url';
    case MISSING_API_TOKEN = 'missing_api_token';
}
