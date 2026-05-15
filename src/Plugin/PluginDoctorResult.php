<?php

declare(strict_types=1);

namespace App\Plugin;

final readonly class PluginDoctorResult
{
    /**
     * @param list<string> $requiredConfigKeys
     * @param list<string> $errors
     */
    public function __construct(
        public bool $pluginFound,
        public ?string $status,
        public ?string $type,
        public string $path,
        public string $mainClass,
        public string $namespacePrefix,
        public string $srcDir,
        public string $classFileCandidate,
        public bool $classExists,
        public string $expectedInterface,
        public bool $implementsInterface,
        public bool $configSchemaValid,
        public array $requiredConfigKeys,
        public array $errors,
    ) {
    }

    public function ok(): bool
    {
        return $this->pluginFound
            && $this->classExists
            && $this->implementsInterface
            && $this->configSchemaValid
            && [] === $this->errors;
    }

    public function errorMessage(): string
    {
        return implode(' ', $this->errors);
    }
}
