<?php

declare(strict_types=1);

namespace App\Module\Application;

/**
 * APP_MODULES_ALLOWED (docs/design/product-modules.md 4.7): the ceiling of the product modules an install may enable,
 * comma separated, default "vpn". The enabled state itself is the module's plugin row; a module outside this list
 * cannot be enabled, and its drivers are neither enabled nor loaded. The server install sets "server".
 */
final class ModuleAllowList
{
    /** @var list<string> */
    private readonly array $modules;

    public function __construct(string $modulesAllowed = 'vpn')
    {
        $modules = [];
        foreach (explode(',', $modulesAllowed) as $module) {
            $module = strtolower(trim($module));
            if ('' !== $module) {
                $modules[] = $module;
            }
        }
        $this->modules = array_values(array_unique($modules));
    }

    public function allows(string $module): bool
    {
        return in_array($module, $this->modules, true);
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->modules;
    }
}
