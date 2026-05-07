<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\Payment;
use App\Payment\Application\PaymentConfirmationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/payment')]
class PaymentModerationController extends AbstractController
{
    #[Route('/{id}/confirm', name: 'admin_payment_confirm', methods: ['GET'])]
    public function confirm(Payment $payment, PaymentConfirmationService $confirmationService): RedirectResponse
    {
        $result = $confirmationService->confirm($payment);
        if ($result->alreadyProcessed) {
            $this->addFlash('info', $result->message);
        } elseif ($result->processed) {
            $this->addFlash('success', $result->message);
        } else {
            $this->addFlash('danger', $result->message);
        }

        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => \App\Admin\UI\Crud\PaymentCrudController::class,
        ]);
    }

    #[Route('/{id}/reject', name: 'admin_payment_reject', methods: ['GET'])]
    public function reject(Payment $payment, Request $request, PaymentConfirmationService $confirmationService): RedirectResponse
    {
        $note = $request->request->get('note');
        $result = $confirmationService->reject($payment, is_string($note) ? $note : null);
        if ($result->alreadyProcessed) {
            $this->addFlash('info', $result->message);
        } elseif ($result->processed) {
            $this->addFlash('warning', $result->message);
        } else {
            $this->addFlash('danger', $result->message);
        }

        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => \App\Admin\UI\Crud\PaymentCrudController::class,
        ]);
    }
}
