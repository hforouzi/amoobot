<?php

declare(strict_types=1);

namespace Amoobot\Plugin\WinapayScripted;

use Amoobot\Plugin\Winapay\WinapayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

foreach (['WinapayException', 'WinapayStatus', 'WinapayApi', 'WinapayGatewayPlugin'] as $class) {
    require_once dirname(__DIR__, 5).'/docs/plugins/winapay-gateway/src/'.$class.'.php';
}

/**
 * Test only: the real WinapayGatewayPlugin behind a mocked HTTP layer, installed like a real plugin.
 *
 * PaymentGatewayRegistry creates a plugin with `new $mainClass()`, so the real class would build its own HttpClient and
 * reach winapay.io, past MockHttpResponder. This wrapper gives it a MockHttpClient that answers from a JSON file named
 * by the gateway's config key "test_script": {"responses": [...], "calls": [...]}. Each call takes the next response
 * under a file lock, so processes share one script, which is how a test scripts Winapay's "100 once, then 101". A
 * response is {"body": {...}} or {"transport": "message"} for a network error. The real request is added to "calls".
 *
 * $beforeAnswer runs inside the call in this process, for "something happened while the verify was on its way".
 */
final class ScriptedWinapayPlugin implements PaymentGatewayPluginInterface
{
    /** @var (\Closure(): void)|null */
    public static ?\Closure $beforeAnswer = null;

    public function getType(): string
    {
        return WinapayGatewayPlugin::CODE;
    }

    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        throw new \LogicException('Not scripted.');
    }

    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $script = (string) ($config['test_script'] ?? throw new \LogicException('The gateway has no test_script.'));
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($script): ResponseInterface {
            $response = self::next($script, ['url' => $url, 'body' => $options['body'] ?? null]);
            if (null !== self::$beforeAnswer) {
                (self::$beforeAnswer)();
            }
            if (isset($response['transport'])) {
                throw new TransportException((string) $response['transport']);
            }

            return new MockResponse((string) json_encode($response['body'] ?? []), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return (new WinapayGatewayPlugin($client))->verifyPayment($payment, $payload, $config);
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        return null;
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
            fwrite($handle, (string) json_encode($state));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $response ?? ['transport' => 'The script has no answer left.'];
    }
}
