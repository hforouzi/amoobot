<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HooshPay's webhook: POST /payment/webhook/hooshpay, the callback_url of every HooshPay invoice
 * (docs/plugins/hooshpay-gateway/README.md). The generic plugin routes refuse "hooshpay" (PaymentWebhookController).
 *
 *   1. every active gateway of plugin "hooshpay": the plugin's handleWebhook() (through the adapter) authenticates the
 *      request, fail closed: the switch on, the Secret set, X-HooshPay-Signature equal to the HMAC of the body.
 *      Refused: 4xx, nothing looked up, nothing changed;
 *   2. the payment found ONLY by the invoice uid stored at createPayment time, scoped to that gateway. None: 200, so
 *      HooshPay stops retrying, and a log line;
 *   3. a payment that no longer accepts a verification (settled, rejected): 200, no API call;
 *   4. verifyPayment(): HooshPay's verify, which must confirm paid, the uid, our order_id and our amount. A paid answer
 *      that does not match is logged as a security warning and not settled;
 *   5. PaymentSettlementService::settle(), idempotent: a repeated webhook answers 200 and credits nothing twice.
 *
 * Nothing from the body is logged: no signature, no payload, no card data. The answer names only an outcome code.
 */
final class HooshPayWebhookController
{
    public const PLUGIN_CODE = 'hooshpay';
    public const SOURCE = 'plugin_hooshpay_webhook';
    /** rawResponse.error of a verification whose paid answer does not match the payment (HooshPayGatewayPlugin). */
    private const VERIFY_MISMATCH = 'verify_mismatch';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentSettlementService $settlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/payment/webhook/hooshpay', name: 'payment_webhook_hooshpay', methods: ['POST'], priority: 20)]
    public function webhook(Request $request): JsonResponse
    {
        try {
            [$status, $code] = $this->handle($request);
        } catch (\Throwable $e) {
            // The class only: a message may quote the config or the provider's answer.
            $this->logger->error('HooshPay webhook failed.', ['error' => $e::class]);
            [$status, $code] = [500, 'error'];
        }

        return new JsonResponse(['ok' => $status < 400, 'message' => $code], $status);
    }

    /**
     * @return array{int, string}
     */
    private function handle(Request $request): array
    {
        $gateways = $this->entityManager->getRepository(PaymentGateway::class)->findBy(['pluginCode' => self::PLUGIN_CODE, 'isActive' => true], ['id' => 'ASC']);
        $rejection = [404, 'gateway_not_found'];
        foreach ($gateways as $gateway) {
            try {
                $driver = $this->gatewayRegistry->resolve($gateway);
            } catch (\Throwable $e) {
                $this->logger->warning('HooshPay webhook: the gateway driver is unavailable.', ['gateway_id' => $gateway->getId(), 'error' => $e::class]);
                continue;
            }
            if (!$driver instanceof NotificationAwareGatewayInterface) {
                continue;
            }
            $notification = $driver->handleNotification($gateway, $request, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK);
            if (!$notification->authenticated) {
                $rejection = [$notification->rejectStatus, $notification->rejectReason];
                continue;
            }

            $uid = $notification->references[0] ?? null;
            $payment = null === $uid ? null : $this->entityManager->getRepository(Payment::class)->findOneBy(['gateway' => $gateway, 'gatewayTransactionId' => $uid], ['id' => 'DESC']);
            if (!$payment instanceof Payment) {
                $this->logger->warning('HooshPay webhook: unknown invoice, ignored.', ['gateway_id' => $gateway->getId()]);

                return [200, 'payment_not_found'];
            }

            return $this->process($driver, $payment);
        }

        $this->logger->warning('HooshPay webhook rejected.', ['reason' => $rejection[1], 'status' => $rejection[0], 'ip' => $request->getClientIp()]);

        return $rejection;
    }

    /**
     * @return array{int, string}
     */
    private function process(NotificationAwareGatewayInterface&PaymentGatewayInterface $driver, Payment $payment): array
    {
        if (!$payment->acceptsGatewayVerification()) {
            return [200, 'already_'.$payment->getStatus()];
        }

        $verification = $driver->verifyPayment($payment, []);
        $this->entityManager->flush();

        if ($verification->success && $verification->paid) {
            $result = $this->settlement->settle($payment, VerifiedPayment::fromGateway($verification), self::SOURCE);

            return [$result->isRecorded() ? 200 : 500, $result->outcome->value];
        }

        $raw = is_array($verification->rawResponse) ? $verification->rawResponse : [];
        if (self::VERIFY_MISMATCH === ($raw['error'] ?? null)) {
            $this->logger->warning('Security: HooshPay webhook for a paid invoice that does not match the payment; not settled.', [
                'payment_id' => $payment->getId(),
                'reasons' => is_array($raw['reasons'] ?? null) ? array_values(array_filter($raw['reasons'], 'is_string')) : [],
                'expected_amount' => is_string($raw['expected_amount'] ?? null) ? $raw['expected_amount'] : null,
                'reported_amount' => is_string($raw['reported_amount'] ?? null) ? $raw['reported_amount'] : null,
            ]);

            return [200, 'mismatch'];
        }

        // Not paid (yet), or HooshPay could not answer: a 5xx makes HooshPay ask again later.
        return $verification->success ? [200, 'pending'] : [503, 'verification_unavailable'];
    }
}
