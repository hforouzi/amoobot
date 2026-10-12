<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\PaymentModerationController;
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
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class PaymentCrudController extends AbstractCrudController
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

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
        // POST forms straight to PaymentModerationController (not via the dashboard router, which would
        // drop the query string) carrying a per-payment CSRF token.
        $confirm = Action::new('confirmPayment', 'Approve')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_confirm', $this->routeParameters($payment)))
            ->renderAsForm()
            ->setCssClass('btn btn-success')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW], true));

        $retry = Action::new('retryProvisioning', 'Retry provisioning')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_retry', $this->routeParameters($payment)))
            ->renderAsForm()
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (Payment $payment): bool => PaymentStatus::PROVISIONING_FAILED === $payment->getStatus());

        $reject = Action::new('rejectPayment', 'Reject')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_reject', $this->routeParameters($payment)))
            ->renderAsForm()
            ->setCssClass('btn btn-warning')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true));

        foreach ([$confirm, $retry, $reject] as $action) {
            $actions->add(Action::INDEX, $action)->add(Action::DETAIL, $action);
        }

        return $actions;
    }

    /**
     * @return array{id: int|null, _token: string}
     */
    private function routeParameters(Payment $payment): array
    {
        return [
            'id' => $payment->getId(),
            '_token' => $this->csrfTokenManager->getToken(PaymentModerationController::CSRF_PREFIX.$payment->getId())->getValue(),
        ];
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
            // Read-only: only PaymentSettlementService / the moderation actions may change a payment's status.
            TextField::new('status')
                ->formatValue(static fn (mixed $value): string => AdminStatusBadge::html($value))
                ->renderAsHtml()
                ->hideOnForm(),
            TextField::new('amountFlag')->setLabel('Amount flag')->hideOnForm(),
            TextareaField::new('reviewReason')->setLabel('Review reason')->hideOnIndex()->hideOnForm(),
            TextareaField::new('provisioningError')->setLabel('Provisioning error')->hideOnIndex()->hideOnForm(),
            IntegerField::new('provisioningAttempts')->setLabel('Provisioning attempts')->hideOnIndex()->hideOnForm(),
            DateTimeField::new('provisioningFailedAt')->setLabel('Provisioning failed at')->hideOnIndex()->hideOnForm(),
            TextField::new('expectedAmount')->setLabel('Expected amount')->hideOnIndex()->hideOnForm(),
            TextField::new('expectedCurrency')->setLabel('Expected currency')->hideOnIndex()->hideOnForm(),
            TextField::new('verifiedAmount')->setLabel('Verified amount')->hideOnIndex()->hideOnForm(),
            TextField::new('verifiedCurrency')->setLabel('Verified currency')->hideOnIndex()->hideOnForm(),
            TextField::new('confirmationSource')->setLabel('Confirmation source')->hideOnIndex()->hideOnForm(),
            IntegerField::new('confirmedByAdminId')->setLabel('Confirmed by admin #')->hideOnIndex()->hideOnForm(),
            TextField::new('confirmedByTelegramId')->setLabel('Confirmed by Telegram ID')->hideOnIndex()->hideOnForm(),
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
