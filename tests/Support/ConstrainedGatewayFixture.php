<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Amoobot\Plugin\ConstrainedGateway\ConstrainedGatewayPlugin;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\StorePaymentMethod;

/**
 * The fake plugin of tests/Support/plugins/constrained-gateway, registered as installed, with a store method.
 * For an IntegrationTestCase. The caller's tearDown() runs tearDownConstrainedGateway().
 */
trait ConstrainedGatewayFixture
{
    private const PLUGIN_CODE = 'constrained_fixture';
    private const API_KEY = 'fixture-secret-key-7f3a';

    private function installConstrainedPlugin(string $mainClass = ConstrainedGatewayPlugin::class): void
    {
        $path = dirname(__DIR__).'/Support/plugins/constrained-gateway';
        require_once $path.'/src/UnconstrainedGatewayPlugin.php';
        require_once $path.'/src/ConstrainedGatewayPlugin.php';
        ConstrainedGatewayPlugin::$throwIn = null;
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::PLUGIN_CODE]);
        $manifest = ['mainClass' => $mainClass] + json_decode((string) file_get_contents($path.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->em->persist((new Plugin())
            ->setCode(self::PLUGIN_CODE)
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'Constrained'])
            ->setVersion('1.0.0')
            ->setPath($path)
            ->setMainClass($mainClass)
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_ENABLED));
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function constrainedMethod(array $config = [], string $title = 'درگاه محدود', int $sort = 50): StorePaymentMethod
    {
        $gateway = $this->constrainedGateway($config);
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle($title)->setIsActive(true)->setSortOrder($sort);
        $this->em->persist($method);
        $this->em->flush();

        return $method;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function constrainedGateway(array $config): PaymentGateway
    {
        $gateway = $this->createGateway(self::PLUGIN_CODE, $config + ['api_key' => self::API_KEY], 'IRT')->setPluginCode(self::PLUGIN_CODE);
        $this->em->flush();

        return $gateway;
    }

    private function tearDownConstrainedGateway(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::PLUGIN_CODE]);
        if (class_exists(ConstrainedGatewayPlugin::class, false)) {
            ConstrainedGatewayPlugin::$throwIn = null;
        }
    }
}
