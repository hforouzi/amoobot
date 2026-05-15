<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;

final readonly class PluginInstallResult
{
    public function __construct(
        public bool $success,
        public ?Plugin $plugin = null,
        public ?string $error = null,
    ) {
    }

    public static function success(Plugin $plugin): self
    {
        return new self(true, $plugin);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
