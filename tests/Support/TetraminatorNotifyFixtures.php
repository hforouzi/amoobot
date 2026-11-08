<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Payment\Domain\PaymentStatus;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\RateLimiter\RateLimiterFactory;

// Not autoloadable: the plugin has its own namespace, and only PluginAutoloader knows its folder.
require_once __DIR__.'/plugins/tetraminator-scripted/src/ScriptedTetraminatorPlugin.php';

/**
 * A Tetraminator gateway served by tests/Support/plugins/tetraminator-scripted (the real plugin, mocked HTTP), for tests
 * that extend IntegrationTestCase. No network is used. $tetraScript holds Tetraminator's inquiry answers and records every
 * inquiry (ScriptedTetraminatorPlugin), across processes.
 */
trait TetraminatorNotifyFixtures
{
    private const TETRA_KEY = 'FAKE-tetraminator-key-0123456789abcdef';
    private const TETRA_PAY_ID = 'TPAY0000000000000001';
    /** 32 bytes, base64url: the shape the plugin generates. */
    private const TETRA_TOKEN = 'Ab3dEf6hIj9lMn0pQr1tUv2xYz4_-5bC7dE8fG9hI0j';
    private const TETRA_AMOUNT = 150000;

    private string $tetraScript = '';

    #[Before]
    protected function createTetraminatorScript(): void
    {
        $this->tetraScript = (string) tempnam(sys_get_temp_dir(), 'tetraminator-script');
        $this->tetraminatorAnswers([]);
    }

    #[After]
    protected function removeTetraminatorFixtures(): void
    {
        @unlink($this->tetraScript);
        // The plugin table is not purged between tests. Kept out of the next test even when this one failed.
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'tetraminator'");
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function tetraminatorAnswers(array $responses): void
    {
        file_put_contents($this->tetraScript, (string) json_encode(['responses' => $responses, 'calls' => []]));
    }

    /** @return array<string, mixed> Tetraminator's documented paid answer */
    private static function tetraPaid(int $amount = self::TETRA_AMOUNT, string $payId = self::TETRA_PAY_ID, int $sleepMs = 0): array
    {
        return ['status' => 200, 'body' => ['status' => true, 'payment_status' => 'paid', 'pay_id' => $payId, 'amount' => $amount], 'sleep_ms' => $sleepMs];
    }

    /** @return array<string, mixed> */
    private static function tetraUnpaid(): array
    {
        return ['status' => 200, 'body' => ['status' => true, 'payment_status' => 'pending', 'pay_id' => self::TETRA_PAY_ID]];
    }

    /** @return list<array{method: string, url: string}> every inquiry made, in any process */
    private function tetraminatorCalls(): array
    {
        return json_decode((string) file_get_contents($this->tetraScript), true, flags: JSON_THROW_ON_ERROR)['calls'];
    }

    private function installTetraminator(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'tetraminator'");
        $path = dirname(__DIR__).'/Support/plugins/tetraminator-scripted';
        $manifest = json_decode((string) file_get_contents($path.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->em->persist((new Plugin())
            ->setCode('tetraminator')
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'Tetraminator'])
            ->setVersion((string) $manifest['version'])
            ->setPath($path)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_ENABLED));
        $this->em->flush();
    }

    private function tetraminatorGateway(): PaymentGateway
    {
        $gateway = $this->createGateway('plugin', ['enabled' => true, 'api_key' => self::TETRA_KEY, 'callback_base_url' => 'https://shop.example', 'test_script' => $this->tetraScript], 'IRT')
            ->setPluginCode('tetraminator');
        $this->em->flush();

        return $gateway;
    }

    /**
     * A payment as createPayment() leaves it: pay_id, the token's hash, the frozen expectation and the invoice expiry.
     */
    private function tetraminatorPayment(?Order $order = null, ?PaymentGateway $gateway = null): Payment
    {
        $order ??= $this->createOrder($this->createUser(), $this->createPlan(self::TETRA_AMOUNT, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway ?? $this->tetraminatorGateway(), PaymentStatus::PENDING)
            ->setGatewayTransactionId(self::TETRA_PAY_ID)
            ->setAuthority(hash('sha256', self::TETRA_TOKEN))
            ->setExpectation((string) $order->getAmount(), 'IRT')
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $this->em->flush();
        $this->resetNotifyLimit($payment);

        return $payment;
    }

    private static function notifyPath(int|string $paymentId, string $token = self::TETRA_TOKEN): string
    {
        return '/payment/notify/tetraminator/'.$paymentId.'/'.$token;
    }

    /** The limiter keeps its state in the cache, across runs: a payment id reused by the purge starts afresh. */
    private function resetNotifyLimit(Payment $payment): void
    {
        /** @var RateLimiterFactory $limiter */
        $limiter = self::getContainer()->get('limiter.payment_notify_payment');
        $limiter->create('payment_'.$payment->getId())->reset();
    }
}
