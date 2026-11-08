<?php

declare(strict_types=1);

namespace Amoobot\Plugin\HooshPayScripted;

use Amoobot\Plugin\HooshPay\HooshPayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;
use App\Payment\Plugin\PaymentGatewayInvoiceCancellationInterface;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

foreach (['HooshPayException', 'HooshPayApi', 'HooshPayGatewayPlugin'] as $class) {
    require_once dirname(__DIR__, 5).'/docs/plugins/hooshpay-gateway/src/'.$class.'.php';
}

/**
 * Test only: the real HooshPayGatewayPlugin behind a mocked HTTP layer, installed like a real plugin (the same idea as
 * ScriptedTetraminatorPlugin). The answers come from the JSON file named by the gateway's config key "test_script":
 * {"responses": [...], "calls": [...]}. Every API call (create, verify, status, cancel) takes the next response and is
 * recorded in "calls" with its method, URL and JSON body. A response is {"status": 200, "body": {...}}, or
 * {"transport": "message"} for a network error; an empty script answers with a network error.
 */
final class ScriptedHooshPayPlugin implements PaymentGatewayPluginInterface, PaymentGatewayConstraintsInterface, PaymentGatewayInvoiceCancellationInterface
{
    private ?HooshPayGatewayPlugin $real = null;

    public function getType(): string
    {
        return HooshPayGatewayPlugin::CODE;
    }

    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        return $this->real($config)->createPayment($payment, $order, $config);
    }

    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        return $this->real($config)->verifyPayment($payment, $payload, $config);
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        return $this->real($config)->handleWebhook($payload, $request, $config);
    }

    public function cancelInvoice(Payment $payment, array $config): string
    {
        return $this->real($config)->cancelInvoice($payment, $config);
    }

    public function isUsable(array $config): bool
    {
        return $this->real($config)->isUsable($config);
    }

    public function acceptsAmount(int $toman): bool
    {
        return $this->real([])->acceptsAmount($toman);
    }

    public function invoiceLifetimeSeconds(): int
    {
        return HooshPayGatewayPlugin::DEFAULT_LIFETIME_SECONDS;
    }

    /**
     * One real plugin per instance (isUsable() then acceptsAmount() share it, as in production).
     *
     * @param array<string, mixed> $config
     */
    private function real(array $config): HooshPayGatewayPlugin
    {
        if (null !== $this->real) {
            return $this->real;
        }
        $script = (string) ($config['test_script'] ?? '');
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($script): ResponseInterface {
            if ('' === $script) {
                throw new \LogicException('The gateway has no test_script.');
            }
            $response = self::next($script, ['method' => $method, 'url' => $url, 'body' => json_decode((string) ($options['body'] ?? 'null'), true)]);
            if (isset($response['transport'])) {
                throw new TransportException((string) $response['transport']);
            }

            return new MockResponse((string) json_encode($response['body'] ?? [], JSON_UNESCAPED_UNICODE), ['http_code' => (int) ($response['status'] ?? 200), 'response_headers' => ['content-type' => 'application/json']]);
        });

        return $this->real = new HooshPayGatewayPlugin($client);
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    private static function next(string $script, array $call): array
    {
        $handle = fopen($script, 'r+') ?: throw new \RuntimeException('Cannot open the script.');
        flock($handle, LOCK_EX);
        try {
            $state = json_decode((string) stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
            $state['calls'][] = $call;
            $response = array_shift($state['responses']);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $response ?? ['transport' => 'The script has no answer left.'];
    }
}
