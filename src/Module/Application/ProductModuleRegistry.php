<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;

/**
 * The product modules by product type (docs/design/product-modules.md 2.1), from the 'app.product_module' tag.
 * PM1a: no production class uses it yet (guarded by ModuleRegistryUsageGuardTest).
 */
final class ProductModuleRegistry
{
    /** @var array<string, ProductModuleInterface>|null */
    private ?array $byType = null;

    /**
     * @param iterable<ProductModuleInterface> $modules
     */
    public function __construct(private readonly iterable $modules)
    {
    }

    /**
     * @throws ModuleRegistryException unknown type, or a type registered twice
     */
    public function get(string $type): ProductModuleInterface
    {
        return $this->index()[$type] ?? throw ModuleRegistryException::unknownModule($type);
    }

    public function has(string $type): bool
    {
        return isset($this->index()[$type]);
    }

    /** @return list<string> sorted */
    public function types(): array
    {
        $types = array_map('strval', array_keys($this->index()));
        sort($types);

        return $types;
    }

    /**
     * @return array<string, ProductModuleInterface>
     */
    private function index(): array
    {
        if (null === $this->byType) {
            $byType = [];
            foreach ($this->modules as $module) {
                $type = $module->type();
                if (isset($byType[$type])) {
                    throw ModuleRegistryException::duplicateModule($type);
                }
                $byType[$type] = $module;
            }
            $this->byType = $byType;
        }

        return $this->byType;
    }
}
