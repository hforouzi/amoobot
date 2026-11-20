<?php

declare(strict_types=1);

namespace App\Module\Domain;

/**
 * What a provider driver declares it can do (docs/design/product-modules.md 2.3, 6.3): an immutable set of capability
 * names such as 'adopt_existing' or 'multi_inbound'. Undeclared means absent: require() throws CapabilityNotSupported.
 * The names are module-specific (VpnCapability lists the VPN ones); this class only keeps a normalised, sorted set.
 */
final class CapabilitySet
{
    private const NAME = '/^[a-z][a-z0-9_]{0,63}\z/';

    /** @var list<string> sorted, unique */
    private readonly array $names;

    /**
     * @param list<string> $names
     */
    private function __construct(array $names)
    {
        foreach ($names as $name) {
            if (1 !== preg_match(self::NAME, $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid capability name "%s".', $name));
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        $this->names = $names;
    }

    public static function of(string ...$names): self
    {
        return new self(array_values($names));
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function has(string $capability): bool
    {
        return in_array($capability, $this->names, true);
    }

    /**
     * @throws CapabilityNotSupported when the capability is not declared
     */
    public function require(string $capability, string $driverCode = ''): void
    {
        if (!$this->has($capability)) {
            throw new CapabilityNotSupported($capability, $driverCode);
        }
    }

    /** @return list<string> sorted */
    public function names(): array
    {
        return $this->names;
    }

    public function equals(self $other): bool
    {
        return $this->names === $other->names;
    }
}
