<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Amoobot\Plugin\WinapayScripted\ScriptedWinapayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\Setting;
use App\Payment\Application\Reconcile\WinapayReconcileSettings;
use App\Payment\Domain\PaymentStatus;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

// Not autoloadable: the plugin has its own namespace, and only PluginAutoloader knows its folder.
require_once __DIR__.'/plugins/winapay-scripted/src/ScriptedWinapayPlugin.php';

/**
 * A Winapay gateway served by tests/Support/plugins/winapay-scripted (the real plugin, mocked HTTP), for tests that
 * extend IntegrationTestCase. No network is used. $script holds Winapay's answers: ScriptedWinapayPlugin.
 */
trait WinapayReconcileFixtures
{
    private const MERCHANT = 'MERCHANT-SECRET-0123456789abcdef';
    private const AUTHORITY = 'AUTH0000000000000000000000000001';

    private string $script = '';

    #[Before]
    protected function createWinapayScript(): void
    {
        $this->script = (string) tempnam(sys_get_temp_dir(), 'winapay-script');
        ScriptedWinapayPlugin::$beforeAnswer = null;
        $this->winapayAnswers([]);
    }

    #[After]
    protected function removeWinapayFixtures(): void
    {
        ScriptedWinapayPlugin::$beforeAnswer = null;
        @unlink($this->script);
        // Neither table is purged between tests. Kept out of the next test even when this one failed.
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'payments.%'");
        $connection->executeStatement("DELETE FROM plugin WHERE code = 'winapay'");
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function winapayAnswers(array $responses): void
    {
        file_put_contents($this->script, (string) json_encode(['responses' => $responses, 'calls' => []]));
    }

    /** @return array<string, mixed> Winapay's answer for a payment verified now */
    private static function paid(int $amount = 150000): array
    {
        return ['body' => ['Status' => 100, 'RefID' => 'REF-1', 'Amount' => $amount, 'PaymentTime' => '1700000000', 'MaskCardNumber' => '6037****1234']];
    }

    /** @return array<string, mixed> Winapay's answer for a payment verified earlier */
    private static function alreadyVerified(): array
    {
        return ['body' => ['Status' => 101, 'RefID' => 'REF-1']];
    }

    /** @return list<array{url: string, body: mixed}> */
    private function winapayCalls(): array
    {
        return json_decode((string) file_get_contents($this->script), true, flags: JSON_THROW_ON_ERROR)['calls'];
    }

    private function switchReconcile(?string $value): void
    {
        $this->em->persist((new Setting())->setKeyName(WinapayReconcileSettings::ENABLED)->setValue($value)->setType('boolean'));
        $this->em->flush();
    }

    private function installWinapay(string $status = Plugin::STATUS_ENABLED): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'winapay'");
        $path = dirname(__DIR__).'/Support/plugins/winapay-scripted';
        $manifest = json_decode((string) file_get_contents($path.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->em->persist((new Plugin())
            ->setCode('winapay')
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'Winapay'])
            ->setVersion((string) $manifest['version'])
            ->setPath($path)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus($status));
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function winapayGateway(array $config = [], bool $active = true, string $pluginCode = 'winapay'): PaymentGateway
    {
        $gateway = $this->createGateway('plugin', ['merchant_id' => self::MERCHANT, 'test_script' => $this->script] + $config, 'IRT')
            ->setPluginCode($pluginCode)
            ->setIsActive($active);
        $this->em->flush();

        return $gateway;
    }

    private function winapayOrder(int $amount = 150000): Order
    {
        return $this->createOrder($this->createUser(), $this->createPlan($amount, $this->createTestInbound()));
    }

    private function winapayPayment(?PaymentGateway $gateway = null, string $ago = '-1 hour', ?string $authority = self::AUTHORITY, string $status = PaymentStatus::PENDING): Payment
    {
        $payment = $this->createPayment($this->winapayOrder(), $gateway ?? $this->winapayGateway(), $status)
            ->setAuthority($authority)
            ->setGatewayTransactionId($authority);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE payment SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable($ago))->format('Y-m-d H:i:s'), $payment->getId()]);

        return $payment;
    }
}
