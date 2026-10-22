<?php

declare(strict_types=1);

namespace App\Subscription\Application;

/**
 * An uploaded installer after it was checked and moved into the downloads directory (AppDownloadStorage::store).
 */
final class StoredFile
{
    public function __construct(
        public readonly string $storedName,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }
}
