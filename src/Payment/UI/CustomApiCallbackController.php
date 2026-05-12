<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\ArrayPathReader;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomApiCallbackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentConfirmationService $paymentConfirmationService,
        private readonly ArrayPathReader $arrayPathReader,
    ) {
    }

    #[Route('/payment/callback/custom-api/{gatewayId}', name: 'payment_callback_custom_api', methods: ['GET', 'POST'])]
    public function callback(Request $request, int $gatewayId): Response
    {
        $gateway = $this->findGateway($gatewayId);
        if (!$gateway instanceof PaymentGateway) {
            return $this->html('درگاه یافت نشد.', 404);
        }
        if (PaymentGatewayType::CUSTOM_API !== $gateway->getType()) {
            return $this->html('نوع درگاه نامعتبر است.', 400);
        }

        $payload = $this->buildPayload($request);
        $payment = $this->findPaymentForCallback($gateway, $payload);
        if (!$payment instanceof Payment) {
            return $this->html('پرداخت یافت نشد.', 404);
        }

        if (PaymentStatus::CONFIRMED === $payment->getStatus()) {
            return $this->html('پرداخت قبلاً تایید شده است.', 200);
        }

        try {
            $verification = $this->paymentGatewayRegistry
                ->resolve($gateway)
                ->verifyPayment($payment, $payload);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[CustomApiCallbackController] callback_verify_exception gateway_id=%d payment_id=%d message="%s"',
                $gateway->getId() ?? 0,
                $payment->getId() ?? 0,
                $e->getMessage()
            ));

            return $this->html('خطا در بررسی پرداخت.', 500);
        }

        if (!$verification->success || !$verification->paid) {
            return $this->html($verification->message ?? 'پرداخت تایید نشد.', 400);
        }

        $result = $this->paymentConfirmationService->confirm($payment, 'custom_api_callback');
        if ($result->processed || $result->alreadyProcessed) {
            return $this->html('پرداخت با موفقیت تایید شد.', 200);
        }

        return $this->html($result->message, 400);
    }

    #[Route('/payment/webhook/custom-api/{gatewayId}', name: 'payment_webhook_custom_api', methods: ['POST'])]
    public function webhook(Request $request, int $gatewayId): JsonResponse
    {
        $gateway = $this->findGateway($gatewayId);
        if (!$gateway instanceof PaymentGateway) {
            return $this->json(['ok' => false, 'message' => 'gateway_not_found'], 404);
        }
        if (PaymentGatewayType::CUSTOM_API !== $gateway->getType()) {
            return $this->json(['ok' => false, 'message' => 'invalid_gateway_type'], 400);
        }

        $config = is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
        $webhookConfig = is_array($config['webhook'] ?? null) ? $config['webhook'] : [];
        if (!$this->toBool($webhookConfig['enabled'] ?? false)) {
            return $this->json(['ok' => false, 'message' => 'webhook_disabled'], 404);
        }

        if (!$this->verifyWebhookSecret($request, $webhookConfig)) {
            return $this->json(['ok' => false, 'message' => 'unauthorized'], 401);
        }

        $payload = $this->buildPayload($request);
        $payment = $this->findPaymentForWebhook($gateway, $payload, $webhookConfig);
        if (!$payment instanceof Payment) {
            return $this->json(['ok' => false, 'message' => 'payment_not_found'], 404);
        }

        if (PaymentStatus::CONFIRMED === $payment->getStatus()) {
            return $this->json(['ok' => true, 'message' => 'already_confirmed'], 200);
        }

        if ($this->isWebhookExplicitlyNotPaid($payload, $webhookConfig)) {
            return $this->json(['ok' => true, 'paid' => false, 'message' => 'not_paid'], 200);
        }

        try {
            $verification = $this->paymentGatewayRegistry
                ->resolve($gateway)
                ->verifyPayment($payment, $payload);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[CustomApiCallbackController] webhook_verify_exception gateway_id=%d payment_id=%d message="%s"',
                $gateway->getId() ?? 0,
                $payment->getId() ?? 0,
                $e->getMessage()
            ));

            return $this->json(['ok' => false, 'message' => 'verify_failed'], 500);
        }

        if (!$verification->success || !$verification->paid) {
            return $this->json(['ok' => true, 'paid' => false, 'message' => $verification->message ?? 'not_paid'], 200);
        }

        $result = $this->paymentConfirmationService->confirm($payment, 'custom_api_webhook');
        if ($result->processed || $result->alreadyProcessed) {
            return $this->json(['ok' => true, 'paid' => true, 'message' => 'confirmed'], 200);
        }

        return $this->json(['ok' => false, 'message' => $result->message], 400);
    }

    private function findGateway(int $gatewayId): ?PaymentGateway
    {
        if ($gatewayId <= 0) {
            return null;
        }

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($gatewayId);

        return $gateway instanceof PaymentGateway ? $gateway : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Request $request): array
    {
        $payload = array_merge($request->query->all(), $request->request->all());

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
    private function findPaymentForCallback(PaymentGateway $gateway, array $payload): ?Payment
    {
        $paymentId = (int) ($payload['payment_id'] ?? $payload['paymentId'] ?? 0);
        if ($paymentId > 0) {
            $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
            if ($payment instanceof Payment && $payment->getGateway()?->getId() === $gateway->getId()) {
                return $payment;
            }
        }

        $transactionId = $this->findFirstScalar($payload, ['transaction_id', 'transactionId', 'trackId', 'track_id']);
        $authority = $this->findFirstScalar($payload, ['authority', 'Authority']);

        return $this->findPaymentByGatewayReference($gateway, $transactionId, $authority);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $webhookConfig
     */
    private function findPaymentForWebhook(PaymentGateway $gateway, array $payload, array $webhookConfig): ?Payment
    {
        $paymentLookupPath = trim((string) ($webhookConfig['payment_lookup'] ?? ''));
        $lookupValue = null;
        if ('' !== $paymentLookupPath) {
            $lookupValue = $this->stringOrNull($this->arrayPathReader->get($payload, $paymentLookupPath));
        }

        if (null === $lookupValue) {
            $lookupValue = $this->findFirstScalar($payload, ['transaction_id', 'transactionId', 'authority', 'trackId', 'track_id']);
        }

        return $this->findPaymentByGatewayReference($gateway, $lookupValue, $lookupValue);
    }

    private function findPaymentByGatewayReference(PaymentGateway $gateway, ?string $transactionId, ?string $authority): ?Payment
    {
        if (null === $transactionId && null === $authority) {
            return null;
        }

        $qb = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p');
        $qb->where('p.gateway = :gateway')
            ->setParameter('gateway', $gateway)
            ->setMaxResults(1);

        $orX = $qb->expr()->orX();
        if (null !== $transactionId) {
            $orX->add($qb->expr()->eq('p.gatewayTransactionId', ':transactionId'));
            $orX->add($qb->expr()->eq('p.trackingCode', ':transactionId'));
            $qb->setParameter('transactionId', $transactionId);
        }
        if (null !== $authority) {
            $orX->add($qb->expr()->eq('p.authority', ':authority'));
            $qb->setParameter('authority', $authority);
        }

        $qb->andWhere($orX);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $keys
     */
    private function findFirstScalar(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $this->stringOrNull($payload[$key]);
            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $webhookConfig
     */
    private function verifyWebhookSecret(Request $request, array $webhookConfig): bool
    {
        $headerName = trim((string) ($webhookConfig['secret_header'] ?? ''));
        $secret = trim((string) ($webhookConfig['secret'] ?? ''));
        if ('' === $headerName || '' === $secret) {
            return true;
        }

        $received = trim((string) $request->headers->get($headerName, ''));
        if ('' === $received) {
            return false;
        }

        return hash_equals($secret, $received);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $webhookConfig
     */
    private function isWebhookExplicitlyNotPaid(array $payload, array $webhookConfig): bool
    {
        $statusPath = trim((string) ($webhookConfig['status_path'] ?? ''));
        if ('' === $statusPath) {
            return false;
        }

        $status = strtolower(trim((string) ($this->arrayPathReader->get($payload, $statusPath) ?? '')));
        if ('' === $status) {
            return false;
        }

        $paidValues = $this->extractWebhookPaidValues($webhookConfig);
        if ([] === $paidValues) {
            return false;
        }

        return !in_array($status, $paidValues, true);
    }

    /**
     * @param array<string, mixed> $webhookConfig
     *
     * @return list<string>
     */
    private function extractWebhookPaidValues(array $webhookConfig): array
    {
        $raw = $webhookConfig['paid_values'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = strtolower(trim((string) $item));
            if ('' !== $text) {
                $values[] = $text;
            }
        }

        return array_values(array_unique($values));
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private function html(string $message, int $status): Response
    {
        return new Response(sprintf(
            '<html><head><meta charset="UTF-8"></head><body><h3>%s</h3></body></html>',
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        ), $status);
    }
}
