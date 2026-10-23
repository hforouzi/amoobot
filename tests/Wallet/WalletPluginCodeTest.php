<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Payment;
use App\Entity\Plugin;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Plugin\PluginManager;
use App\Tests\Support\IntegrationTestCase;

/**
 * "wallet" is the method/gateway type of payments paid from the wallet (no gateway verifies them). A plugin can never
 * take that code: the manifest is refused, and a plugin row with it (installed some other way) is never resolved as
 * the driver that would "verify" wallet payments.
 */
final class WalletPluginCodeTest extends IntegrationTestCase
{
    private const DEMO = 'docs/plugins/demo-payment-gateway';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [Payment::METHOD_WALLET]);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [Payment::METHOD_WALLET]);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function demoManifest(): array
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.self::DEMO.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    public function testAManifestCannotTakeTheReservedCode(): void
    {
        $manager = self::getContainer()->get(PluginManager::class);
        self::assertTrue($manager->validateManifest($this->demoManifest())->valid, 'the demo manifest itself is valid');

        $result = $manager->validateManifest(['code' => Payment::METHOD_WALLET] + $this->demoManifest());

        self::assertFalse($result->valid);
        self::assertContains('code "wallet" is reserved.', $result->errors);
    }

    public function testAPluginRowWithTheReservedCodeIsNeverResolved(): void
    {
        $manifest = $this->demoManifest();
        $plugin = (new Plugin())
            ->setCode(Payment::METHOD_WALLET)
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'Impostor'])
            ->setVersion((string) ($manifest['version'] ?? '1.0.0'))
            ->setPath(dirname(__DIR__, 2).'/'.self::DEMO)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_ENABLED);
        $this->em->persist($plugin);
        $this->em->flush();
        $registry = self::getContainer()->get(PaymentGatewayRegistry::class);

        self::assertFalse($registry->supportsType(Payment::METHOD_WALLET));
        try {
            $registry->resolveByType(Payment::METHOD_WALLET);
            self::fail('a plugin must not become the wallet payments\' verifier');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }
        try {
            $registry->resolve($this->createGateway('plugin')->setPluginCode(Payment::METHOD_WALLET));
            self::fail('not through a gateway row either');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }
    }
}
