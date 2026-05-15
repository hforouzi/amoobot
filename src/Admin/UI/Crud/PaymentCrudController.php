<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
            ->add(ChoiceFilter::new('status')->setChoices(AdminStatusBadge::choices(PaymentStatus::ALL)));
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
            AssociationField::new('order')->setLabel('admin.orders'),
            TextField::new('order.type', 'orderType')->hideOnForm(),
            AssociationField::new('storePaymentMethod')->hideOnForm(),
            AssociationField::new('gateway')->setLabel('admin.payment_gateways'),
            TextField::new('method')->setLabel('Method'),
            TextField::new('gatewayType')->setLabel('Gateway Type'),
            IntegerField::new('amount')->setLabel('Amount'),
            IntegerField::new('payableAmount')->setLabel('Payable Amount'),
            TextField::new('currency')->setLabel('Currency'),
            TextField::new('status')
                ->formatValue(static fn (mixed $value): string => AdminStatusBadge::html($value))
                ->renderAsHtml(),
            TextField::new('gatewayTransactionId')->hideOnIndex(),
            TextField::new('authority')->hideOnIndex(),
            TextField::new('paymentUrl')->hideOnIndex(),
            DateTimeField::new('verifiedAt')->hideOnIndex(),
            DateTimeField::new('failedAt')->hideOnIndex(),
            TextField::new('trackingCode'),
            TextField::new('receiptFileId'),
            TextareaField::new('receiptMessage')->hideOnIndex(),
            TextareaField::new('adminNote')->hideOnIndex(),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('submittedAt'),
            DateTimeField::new('confirmedAt'),
            // Crypto fields
            TextField::new('cryptoPaymentId')->hideOnIndex()->setLabel('Crypto Payment ID'),
            TextField::new('cryptoPaymentStatus')->hideOnIndex()->setLabel('Crypto Status'),
            TextField::new('cryptoPayAmount')->hideOnIndex()->setLabel('Crypto Pay Amount'),
            TextField::new('cryptoPayCurrency')->hideOnIndex()->setLabel('Crypto Pay Currency'),
            TextField::new('cryptoAddress')->hideOnIndex()->setLabel('Crypto Address'),
            TextField::new('cryptoPriceCurrency')->hideOnIndex()->setLabel('Crypto Price Currency'),
            TextField::new('cryptoPurchaseId')->hideOnIndex()->setLabel('Crypto Purchase ID'),
            DateTimeField::new('cryptoExpiresAt')->hideOnIndex()->setLabel('Crypto Expires At'),
        ];
    }
}
