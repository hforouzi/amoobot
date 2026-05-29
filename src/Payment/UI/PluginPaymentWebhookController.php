<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PluginPaymentWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentConfirmationService $paymentConfirmationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/payment/webhook/plugin/{code}', name: 'payment_webhook_plugin', methods: ['POST'])]
    public function __invoke(Request $request, string $code): JsonResponse
    {
        $gateway = $this->findActivePluginGateway($code);
        if (!$gateway instanceof PaymentGateway) {
            return $this->json(['ok' => false, 'message' => 'plugin_gateway_not_found'], 404);
        }

        try {
            $driver = $this->paymentGatewayRegistry->resolve($gateway);
        } catch (\Throwable $exception) {
            $this->logger->warning('Plugin payment webhook driver resolution failed.', [
                'plugin_code' => $code,
                'gateway_id' => $gateway->getId(),
                'error' => $exception->getMessage(),
            ]);

            return $this->json(['ok' => false, 'message' => 'plugin_gateway_unavailable'], 404);
        }

        if (!$driver instanceof PluginPaymentGatewayDriverAdapter || !$driver->supportsWebhook()) {
            return $this->json(['ok' => false, 'message' => 'webhook_not_supported'], 404);
        }

        $payload = $this->buildPayload($request);
        $payment = $this->findPaymentByGatewayReference($gateway, $this->findExternalPaymentId($payload));
        if (!$payment instanceof Payment) {
            return $this->json(['ok' => false, 'message' => 'payment_not_found'], 404);
        }

        if (PaymentStatus::CONFIRMED === $payment->getStatus()) {
            return $this->json(['ok' => true, 'message' => 'already_confirmed'], 200);
        }

        $webhookResult = $driver->handleWebhook($payment, $payload, $request);
        if (null === $webhookResult || !$webhookResult->handled) {
            $message = $webhookResult?->message ?? 'webhook_not_handled';
            $status = 'unauthorized' === $message ? 401 : 500;
            $this->logger->warning('Plugin payment webhook was not handled.', [
                'plugin_code' => $code,
                'gateway_id' => $gateway->getId(),
                'payment_id' => $payment->getId(),
                'message' => $message,
            ]);

            return $this->json(['ok' => false, 'message' => $message], $status);
        }

        $verificationPayload = array_replace($payload, is_array($webhookResult->payload) ? $webhookResult->payload : []);
        $payment->setCallbackPayload($this->sanitizePayload($verificationPayload));

        if (false === ($verificationPayload['paid'] ?? null)) {
            $this->entityManager->flush();

            return $this->json(['ok' => true, 'paid' => false, 'message' => $webhookResult->message ?? 'not_paid'], 200);
        }

        try {
            $verification = $driver->verifyPayment($payment, $verificationPayload);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Plugin payment webhook verification failed.', [
                'plugin_code' => $code,
                'gateway_id' => $gateway->getId(),
                'payment_id' => $payment->getId(),
                'error' => $exception->getMessage(),
            ]);

            return $this->json(['ok' => false, 'message' => 'verification_failed'], 500);
        }

        if (!$verification->success || !$verification->paid) {
            return $this->json(['ok' => true, 'paid' => false, 'message' => $verification->message ?? 'not_paid'], 200);
        }

        $result = $this->paymentConfirmationService->confirm($payment, sprintf('plugin_%s_webhook', $code));
        if ($result->processed || $result->alreadyProcessed) {
            return $this->json(['ok' => true, 'paid' => true, 'message' => 'confirmed'], 200);
        }

        $this->logger->error('Plugin payment webhook confirmation failed after verification.', [
            'plugin_code' => $code,
            'gateway_id' => $gateway->getId(),
            'payment_id' => $payment->getId(),
            'order_id' => $payment->getOrder()->getId(),
            'message' => $result->message,
        ]);

        return $this->json(['ok' => false, 'message' => $result->message], 500);
    }

    private function findActivePluginGateway(string $code): ?PaymentGateway
    {
        $code = trim($code);
        if ('' === $code) {
            return null;
        }

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->findOneBy([
            'pluginCode' => $code,
            'isActive' => true,
        ]);

        return $gateway instanceof PaymentGateway ? $gateway : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Request $request): array
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        foreach (['token', 'secret'] as $secretKey) {
            unset($payload[$secretKey]);
        }

        $content = trim((string) $request->getContent());
        if ('' !== $content) {
            $decoded = json_decode($content, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $payload = array_replace_recursive($payload, $decoded);
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findExternalPaymentId(array $payload): ?string
    {
        foreach (['payment_id', 'paymentId', 'transaction_id', 'transactionId'] as $key) {
            $value = $this->stringOrNull($payload[$key] ?? null);
            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function findPaymentByGatewayReference(PaymentGateway $gateway, ?string $paymentId): ?Payment
    {
        if (null === $paymentId) {
            return null;
        }

        $qb = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p');
        $qb->where('p.gateway = :gateway')
            ->andWhere('(p.gatewayTransactionId = :paymentId OR p.authority = :paymentId)')
            ->setParameter('gateway', $gateway)
            ->setParameter('paymentId', $paymentId)
            ->setMaxResults(1);

        $payment = $qb->getQuery()->getOneOrNullResult();

        return $payment instanceof Payment ? $payment : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = ['api_key', 'api-key', 'token', 'secret', 'callback_secret', 'callback_token', 'authorization', 'password'];
        $sanitized = [];
        foreach ($payload as $key => $value) {
            $keyText = is_string($key) ? $key : (string) $key;
            if (in_array(strtolower($keyText), $sensitiveKeys, true)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitizePayload($value) : $value;
        }

        return $sanitized;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }
}
