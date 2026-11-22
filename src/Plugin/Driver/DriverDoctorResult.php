<?php

declare(strict_types=1);

namespace App\Plugin\Driver;

use App\Module\Domain\ProviderDriverInterface;
use App\Plugin\PluginDoctorResult;

/**
 * What DriverPluginDoctor found. $driver is the instance, built only when every check before loading code passed
 * (manifest, core version, contract version, file hashes); ok() also needs every check after it.
 */
final readonly class DriverDoctorResult
{
    /**
     * @param list<string> $manifestCapabilities
     * @param list<string> $capabilities         what the loaded driver declares
     * @param list<string> $errors
     */
    public function __construct(
        public bool $pluginFound,
        public ?string $status,
        public ?string $type,
        public ?string $module,
        public string $path,
        public string $mainClass,
        public string $srcDir,
        public string $coreRange,
        public string $coreVersion,
        public bool $coreOk,
        public ?int $contractRequired,
        public ?int $contractCore,
        public bool $contractOk,
        public bool $integrityOk,
        public bool $classExists,
        public string $expectedInterface,
        public bool $implementsInterface,
        public array $manifestCapabilities,
        public array $capabilities,
        public array $errors,
        public ?ProviderDriverInterface $driver,
    ) {
    }

    public function ok(): bool
    {
        return $this->pluginFound && null !== $this->driver && [] === $this->errors;
    }

    public function errorMessage(): string
    {
        return implode(' ', $this->errors);
    }

    /** The shape the admin plugin page reads for every plugin type. */
    public function toPluginDoctorResult(): PluginDoctorResult
    {
        return new PluginDoctorResult(
            $this->pluginFound,
            $this->status,
            $this->type,
            $this->path,
            $this->mainClass,
            '',
            $this->srcDir,
            '',
            $this->classExists,
            $this->expectedInterface,
            $this->implementsInterface,
            true,
            [],
            $this->errors,
        );
    }
}
