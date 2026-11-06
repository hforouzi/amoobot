<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\ConstrainedGatewayPlugin;
use Amoobot\Plugin\ConstrainedGateway\UnconstrainedGatewayPlugin;
use App\Entity\Plugin;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The adapter hands a plugin's constraints to the core. A plugin without them answers as every plugin did before:
 * usable, any amount, no invoice lifetime.
 */
final class PluginAdapterConstraintsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $src = dirname(__DIR__, 2).'/Support/plugins/constrained-gateway/src';
        require_once $src.'/UnconstrainedGatewayPlugin.php';
        require_once $src.'/ConstrainedGatewayPlugin.php';
    }

    protected function setUp(): void
    {
        ConstrainedGatewayPlugin::$throwIn = null;
    }

    private static function adapter(UnconstrainedGatewayPlugin $plugin, bool $testingShortcuts = false): PluginPaymentGatewayDriverAdapter
    {
        return new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('constrained_fixture'), $plugin, $testingShortcuts);
    }

    public function testAPluginWithoutTheInterfaceAnswersAsBefore(): void
    {
        $adapter = self::adapter(new UnconstrainedGatewayPlugin());

        self::assertTrue($adapter->isUsable([]));
        self::assertTrue($adapter->isUsable(['usable' => false]));
        self::assertTrue($adapter->acceptsAmount(0));
        self::assertTrue($adapter->acceptsAmount(PHP_INT_MAX));
        self::assertNull($adapter->invoiceLifetimeSeconds());
    }

    public function testAPluginWithTheInterfaceAnswersForItself(): void
    {
        $adapter = self::adapter(new ConstrainedGatewayPlugin());

        self::assertTrue($adapter->isUsable([]));
        self::assertFalse($adapter->isUsable(['usable' => false]));
        self::assertTrue($adapter->acceptsAmount(50000));
        self::assertFalse($adapter->acceptsAmount(49999));
        self::assertSame(3600, $adapter->invoiceLifetimeSeconds());
    }

    public function testThePluginSeesTheRuntimeConfigOfEveryOtherCall(): void
    {
        $seen = new class implements PaymentGatewayConstraintsInterface {
            /** @var array<string, mixed> */
            public array $config = [];

            public function isUsable(array $config): bool
            {
                $this->config = $config;

                return true;
            }

            public function acceptsAmount(int $toman): bool
            {
                return true;
            }

            public function invoiceLifetimeSeconds(): ?int
            {
                return null;
            }
        };
        $plugin = new class($seen) extends UnconstrainedGatewayPlugin implements PaymentGatewayConstraintsInterface {
            public function __construct(private readonly PaymentGatewayConstraintsInterface $seen)
            {
            }

            public function isUsable(array $config): bool
            {
                return $this->seen->isUsable($config);
            }

            public function acceptsAmount(int $toman): bool
            {
                return $this->seen->acceptsAmount($toman);
            }

            public function invoiceLifetimeSeconds(): ?int
            {
                return $this->seen->invoiceLifetimeSeconds();
            }
        };

        self::adapter($plugin)->isUsable(['api_key' => 'k', 'auto_confirm_for_testing' => true]);

        self::assertSame('k', $seen->config['api_key']);
        self::assertArrayNotHasKey('auto_confirm_for_testing', $seen->config, 'removed in production, as for createPayment()');
        self::assertFalse($seen->config[PluginPaymentGatewayDriverAdapter::CONFIG_TESTING_SHORTCUTS]);
    }

    public function testAThrowingPluginIsNotHiddenByTheAdapter(): void
    {
        ConstrainedGatewayPlugin::$throwIn = 'acceptsAmount';

        $this->expectException(\RuntimeException::class);
        self::adapter(new ConstrainedGatewayPlugin())->acceptsAmount(50000);
    }
}
