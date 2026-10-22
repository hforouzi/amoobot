<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\PaymentDisplayState;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Provisioning\Application\CustomerAccessGuard;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer's browser returning from a gateway: /payment/return/{token}.
 *
 * The payment is identified ONLY by its unguessable return token, which the gateway received in
 * the return URL at createPayment time (internal ids, NP_id etc. never identify a payment here,
 * because the paid page shows the service's access details).
 *
 * The page never confirms anything by itself: it asks the provider (verifyPayment), hands a paid
 * result to PaymentSettlementService and then only displays the resulting payment state. While the
 * payment is still pending the page shows a "waiting for confirmation" state that refreshes itself.
 */
final class PaymentReturnController extends AbstractController
{
    public const REFRESH_SECONDS = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentSettlementService $paymentSettlementService,
        private readonly LoggerInterface $logger,
        private readonly CustomerAccessGuard $accessGuard,
    ) {
    }

    #[Route('/payment/return/{token}', name: 'payment_return', requirements: ['token' => PaymentReturnUrl::TOKEN_PATTERN], methods: ['GET'])]
    public function return(Request $request, string $token): Response
    {
        $payment = $this->findPayment($token);
        if (!$payment instanceof Payment) {
            return $this->notFound();
        }

        $statusText = null;
        if ($payment->acceptsGatewayVerification() && $this->isVerifiable($payment)) {
            [$payment, $statusText] = $this->verifyAndSettle($payment, $request);
        }

        $order = $payment->getOrder();
        $state = $this->displayState($payment);
        $service = 'paid' === $state ? $this->findServiceForOrder($order) : null;
        // Only what a client can reach (never the panel's 127.0.0.1 / private address).
        $subscriptionUrl = null !== $service ? (string) $this->accessGuard->subscriptionUrl($service) : '';
        $configText = null !== $service ? implode("\n", $this->accessGuard->configLinks($service, array_values(array_filter(array_map('trim', explode("\n", (string) $service->getConfigText())), static fn (string $line): bool => '' !== $line)))) : '';
        $accessWithheld = null !== $service && (('' === $subscriptionUrl && '' !== trim((string) $service->getSubscriptionUrl())) || ('' === $configText && '' !== trim((string) $service->getConfigText())));

        $this->logger->info('Payment return page.', ['payment_id' => $payment->getId(), 'status' => $payment->getStatus(), 'state' => $state]);

        return $this->page('payment/success.html.twig', [
            'state' => $state,
            'payment' => $payment,
            'order' => $order,
            'service' => $service,
            'subscriptionUrl' => '' !== $subscriptionUrl ? $subscriptionUrl : null,
            'configText' => '' !== $configText ? $configText : null,
            'accessWithheld' => $accessWithheld,
            'redirectEnabled' => 'paid' === $state && '' !== $subscriptionUrl,
            'refreshSeconds' => 'pending' === $state ? self::REFRESH_SECONDS : null,
            'statusText' => $statusText ?? $payment->getCryptoPaymentStatus(),
        ]);
    }

    #[Route('/payment/return/{token}/cancel', name: 'payment_return_cancel', requirements: ['token' => PaymentReturnUrl::TOKEN_PATTERN], methods: ['GET'])]
    public function cancel(string $token): Response
    {
        $payment = $this->findPayment($token);

        return $this->page('payment/cancel.html.twig', [
            'payment' => $payment,
            'order' => $payment?->getOrder(),
        ]);
    }

    /**
     * Return URLs issued before the return token existed. They cannot identify a payment any more;
     * the customer is pointed to the bot (where settled payments are announced anyway).
     */
    #[Route('/payment/success', name: 'payment_success', methods: ['GET'])]
    public function legacySuccess(): Response
    {
        return $this->notFound();
    }

    #[Route('/payment/cancel', name: 'payment_cancel', methods: ['GET'])]
    public function legacyCancel(): Response
    {
        return $this->page('payment/cancel.html.twig', ['payment' => null, 'order' => null]);
    }

    /**
     * @return array{Payment, ?string}
     */
    private function verifyAndSettle(Payment $payment, Request $request): array
    {
        $source = sprintf('%s_return_page', $payment->getGatewayType() ?? 'gateway');

        try {
            $gateway = $payment->getGateway();
            $driver = null !== $gateway
                ? $this->paymentGatewayRegistry->resolve($gateway)
                : $this->paymentGatewayRegistry->resolveByType((string) $payment->getGatewayType());
            // NOWPayments appends NP_id; it is only a hint the gateway accepts if its API ties it to
            // this payment. It never identifies the payment.
            $hints = [];
            $npId = trim((string) $request->query->get('NP_id', ''));
            if (PaymentGatewayType::NOWPAYMENTS === $payment->getGatewayType() && '' !== $npId) {
                $hints['payment_id'] = $npId;
            }
            $verification = $driver->verifyPayment($payment, $hints);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Return page verification failed.', ['payment_id' => $payment->getId(), 'error' => $e->getMessage()]);

            return [$payment, null];
        }

        if ($verification->success && $verification->paid) {
            $result = $this->paymentSettlementService->settle($payment, VerifiedPayment::fromGateway($verification), $source);

            return [$result->payment ?? $payment, $verification->message];
        }

        if ($verification->success && $verification->failed) {
            $result = $this->paymentSettlementService->recordGatewayFailure($payment, $source);

            return [$result->payment ?? $payment, $verification->message];
        }

        return [$payment, $verification->message];
    }

    /**
     * Manual card payments are confirmed by an admin, never by asking a provider.
     */
    private function isVerifiable(Payment $payment): bool
    {
        return null !== $payment->getGatewayType() && PaymentGatewayType::MANUAL_CARD !== $payment->getGatewayType();
    }

    private function displayState(Payment $payment): string
    {
        return PaymentDisplayState::forReturnPage($payment);
    }

    private function findPayment(string $token): ?Payment
    {
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['returnToken' => $token]);

        return $payment instanceof Payment ? $payment : null;
    }

    private function findServiceForOrder(Order $order): ?VpnService
    {
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $order->getTargetService();
        }

        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        return $service instanceof VpnService ? $service : null;
    }

    private function notFound(): Response
    {
        return $this->page('payment/success.html.twig', [
            'state' => 'not_found',
            'payment' => null,
            'order' => null,
            'service' => null,
            'subscriptionUrl' => null,
            'configText' => null,
            'redirectEnabled' => false,
            'refreshSeconds' => null,
            'statusText' => null,
        ], Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $parameters, new Response('', $status));
        // The URL is a bearer secret: keep it out of caches, Referer headers and search engines.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
