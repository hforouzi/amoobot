<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\Request;

/**
 * Boots the kernel with two domains: call useBrandDomain() before the kernel boots (env values are read at runtime,
 * so the compiled test container stays the same) and restoreBrandDomain() after it shut down.
 */
trait BrandDomainEnvironment
{
    private const OPERATIONAL_BASE = 'https://cloud.example.net';
    private const BRAND_BASE = 'https://brand.example.org';

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnvironment = [];

    private function useBrandDomain(?string $brand = self::BRAND_BASE, string $defaultUri = self::OPERATIONAL_BASE): void
    {
        foreach (['BRAND_PUBLIC_BASE_URL' => (string) $brand, 'DEFAULT_URI' => $defaultUri] as $name => $value) {
            $this->savedEnvironment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    private function restoreBrandDomain(): void
    {
        foreach ($this->savedEnvironment as $name => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        $this->savedEnvironment = [];
        // Static: the next kernel only replaces it when it has hosts of its own.
        Request::setTrustedHosts([]);
    }
}
