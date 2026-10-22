<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\Payment;
use App\Payment\Application\PaymentModeration;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Security\AdminActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * EasyAdmin payment actions. POST only with a per-payment CSRF token (see PaymentCrudController),
 * so a link opened by a logged-in admin cannot approve or reject anything.
 */
#[Route('/admin/payment')]
class PaymentModerationController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF_PREFIX = 'payment_moderation_';

    /**
     * Approve a manual/receipt payment or a NEEDS_REVIEW payment. On a PROVISIONING_FAILED payment
     * this retries fulfilment (the payment was already verified).
     */
    #[Route('/{id}/confirm', name: 'admin_payment_confirm', methods: ['POST'])]
    public function confirm(Payment $payment, Request $request, PaymentModeration $moderation): RedirectResponse
    {
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        return $this->back($request, $moderation->approve($payment, $this->currentAdmin(), null, PaymentModeration::CHANNEL_WEB));
    }

    #[Route('/{id}/retry', name: 'admin_payment_retry', methods: ['POST'])]
    public function retry(Payment $payment, Request $request, PaymentModeration $moderation): RedirectResponse
    {
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        return $this->back($request, $moderation->retry($payment, $this->currentAdmin(), null, PaymentModeration::CHANNEL_WEB));
    }

    #[Route('/{id}/reject', name: 'admin_payment_reject', methods: ['POST'])]
    public function reject(Payment $payment, Request $request, PaymentModeration $moderation): RedirectResponse
    {
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        $note = $request->request->get('note');
        $refunded = '1' === (string) ($request->query->get('refunded') ?? $request->request->get('refunded') ?? '');

        return $this->back($request, $moderation->reject($payment, is_string($note) ? $note : null, $refunded, $this->currentAdmin(), null, PaymentModeration::CHANNEL_WEB));
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
        $referer = (string) $request->headers->get('referer', '');
        if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost().'/admin')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => \App\Admin\UI\Crud\PaymentCrudController::class,
        ]);
    }
}
