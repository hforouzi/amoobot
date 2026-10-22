<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Checkout\CheckoutQuote;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Checkout\PaymentProgress;
use App\MiniApp\Checkout\ReceiptUpload;
use App\MiniApp\Security\MiniAppUser;
use App\Shop\Application\Checkout\OrderLifecycle;
use App\Shop\Application\Checkout\PaymentStart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Mini App's state-changing checkout calls (JSON in, JSON out; CSRF checked by the firewall). Each creating call
 * is rate limited per Telegram user and runs once per Idempotency-Key (CheckoutIdempotency), so a double tap or a
 * retry returns the first answer. The answer tells the page where to go next ({redirect}) or why not ({error}).
 * Prices are never read from the request: MiniAppCheckout computes them.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppCheckoutController extends AbstractController
{
    public function __construct(
        private readonly MiniAppCheckout $checkout,
        private readonly CheckoutIdempotency $idempotency,
        private readonly PaymentProgress $progress,
        private readonly ReceiptUpload $receiptUpload,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.miniapp_checkout')]
        private readonly RateLimiterFactory $checkoutLimiter,
        #[Autowire(service: 'limiter.miniapp_receipt')]
        private readonly RateLimiterFactory $receiptLimiter,
    ) {
    }

    /** The price of a new service with these choices (and code): computed by the server, nothing is created. */
    #[Route('/app/api/checkout/quote', name: 'miniapp_checkout_quote', defaults: ['_miniapp_section' => 'shop'], methods: ['POST'])]
    public function quote(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        return $this->guarded($request, function () use ($request, $user): JsonResponse {
            $body = $this->body($request);
            $quote = $this->checkout->quoteNewService($user->customer(), (int) ($body['planId'] ?? 0), $body['trafficGb'] ?? null, $body['durationDays'] ?? null, $this->string($body, 'discountCode'));

            return $this->quoteResponse($request, $quote);
        });
    }

    #[Route('/app/api/checkout/new', name: 'miniapp_checkout_new', defaults: ['_miniapp_section' => 'shop'], methods: ['POST'])]
    public function newService(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        return $this->creating($request, $user, 'new_service', function () use ($request, $user): array {
            $body = $this->body($request);
            $order = $this->checkout->newService($user, (int) ($body['planId'] ?? 0), (string) $this->string($body, 'accountName'), $body['trafficGb'] ?? null, $body['durationDays'] ?? null, $this->string($body, 'discountCode'));

            return ['redirect' => $this->generateUrl('miniapp_order_pay', ['id' => $order->getId()])];
        });
    }

    #[Route('/app/api/orders/{id}/pay', name: 'miniapp_order_pay_api', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]
    public function pay(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->creating($request, $user, 'pay:'.$id, function () use ($request, $user, $id): array {
            $start = $this->checkout->pay($user, $id, (int) ($this->body($request)['methodId'] ?? 0));
            if (!$start->isReady()) {
                // The gateway refused: the payment keeps its note (as in the bot); the customer may pick again.
                return ['error' => 'payment_start_failed', 'status' => Response::HTTP_BAD_GATEWAY, 'message' => $start->message ?: $this->message('payment_start_failed', $request)];
            }

            return ['redirect' => $this->generateUrl('miniapp_payment', ['id' => $start->payment?->getId()]), 'kind' => $start->kind];
        });
    }

    #[Route('/app/api/orders/{id}/cancel', name: 'miniapp_order_cancel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]
    public function cancel(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->guarded($request, function () use ($request, $user, $id): JsonResponse {
            $outcome = $this->checkout->cancel($user->customer(), $id);
            if (OrderLifecycle::CANCEL_NOT_ALLOWED === $outcome) {
                throw new CheckoutFailure('cancel_not_allowed', Response::HTTP_CONFLICT);
            }

            return $this->ok(['redirect' => $this->generateUrl('miniapp_shop'), 'message' => $this->message('order_cancelled', $request)]);
        });
    }

    #[Route('/app/api/trial/{id}', name: 'miniapp_trial_claim', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'shop'], methods: ['POST'])]
    public function claimTrial(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->creating($request, $user, 'trial:'.$id, function () use ($user, $id): array {
            $service = $this->checkout->claimTrial($user, $id);

            return ['redirect' => $this->generateUrl('miniapp_service', ['id' => $service->getId()])];
        });
    }

    #[Route('/app/api/payments/{id}/receipt', name: 'miniapp_payment_receipt', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]
    public function receipt(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->guarded($request, function () use ($request, $user, $id): JsonResponse {
            $this->limit($this->receiptLimiter, $user);
            $payment = $this->progress->ownedPayment($user->customer(), $id) ?? throw new CheckoutFailure('payment_not_found', Response::HTTP_NOT_FOUND);
            $file = $request->files->get('receipt');
            $this->receiptUpload->submit($user, $payment, $file instanceof UploadedFile ? $file : null);

            return $this->ok(['redirect' => $this->generateUrl('miniapp_payment', ['id' => $id]), 'message' => $this->message('receipt_sent', $request)]);
        });
    }

    /** Read only: where the payment stands. Polled by the payment page; never asks a gateway. */
    #[Route('/app/api/payments/{id}/status', name: 'miniapp_payment_status', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]
    public function status(#[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $payment = $this->progress->ownedPayment($user->customer(), $id);
        if (null === $payment) {
            return $this->failure(new CheckoutFailure('payment_not_found', Response::HTTP_NOT_FOUND), null);
        }

        return $this->ok($this->progress->snapshot($payment));
    }

    /**
     * Rate limit + idempotency around a call that creates something; its stored answer may be an error the gateway
     * gave (a new key is needed to try again), a CheckoutFailure is never stored.
     *
     * @param callable(): array<string, mixed> $action
     */
    private function creating(Request $request, MiniAppUser $user, string $scope, callable $action): JsonResponse
    {
        return $this->guarded($request, function () use ($request, $user, $scope, $action): JsonResponse {
            $this->limit($this->checkoutLimiter, $user);
            $answer = $this->idempotency->once($user->customer(), $request->headers->get(CheckoutIdempotency::HEADER), $scope, $action);
            $status = (int) ($answer['status'] ?? Response::HTTP_OK);
            unset($answer['status']);

            return $this->ok($answer, $status);
        });
    }

    /**
     * @param callable(): JsonResponse $action
     */
    private function guarded(Request $request, callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CheckoutFailure $failure) {
            return $this->failure($failure, $request);
        }
    }

    private function limit(RateLimiterFactory $limiter, MiniAppUser $user): void
    {
        if (!$limiter->create($user->telegramUserId())->consume()->isAccepted()) {
            throw new CheckoutFailure('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    private function quoteResponse(Request $request, CheckoutQuote $quote): JsonResponse
    {
        $html = (string) $this->fragments->render($request, 'miniapp/view/_price.html.twig', ['quote' => $quote])->getContent();

        return $this->ok($quote->toArray() + ['html' => $html]);
    }

    private function failure(CheckoutFailure $failure, ?Request $request): JsonResponse
    {
        return $this->ok(['error' => $failure->error, 'message' => $failure->customerMessage ?: $this->message($failure->error, $request)] + $failure->extra, $failure->status);
    }

    private function message(string $key, ?Request $request): string
    {
        $locale = null !== $request ? MiniAppLocale::fromRequest($request) : 'fa';
        $id = 'checkout.message.'.$key;
        $text = $this->translator->trans($id, [], 'miniapp', $locale);

        return $text === $id ? $this->translator->trans('state.error', [], 'miniapp', $locale) : $text;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $data = 'json' === $request->getContentTypeFormat() ? json_decode($request->getContent(), true) : $request->request->all();

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
