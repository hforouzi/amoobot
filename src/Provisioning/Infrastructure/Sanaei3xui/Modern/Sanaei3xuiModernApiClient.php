<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

final class Sanaei3xuiModernApiClient
{
    public function apiBasePath(): string
    {
        return '/panel/api';
    }
}
