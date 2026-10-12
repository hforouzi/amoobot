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

#[Route('/admin/payment')]
class PaymentModerationController extends AbstractController
{
    /**
     * Approve a manual/receipt payment or a NEEDS_REVIEW payment. On a PROVISIONING_FAILED payment
     * this retries fulfilment (the payment was already verified).
     */
    #[Route('/{id}/confirm', name: 'admin_payment_confirm', methods: ['GET'])]
    public function confirm(Payment $payment, PaymentSettlementService $settlement): RedirectResponse
    {
        $adminId = $this->currentAdminId();
        $result = PaymentStatus::PROVISIONING_FAILED === $payment->getStatus()
            ? $settlement->retryProvisioning($payment, $adminId, null, 'easyadmin_retry_provisioning')
            : $settlement->approveByAdmin($payment, $adminId, null, 'easyadmin_payment_approval');

        return $this->backToList($result);
    }

    #[Route('/{id}/reject', name: 'admin_payment_reject', methods: ['GET'])]
    public function reject(Payment $payment, Request $request, PaymentSettlementService $settlement): RedirectResponse
    {
        $note = $request->request->get('note');
        $result = $settlement->reject($payment, is_string($note) ? $note : null, $this->currentAdminId(), null, 'easyadmin_payment_reject');

        return $this->backToList($result);
    }

    private function currentAdminId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof AdminUser ? $user->getId() : null;
    }

    private function backToList(SettlementResult $result): RedirectResponse
    {
        $this->addFlash(match ($result->outcome) {
            SettlementOutcome::CONFIRMED => 'success',
            SettlementOutcome::REJECTED, SettlementOutcome::NEEDS_REVIEW => 'warning',
            SettlementOutcome::ALREADY_PROCESSED => 'info',
            default => 'danger',
        }, $result->message);

        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => \App\Admin\UI\Crud\PaymentCrudController::class,
        ]);
    }
}
