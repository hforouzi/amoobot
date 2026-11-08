<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Amoobot\Plugin\HooshPay\HooshPayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\GatewayPayableAmount;
use App\Payment\Domain\PaymentStatus;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

// Not autoloadable: the plugin has its own namespace, and only PluginAutoloader knows its folder.
require_once __DIR__.'/plugins/hooshpay-scripted/src/ScriptedHooshPayPlugin.php';

/**
 * A HooshPay gateway served by tests/Support/plugins/hooshpay-scripted (the real plugin, mocked HTTP), for tests that
 * extend IntegrationTestCase. No network is used. $hooshScript holds HooshPay's answers and records every API call.
 */
trait HooshPayFixtures
{
    private const HOOSH_KEY = 'hp_live_FAKE-hooshpay-key-0123456789';
    private const HOOSH_SECRET = 'FAKE-hooshpay-secret-abcdef0123456789';
    private const HOOSH_UID = 'inv_TEST000000001';
    private const HOOSH_AMOUNT = 150000;

    private string $hooshScript = '';

    #[Before]
    protected function createHooshPayScript(): void
    {
        $this->hooshScript = (string) tempnam(sys_get_temp_dir(), 'hooshpay-script');
        $this->hooshPayAnswers([]);
    }

    #[After]
    protected function removeHooshPayFixtures(): void
    {
        @unlink($this->hooshScript);
        // The plugin table is not purged between tests. Kept out of the next test even when this one failed.
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'hooshpay'");
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function hooshPayAnswers(array $responses): void
    {
        file_put_contents($this->hooshScript, (string) json_encode(['responses' => $responses, 'calls' => []]));
    }

    /** @return list<array{method: string, url: string, body: mixed}> every API call made */
    private function hooshPayCalls(): array
    {
        return json_decode((string) file_get_contents($this->hooshScript), true, flags: JSON_THROW_ON_ERROR)['calls'];
    }

    /**
     * HooshPay's verify answer for a paid invoice of this payment.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function hooshVerified(Payment $payment, array $data = [], bool $paid = true): array
    {
        return ['status' => 200, 'body' => ['success' => true, 'paid' => $paid, 'status' => $paid ? 'paid' : 'pending', 'data' => array_replace([
            'uid' => $payment->getGatewayTransactionId(),
            'tracking_code' => '556677',
            'order_id' => $payment->getRequestPayload()['order_id'] ?? null,
            'amount' => (int) $payment->getExpectedAmount(),
            'payable_amount' => (int) $payment->getExpectedAmount() + 17,
            'card' => ['card_number' => '6037991234567890', 'holder_name' => 'Card Holder'],
        ], $data)]];
    }

    /**
     * HooshPay's create answer.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function hooshCreated(string $uid, int $amount, int $payable, array $data = []): array
    {
        return ['status' => 201, 'body' => ['success' => true, 'data' => array_replace([
            'uid' => $uid, 'amount' => $amount, 'fee_mode' => 'seller', 'fee_percent' => 20, 'fee_amount' => (int) ($amount / 5),
            'payable_amount' => $payable, 'merchant_credit' => $amount, 'status' => 'pending',
            'payment_url' => 'https://hooshpay.xyz/pay/'.$uid,
            'expires_at' => (new \DateTimeImmutable('+30 minutes', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d\TH:i:s'),
            'card' => ['card_number' => '6037991234567890', 'holder_name' => 'Card Holder', 'bank_name' => 'Mellat'],
        ], $data)]];
    }

    private function installHooshPay(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'hooshpay'");
        $path = dirname(__DIR__).'/Support/plugins/hooshpay-scripted';
        $manifest = json_decode((string) file_get_contents($path.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->em->persist((new Plugin())
            ->setCode('hooshpay')
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'HooshPay'])
            ->setVersion((string) $manifest['version'])
            ->setPath($path)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_ENABLED));
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function hooshPayGateway(array $overrides = []): PaymentGateway
    {
        $gateway = $this->createGateway('plugin', array_replace([
            'enabled' => true,
            'api_key' => self::HOOSH_KEY,
            'api_secret' => self::HOOSH_SECRET,
            'base_url' => 'https://hooshpay.xyz',
            'callback_base_url' => 'https://shop.example',
            'fee_mode' => 'seller',
            'min_amount' => 1000,
            'test_script' => $this->hooshScript,
        ], $overrides), 'IRT')->setPluginCode(HooshPayGatewayPlugin::CODE);
        $this->em->flush();

        return $gateway;
    }

    private function hooshPayMethod(PaymentGateway $gateway): StorePaymentMethod
    {
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle('هوش‌پی')->setIsActive(true)->setSortOrder(10);
        $this->em->persist($method);
        $this->em->flush();

        return $method;
    }

    /**
     * A payment as createPayment() leaves it: uid, link, the frozen expectation, the sent order_id and the invoice expiry.
     */
    private function hooshPayPayment(?Order $order = null, ?PaymentGateway $gateway = null, int $payable = self::HOOSH_AMOUNT + 17): Payment
    {
        $order ??= $this->createOrder($this->createUser(), $this->createPlan(self::HOOSH_AMOUNT, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway ?? $this->hooshPayGateway(), PaymentStatus::PENDING);
        $payment
            ->setGatewayTransactionId(self::HOOSH_UID)
            ->setPaymentUrl('https://hooshpay.xyz/pay/'.self::HOOSH_UID)
            ->setExpectation((string) $order->getAmount(), 'IRT')
            ->setRequestPayload(['success' => true, 'uid' => self::HOOSH_UID, 'order_id' => 'ORD-P'.$payment->getId(), 'amount' => $order->getAmount(), GatewayPayableAmount::KEY => $payable])
            ->setExpiresAt(new \DateTimeImmutable('+30 minutes'));
        $this->em->flush();

        return $payment;
    }

    /**
     * HooshPay's webhook body for the invoice.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function hooshWebhookBody(array $overrides = []): array
    {
        return array_replace([
            'event' => 'payment.success', 'invoice' => self::HOOSH_UID, 'order_id' => 'ORD', 'status' => 'paid',
            'amount' => self::HOOSH_AMOUNT, 'payable_amount' => self::HOOSH_AMOUNT + 17, 'merchant_credit' => self::HOOSH_AMOUNT,
            'fee_amount' => 30000, 'fee_mode' => 'seller', 'tracking_code' => '556677', 'paid_at' => '2026-06-16T12:05:00',
        ], $overrides);
    }
}
