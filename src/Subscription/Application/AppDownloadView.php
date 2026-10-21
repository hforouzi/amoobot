<?php

declare(strict_types=1);

namespace App\Subscription\Application;

/**
 * One download on the subscription page (always through /d/{id}, which counts it).
 */
final class AppDownloadView
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $platform,
        public readonly ?string $version,
        public readonly ?string $description,
        public readonly ?int $sizeBytes,
        public readonly ?string $sha256,
        public readonly string $url,
        public readonly bool $isExternal,
        public readonly bool $matchesPlatform,
    ) {
    }
}
