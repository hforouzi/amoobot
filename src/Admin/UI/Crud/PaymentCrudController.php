<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class PaymentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('order'))
            ->add(ChoiceFilter::new('status')->setChoices([
                PaymentStatus::PENDING => PaymentStatus::PENDING,
                PaymentStatus::SUBMITTED => PaymentStatus::SUBMITTED,
                PaymentStatus::CONFIRMED => PaymentStatus::CONFIRMED,
                PaymentStatus::REJECTED => PaymentStatus::REJECTED,
            ]));
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
            TextField::new('order.type', 'orderType')->hideOnForm(),
            AssociationField::new('storePaymentMethod')->hideOnForm(),
            AssociationField::new('gateway'),
            TextField::new('method'),
            TextField::new('gatewayType'),
            IntegerField::new('amount'),
            IntegerField::new('payableAmount'),
            TextField::new('currency'),
            TextField::new('status'),
            TextField::new('gatewayTransactionId')->hideOnIndex(),
            TextField::new('authority')->hideOnIndex(),
            TextField::new('paymentUrl')->hideOnIndex(),
            DateTimeField::new('verifiedAt')->hideOnIndex(),
            DateTimeField::new('failedAt')->hideOnIndex(),
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
