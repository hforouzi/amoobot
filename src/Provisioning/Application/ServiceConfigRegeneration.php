<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * The result of ServiceConfigRegenerator::regenerate(): what happened, how many links the service now has (and had
 * before, so a shorter list is reported), and the error text on failure (it can come from a panel; callers outside the
 * web panel mask it).
 */
final readonly class ServiceConfigRegeneration
{
    /** The service has no client identity (uuid / sub id / email) to build links from. */
    public const NO_IDENTITY = 'no_identity';
    public const REFRESHED_FROM_PANEL = 'refreshed_from_panel';
    public const REGENERATED = 'regenerated';
    public const FAILED = 'failed';

    public function __construct(
        public string $outcome,
        public int $linkCount = 0,
        public ?string $error = null,
        public ?int $previousLinkCount = null,
    ) {
    }

    public function succeeded(): bool
    {
        return self::REFRESHED_FROM_PANEL === $this->outcome || self::REGENERATED === $this->outcome;
    }
}
