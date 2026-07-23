<?php

declare(strict_types=1);

use Amoobot\Plugin\GreenPay\GreenPayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/var/plugins/greenpay/src/GreenPayGatewayPlugin.php';

final class GreenPayGatewayPluginBehaviorTest
{
    public function run(): void
    {
        $this->testBaseUrlNormalization();
        $this->testAmountRemainsTomanAndRejectsInvalidAmount();
        $this->testPaidVerificationRulesAreConservative();
        $this->testWebhookParsesDocumentedPayloadWithoutMarkingPaid();
        $this->testWebhookRequiresPaymentId();
    }

    private function testBaseUrlNormalization(): void
    {
        $cases = [
            'https://greenpay.ar-panel.net' => 'https://greenpay.ar-panel.net/api/v1',
            'https://greenpay.ar-panel.net/' => 'https://greenpay.ar-panel.net/api/v1',
            'https://greenpay.ar-panel.net/api/v1' => 'https://greenpay.ar-panel.net/api/v1',
            'https://greenpay.ar-panel.net/api/v1/' => 'https://greenpay.ar-panel.net/api/v1',
        ];

        foreach ($cases as $input => $expected) {
            $actual = $this->invoke('baseUrl', [['base_url' => $input]]);
            $this->assertSame($expected, $actual, 'base URL normalized: '.$input);
            $endpoint = $this->invoke('endpoint', [['base_url' => $input], '/invoices']);
            $this->assertSame($expected.'/invoices', $endpoint, 'endpoint does not duplicate api/v1: '.$input);
        }
    }

    private function testAmountRemainsTomanAndRejectsInvalidAmount(): void
    {
        $amount = $this->invoke('paymentAmountToman', [$this->payment(250000), $this->order(250000)]);
        $this->assertSame(250000, $amount, 'amount is sent unchanged as integer Toman');

        $result = $this->plugin()->createPayment($this->payment(0), $this->order(0), $this->config());
        $this->assertFalse($result->success, 'create rejects zero amount before any provider request');
        $this->assertContains('positive integer', (string) $result->message, 'invalid amount message is explicit');
    }

    private function testPaidVerificationRulesAreConservative(): void
    {
        $paid = $this->invoke('isVerifiedPaid', [[
            'success' => true,
            'paid' => true,
            'status' => 'paid',
        ]]);
        $this->assertTrue($paid, 'paid=true and status=paid is paid');

        foreach (['pending', 'expired', 'cancelled', 'failed'] as $status) {
            $paid = $this->invoke('isVerifiedPaid', [[
                'success' => true,
                'paid' => true,
                'status' => $status,
            ]]);
            $this->assertFalse($paid, $status.' is not paid even when paid flag is ambiguous');
        }

        $paid = $this->invoke('isVerifiedPaid', [[
            'success' => false,
            'paid' => true,
            'status' => 'paid',
        ]]);
        $this->assertFalse($paid, 'success=false is never paid');
    }

    private function testWebhookParsesDocumentedPayloadWithoutMarkingPaid(): void
    {
        $payload = [
            'event' => 'payment.success',
            'payment_id' => 'uid-webhook',
            'order_id' => 'ORDER-1',
            'status' => 'confirmed',
            'fee_mode' => 'buyer',
            'amount' => 100000,
            'fee' => 1000,
            'net_amount' => 99000,
        ];

        $request = Request::create('/payment/webhook/plugin/greenpay', 'POST', [], [], [], ['HTTP_X_PAYMENT_SIGNATURE' => 'abc'], json_encode($payload));
        $result = $this->plugin()->handleWebhook($payload, $request, $this->config());

        $this->assertTrue($result?->handled ?? false, 'webhook handles confirmed notification');
        $this->assertSame('notification_received', $result?->message, 'webhook remains notification-only');
        $this->assertFalse(array_key_exists('paid', $result?->payload ?? []), 'webhook does not mark callback as paid');
        $this->assertSame('uid-webhook', $result?->payload['payment_id'] ?? null, 'webhook returns payment_id for generic controller verification');
        $this->assertTrue($result?->payload['greenpay_signature_present'] ?? false, 'webhook records signature presence only');
    }

    private function testWebhookRequiresPaymentId(): void
    {
        $request = Request::create('/payment/webhook/plugin/greenpay', 'POST', [], [], [], [], '{}');
        $result = $this->plugin()->handleWebhook(['status' => 'confirmed'], $request, $this->config());

        $this->assertFalse($result?->handled ?? true, 'webhook rejects missing payment_id');
        $this->assertSame('payment_id_missing', $result?->message, 'webhook reports missing payment_id');
    }

    /**
     * @param list<mixed> $args
     */
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(GreenPayGatewayPlugin::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->plugin(), $args);
    }

    private function plugin(): GreenPayGatewayPlugin
    {
        return new GreenPayGatewayPlugin();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'base_url' => 'https://greenpay.ar-panel.net/api/v1',
            'api_key' => 'secret-key',
            'callback_base_url' => 'https://bot.example.com',
            'fee_mode' => 'buyer',
        ];
    }

    private function order(int $amount): Order
    {
        $plan = (new Plan())
            ->setTitle('Test plan')
            ->setDurationDays(30)
            ->setPrice($amount);

        return (new Order())
            ->setUser(new User())
            ->setPlan($plan)
            ->setAmount($amount);
    }

    private function payment(int $amount): Payment
    {
        $order = $this->order($amount);

        return (new Payment())
            ->setOrder($order)
            ->setAmount($amount)
            ->setPayableAmount($amount);
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException('Assertion failed: '.$message);
        }
    }

    private function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                'Assertion failed: %s. Expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    private function assertContains(string $needle, string $haystack, string $message): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(sprintf('Assertion failed: %s. "%s" does not contain "%s"', $message, $haystack, $needle));
        }
    }
}

(new GreenPayGatewayPluginBehaviorTest())->run();
echo "GreenPayGatewayPluginBehaviorTest passed\n";
