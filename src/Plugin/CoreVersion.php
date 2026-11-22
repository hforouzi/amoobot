<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * The application's version, against which a driver package's "requires.core" range is checked
 * (docs/design/product-modules.md 4.3). Bumped with every release tag (vX.Y.Z), in the release commit.
 */
final class CoreVersion
{
    public const CURRENT = '2.4.1';

    private function __construct()
    {
    }
}
