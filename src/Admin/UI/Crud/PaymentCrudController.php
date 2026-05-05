<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PaymentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $confirm = Action::new('confirmPayment', 'Confirm Payment')
            ->linkToRoute('admin_payment_confirm', fn (Payment $payment): array => ['id' => $payment->getId()])
            ->setCssClass('btn btn-success')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED], true));

        $reject = Action::new('rejectPayment', 'Reject Payment')
            ->linkToRoute('admin_payment_reject', fn (Payment $payment): array => ['id' => $payment->getId()])
            ->setCssClass('btn btn-warning')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED], true));

        return $actions
            ->add(Action::INDEX, $confirm)
            ->add(Action::DETAIL, $confirm)
            ->add(Action::INDEX, $reject)
            ->add(Action::DETAIL, $reject);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('order'),
            TextField::new('method'),
            IntegerField::new('amount'),
            TextField::new('status'),
            TextField::new('trackingCode'),
            TextField::new('receiptFileId'),
            TextareaField::new('receiptMessage')->hideOnIndex(),
            TextareaField::new('adminNote')->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('submittedAt'),
            DateTimeField::new('confirmedAt'),
        ];
    }
}
