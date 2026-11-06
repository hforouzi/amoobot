<?php

declare(strict_types=1);

namespace Amoobot\Plugin\ConstrainedGateway;

use App\Payment\Plugin\PaymentGatewayConstraintsInterface;

/**
 * Test only: the Tetraminator range, 50,000 to 10,000,000 Toman. Usable unless the gateway config says "usable": false.
 * $throwIn makes a check throw, or the constructor (which is how the plugin fails to load), with the config's API key in
 * the isUsable() message, as a careless plugin would.
 */
final class ConstrainedGatewayPlugin extends UnconstrainedGatewayPlugin implements PaymentGatewayConstraintsInterface
{
    /** 'construct', 'isUsable' or 'acceptsAmount'. */
    public static ?string $throwIn = null;

    public function __construct()
    {
        if ('construct' === self::$throwIn) {
            throw new \RuntimeException('The plugin failed to start.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function isUsable(array $config): bool
    {
        if ('isUsable' === self::$throwIn) {
            throw new \RuntimeException('Refused key '.(is_string($config['api_key'] ?? null) ? $config['api_key'] : ''));
        }

        return false !== ($config['usable'] ?? true);
    }

    public function acceptsAmount(int $toman): bool
    {
        if ('acceptsAmount' === self::$throwIn) {
            throw new \RuntimeException('Refused amount.');
        }

        return $toman >= 50000 && $toman <= 10000000;
    }

    public function invoiceLifetimeSeconds(): int
    {
        return 3600;
    }
}
