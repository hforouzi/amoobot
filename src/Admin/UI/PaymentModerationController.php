<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\Payment;
use App\Payment\Application\PaymentModeration;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Security\AdminActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * EasyAdmin payment actions. The GET is a page showing the amount, the order and the effect (the list's buttons link
 * there); only its POST, with a per-payment CSRF token, approves or rejects anything, so a link opened by a logged-in
 * admin cannot.
 */
#[Route('/admin/payment')]
class PaymentModerationController extends AbstractController
{
    use ConfirmPages;
    use TranslatedFlashes;

    public const CSRF_PREFIX = 'payment_moderation_';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Approve a manual/receipt payment or a NEEDS_REVIEW payment. On a PROVISIONING_FAILED payment
     * this retries fulfilment (the payment was already verified).
     */
    #[Route('/{id}/confirm', name: 'admin_payment_confirm', methods: ['GET', 'POST'])]
    public function confirm(Payment $payment, Request $request, PaymentModeration $moderation): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->confirmStep($payment, $request, 'confirm', 'action.confirm_payment', 'success');
        }
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        return $this->back($request, $moderation->approve($payment, $this->currentAdmin(), null, PaymentModeration::CHANNEL_WEB));
    }

    #[Route('/{id}/retry', name: 'admin_payment_retry', methods: ['GET', 'POST'])]
    public function retry(Payment $payment, Request $request, PaymentModeration $moderation): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->confirmStep($payment, $request, 'retry', 'action.retry_provisioning', 'primary');
        }
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        return $this->back($request, $moderation->retry($payment, $this->currentAdmin(), null, PaymentModeration::CHANNEL_WEB));
    }

    #[Route('/{id}/reject', name: 'admin_payment_reject', methods: ['GET', 'POST'])]
    public function reject(Payment $payment, Request $request, PaymentModeration $moderation): Response
    {
        if (!$request->isMethod('POST')) {
            // The form posts to this same URL, so ?refunded=1 stays.
            return '1' === (string) $request->query->get('refunded')
                ? $this->confirmStep($payment, $request, 'reject_refunded', 'action.reject_payment_refunded', 'warning')
                : $this->confirmStep($payment, $request, 'reject', 'action.reject_payment', 'warning');
        }
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        $note = $request->request->get('note');
        $refunded = '1' === (string) ($request->query->get('refunded') ?? $request->request->get('refunded') ?? '');

        return $this->back($request, $moderation->reject($payment, is_string($note) ? $note : null, $refunded, $this->currentAdmin(), null, PaymentModeration::CHANNEL_WEB));
    }

    /** What the action will do to this payment, with the amount and the order. */
    private function confirmStep(Payment $payment, Request $request, string $action, string $submit, string $style): Response
    {
        $order = $payment->getOrder();
        $back = $this->adminUrl($request->headers->get('referer'), $request);

        return $this->confirmPage('action_confirm.payment.'.$action.'.title', [
            ['action_confirm.payment.summary', [
                '%id%' => $payment->getId(),
                '%amount%' => number_format($payment->getAmount()),
                '%currency%' => $this->translator->trans('currency.'.AmountNormalizer::SHOP_CURRENCY, [], 'admin'),
                '%order%' => $order->getId(),
                '%type%' => $this->translator->trans('order.type.'.$order->getType(), [], 'admin'),
                '%customer%' => (string) $order->getUser(),
            ]],
            ['action_confirm.payment.'.$action.'.body'],
        ], self::CSRF_PREFIX.$payment->getId(), $submit, $back ?? $this->generateUrl('admin_payment_index'), $style, null === $back ? [] : ['back' => $back]);
    }

    private function validToken(Payment $payment, Request $request): bool
    {
        $token = $request->query->get('_token') ?? $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF_PREFIX.$payment->getId(), $token);
    }

    private function invalidToken(Request $request): RedirectResponse
    {
        $this->flash('danger', 'flash.csrf_expired');

        return $this->redirectBack($request);
    }

    private function currentAdmin(): ?AdminActor
    {
        $user = $this->getUser();

        return $user instanceof AdminUser ? AdminActor::fromAdminUser($user) : null;
    }

    private function back(Request $request, SettlementResult $result): RedirectResponse
    {
        // The outcome in the admin's language; the settlement's own message (it can carry a review reason or a
        // provisioning error from a gateway or panel) follows as the detail, escaped by flash().
        $this->flash(match ($result->outcome) {
            SettlementOutcome::CONFIRMED => 'success',
            SettlementOutcome::REJECTED, SettlementOutcome::NEEDS_REVIEW, SettlementOutcome::ORDER_ALREADY_FULFILLED => 'warning',
            SettlementOutcome::ALREADY_PROCESSED => 'info',
            default => 'danger',
        }, 'flash.payment.'.$result->outcome->value, ['%detail%' => $result->message]);

        return $this->redirectBack($request);
    }

    /**
     * Back to the list the admin came from (e.g. "Needs review"), else the payments index.
     */
    private function redirectBack(Request $request): RedirectResponse
    {
        // The confirm page's form carries the list it was opened from; its own referer is that confirm page.
        $back = $this->adminUrl($request->request->get('back'), $request) ?? $this->adminUrl($request->headers->get('referer'), $request);
        if (null !== $back) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => \App\Admin\UI\Crud\PaymentCrudController::class,
        ]);
    }

    /** The URL if it is an admin page of this host. */
    private function adminUrl(mixed $url, Request $request): ?string
    {
        return is_string($url) && str_starts_with($url, $request->getSchemeAndHttpHost().'/admin') ? $url : null;
    }
}
