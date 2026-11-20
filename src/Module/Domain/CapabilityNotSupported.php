<?php

declare(strict_types=1);

namespace App\Module\Domain;

/**
 * A capability's operation was asked of a driver that does not declare it (docs/design/product-modules.md 6.3,
 * "Undeclared means absent"). A programming error in the caller, not a remote failure.
 */
final class CapabilityNotSupported extends \LogicException
{
    public function __construct(public readonly string $capability, public readonly string $driverCode = '')
    {
        parent::__construct('' === $driverCode
            ? sprintf('Capability "%s" is not supported.', $capability)
            : sprintf('Driver "%s" does not declare capability "%s".', $driverCode, $capability));
    }
}
