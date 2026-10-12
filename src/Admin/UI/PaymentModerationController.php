<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Payment\Domain\PaymentStatus;
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
    public const CSRF_PREFIX = 'payment_moderation_';

    /**
     * Approve a manual/receipt payment or a NEEDS_REVIEW payment. On a PROVISIONING_FAILED payment
     * this retries fulfilment (the payment was already verified).
     */
    #[Route('/{id}/confirm', name: 'admin_payment_confirm', methods: ['POST'])]
    public function confirm(Payment $payment, Request $request, PaymentSettlementService $settlement): RedirectResponse
    {
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        $adminId = $this->currentAdminId();
        $result = PaymentStatus::PROVISIONING_FAILED === $payment->getStatus()
            ? $settlement->retryProvisioning($payment, $adminId, null, 'easyadmin_retry_provisioning')
            : $settlement->approveByAdmin($payment, $adminId, null, 'easyadmin_payment_approval');

        return $this->back($request, $result);
    }

    #[Route('/{id}/retry', name: 'admin_payment_retry', methods: ['POST'])]
    public function retry(Payment $payment, Request $request, PaymentSettlementService $settlement): RedirectResponse
    {
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        return $this->back($request, $settlement->retryProvisioning($payment, $this->currentAdminId(), null, 'easyadmin_retry_provisioning'));
    }

    #[Route('/{id}/reject', name: 'admin_payment_reject', methods: ['POST'])]
    public function reject(Payment $payment, Request $request, PaymentSettlementService $settlement): RedirectResponse
    {
        if (!$this->validToken($payment, $request)) {
            return $this->invalidToken($request);
        }

        $note = $request->request->get('note');
        $result = $settlement->reject($payment, is_string($note) ? $note : null, $this->currentAdminId(), null, 'easyadmin_payment_reject');

        return $this->back($request, $result);
    }

    private function validToken(Payment $payment, Request $request): bool
    {
        $token = $request->query->get('_token') ?? $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF_PREFIX.$payment->getId(), $token);
    }

    private function invalidToken(Request $request): RedirectResponse
    {
        $this->addFlash('danger', 'Invalid or expired security token. Please reload the page and try again.');

        return $this->redirectBack($request);
    }

    private function currentAdminId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof AdminUser ? $user->getId() : null;
    }

    private function back(Request $request, SettlementResult $result): RedirectResponse
    {
        $this->addFlash(match ($result->outcome) {
            SettlementOutcome::CONFIRMED => 'success',
            SettlementOutcome::REJECTED, SettlementOutcome::NEEDS_REVIEW => 'warning',
            SettlementOutcome::ALREADY_PROCESSED => 'info',
            default => 'danger',
        }, $result->message);

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
