<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\BrandDomainConfig;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * %env(brand_trusted_hosts:...)%: the framework's trusted_hosts. With an active brand only DEFAULT_URI's host and the
 * brand's host; with no brand (or an invalid one) an empty list, which leaves host checking as it is on one domain.
 */
final class BrandTrustedHostsEnvProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $config = BrandDomainConfig::parse($getEnv($name), $getEnv('default::DEFAULT_URI'));
        if (!$config->isActive()) {
            return '';
        }

        return implode(',', array_map(static fn (?string $host): string => '^'.preg_quote((string) $host).'$', [$config->operationalHost, $config->brandHost]));
    }

    public static function getProvidedTypes(): array
    {
        return ['brand_trusted_hosts' => 'string'];
    }
}
