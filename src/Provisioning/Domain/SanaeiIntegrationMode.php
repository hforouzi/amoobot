<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

enum SanaeiIntegrationMode: string
{
    case LEGACY = 'legacy';
    case MODERN = 'modern';
}
