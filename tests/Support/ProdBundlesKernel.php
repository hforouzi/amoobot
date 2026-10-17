<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;

/**
 * The test environment's configuration with the bundles config/bundles.php enables for prod.
 *
 * A bundle registered for dev/test only is then missing here as it is on the server (TwigExtraBundle once was:
 * EasyAdmin's html_classes() was unknown and /admin answered 500 in prod while every test passed). Own cache dir,
 * so it never shares a compiled container with the normal test kernel.
 */
final class ProdBundlesKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (self::prodBundleClasses($this->getProjectDir().'/config/bundles.php') as $class) {
            yield new $class();
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/prod-bundles';
    }

    /**
     * @return list<class-string<\Symfony\Component\HttpKernel\Bundle\BundleInterface>>
     */
    public static function prodBundleClasses(string $bundlesPath): array
    {
        /** @var array<class-string<\Symfony\Component\HttpKernel\Bundle\BundleInterface>, array<string, bool>> $bundles */
        $bundles = require $bundlesPath;

        return array_keys(array_filter($bundles, static fn (array $envs): bool => $envs['prod'] ?? $envs['all'] ?? false));
    }
}
