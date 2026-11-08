<?php

declare(strict_types=1);

namespace Amoobot\Plugin\TetraminatorScripted;

use Amoobot\Plugin\Tetraminator\TetraminatorGatewayPlugin;
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

foreach (['TetraminatorException', 'TetraminatorApi', 'TetraminatorGatewayPlugin'] as $class) {
    require_once dirname(__DIR__, 5).'/docs/plugins/tetraminator-gateway/src/'.$class.'.php';
}

/**
 * Test only: the real TetraminatorGatewayPlugin behind a mocked HTTP layer, installed like a real plugin (the same idea
 * as ScriptedWinapayPlugin). The answers come from the JSON file named by the gateway's config key "test_script":
 * {"responses": [...], "calls": [...]}. Each inquiry takes the next response under a file lock, so processes share one
 * script and every inquiry, in any process, is counted in "calls". A response is {"status": 200, "body": {...}}, or
 * {"transport": "message"} for a network error; an empty script answers with a network error. With "sleep_ms" the
 * answer comes that much later, so a race test can hold both inquiries in flight together.
 */
final class ScriptedTetraminatorPlugin implements PaymentGatewayPluginInterface
{
    public function getType(): string
    {
        return TetraminatorGatewayPlugin::CODE;
    }

    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        throw new \LogicException('Not scripted.');
    }

    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $script = (string) ($config['test_script'] ?? throw new \LogicException('The gateway has no test_script.'));
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($script): ResponseInterface {
            $response = self::next($script, ['method' => $method, 'url' => $url]);
            if (isset($response['sleep_ms'])) {
                usleep(1000 * (int) $response['sleep_ms']);
            }
            if (isset($response['transport'])) {
                throw new TransportException((string) $response['transport']);
            }

            return new MockResponse((string) json_encode($response['body'] ?? []), ['http_code' => (int) ($response['status'] ?? 200), 'response_headers' => ['content-type' => 'application/json']]);
        });

        return (new TetraminatorGatewayPlugin($client))->verifyPayment($payment, $payload, $config);
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
