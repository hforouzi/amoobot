<?php

declare(strict_types=1);

namespace App\Module\Domain;

/**
 * A product module or provider driver lookup or registration failed: unknown type/code, or the same type/code
 * registered twice.
 */
final class ModuleRegistryException extends \RuntimeException
{
    public const UNKNOWN_MODULE = 'unknown_module';
    public const UNKNOWN_DRIVER = 'unknown_driver';
    public const DUPLICATE_MODULE = 'duplicate_module';
    public const DUPLICATE_DRIVER = 'duplicate_driver';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unknownModule(string $type): self
    {
        return new self(self::UNKNOWN_MODULE, sprintf('No product module of type "%s".', $type));
    }

    public static function unknownDriver(string $module, string $code): self
    {
        return new self(self::UNKNOWN_DRIVER, sprintf('No "%s" driver with code "%s".', $module, $code));
    }

    public static function duplicateModule(string $type): self
    {
        return new self(self::DUPLICATE_MODULE, sprintf('Product module type "%s" is registered twice.', $type));
    }

    public static function duplicateDriver(string $module, string $code): self
    {
        return new self(self::DUPLICATE_DRIVER, sprintf('Driver code "%s" is registered twice for module "%s".', $code, $module));
    }
}
