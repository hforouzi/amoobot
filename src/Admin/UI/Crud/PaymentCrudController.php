<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\PaymentModerationController;
use App\Admin\UI\Support\AdminFormat;
use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Payments, one list with status tabs (?tab=...): the tabs replace the separate "needs review" and "provisioning
 * failed" lists (their old URLs redirect here, LegacyAdminRoutes). Each tab shows how many payments it holds.
 */
final class PaymentCrudController extends AbstractCrudController
{
    /** Tab => the statuses it lists ("all": no filter). */
    public const TABS = [
        'all' => [],
        'pending' => [PaymentStatus::PENDING, PaymentStatus::SUBMITTED],
        'needs_review' => [PaymentStatus::NEEDS_REVIEW],
        'provisioning_failed' => [PaymentStatus::PROVISIONING_FAILED],
        'confirmed' => [PaymentStatus::CONFIRMED],
        'rejected' => [PaymentStatus::REJECTED],
    ];

    /** Tabs an admin works through: oldest first (as the old "needs attention" lists). */
    private const OLDEST_FIRST = ['needs_review', 'provisioning_failed'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminFormat $format,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['id' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/crud/index_tabs.html.twig');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            // The list's customer, order and method columns, in the same query (no query per row).
            ->leftJoin('entity.order', 'listOrder')->addSelect('listOrder')
            ->leftJoin('listOrder.user', 'listUser')->addSelect('listUser')
            ->leftJoin('entity.storePaymentMethod', 'listMethod')->addSelect('listMethod')
            ->leftJoin('entity.gateway', 'listGateway')->addSelect('listGateway');
        $tab = $this->currentTab();
        if ([] !== self::TABS[$tab]) {
            $qb->andWhere('entity.status IN (:tabStatuses)')->setParameter('tabStatuses', self::TABS[$tab]);
        }
        if (in_array($tab, self::OLDEST_FIRST, true) && [] === $searchDto->getCustomSort()) {
            $qb->orderBy('entity.id', 'ASC');
        }

        return $qb;
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $responseParameters->set('admin_tabs', $this->tabs());
        }

        return $responseParameters;
    }

    /**
     * Payments per status tab, for the tabs and the dashboard cards.
     *
     * @return array<string, int>
     */
    public static function countByTab(EntityManagerInterface $entityManager): array
    {
        $byStatus = [];
        foreach ($entityManager->createQueryBuilder()->select('p.status AS status', 'COUNT(p.id) AS total')->from(Payment::class, 'p')->groupBy('p.status')->getQuery()->getArrayResult() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['total'];
        }

        $counts = [];
        foreach (self::TABS as $tab => $statuses) {
            $counts[$tab] = [] === $statuses ? array_sum($byStatus) : array_sum(array_map(static fn (string $status): int => $byStatus[$status] ?? 0, $statuses));
        }

        return $counts;
    }

    /**
     * @return list<array{key: string, label: string, url: string, active: bool, count: int}>
     */
    private function tabs(): array
    {
        $counts = self::countByTab($this->entityManager);
        $active = $this->currentTab();
        $tabs = [];
        foreach (array_keys(self::TABS) as $tab) {
            $tabs[] = [
                'key' => $tab,
                'label' => 'payment.tabs.'.$tab,
                'url' => $this->generateUrl('admin_payment_index', 'all' === $tab ? [] : ['tab' => $tab]),
                'active' => $tab === $active,
                'count' => $counts[$tab],
            ];
        }

        return $tabs;
    }

    private function currentTab(): string
    {
        $tab = (string) $this->requestStack->getCurrentRequest()?->query->get('tab', 'all');

        return array_key_exists($tab, self::TABS) ? $tab : 'all';
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
        $confirm = Action::new('confirmPayment', 'action.confirm_payment')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_confirm', $this->routeParameters($payment)))
            ->renderAsForm()
            ->setCssClass('btn btn-success')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW], true));

        $retry = Action::new('retryProvisioning', 'action.retry_provisioning')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_retry', $this->routeParameters($payment)))
            ->renderAsForm()
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (Payment $payment): bool => PaymentStatus::PROVISIONING_FAILED === $payment->getStatus());

        $reject = Action::new('rejectPayment', 'action.reject_payment')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_reject', $this->routeParameters($payment)))
            ->renderAsForm()
            ->setCssClass('btn btn-warning')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true));

        // Closing a duplicate (or any reviewed payment) whose money was sent back to the customer.
        $rejectRefunded = Action::new('rejectPaymentRefunded', 'action.reject_payment_refunded')
            ->linkToUrl(fn (Payment $payment): string => $this->generateUrl('admin_payment_reject', $this->routeParameters($payment) + ['refunded' => 1]))
            ->renderAsForm()
            ->setCssClass('btn btn-secondary')
            ->displayIf(fn (Payment $payment): bool => in_array($payment->getStatus(), [PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true));

        foreach ([$confirm, $retry, $reject, $rejectRefunded] as $action) {
            $actions->add(Action::INDEX, $action)->add(Action::DETAIL, $action)
                ->setPermission($action->getAsDto()->getName(), AdminPermission::PAYMENTS_MODERATE->value);
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

    /**
     * List: customer, order, method, amount (store currency), status, when. Gateway details, amounts as verified,
     * review/provisioning data, receipts and crypto data are on the detail page.
     */
    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;

        return [
            IdField::new('id')->onlyOnIndex(),
            AdminFormat::field('customer', static fn (mixed $v, ?Payment $p): string => null === $p ? '' : AdminFormat::e((string) $p->getOrder()->getUser()), 'field.customer')->onlyOnIndex(),
            AssociationField::new('order'),
            AdminFormat::field('orderType', fn (mixed $v, ?Payment $p): string => null === $p ? '' : AdminFormat::e($this->translator->trans('order.type.'.$p->getOrder()->getType(), [], 'admin')), 'field.order_type')->hideOnIndex(),
            // The method the customer chose (its store title), else the gateway's title, else the raw method code.
            AdminFormat::field('paidWith', static fn (mixed $v, ?Payment $p): string => AdminFormat::e((string) ($p?->getStorePaymentMethod()?->getTitle() ?? $p?->getGateway()?->getTitle() ?? $p?->getMethod() ?? '')) ?: AdminFormat::NONE, 'field.method')->onlyOnIndex(),
            IntegerField::new('amount')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->money($v)),
            // Read-only: only PaymentSettlementService / the moderation actions may change a payment's status.
            TextField::new('status')->hideOnForm()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->status($v)),
            DateTimeField::new('createdAt')->hideOnForm(),

            // Detail page and form.
            AssociationField::new('storePaymentMethod')->hideOnForm()->hideOnIndex(),
            AssociationField::new('gateway')->hideOnIndex(),
            TextField::new('method')->hideOnIndex(),
            TextField::new('gatewayType')->hideOnIndex(),
            IntegerField::new('payableAmount')->hideOnIndex()->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => $f->money($v)),
            TextField::new('currency')->hideOnIndex(),
            TextField::new('amountFlag')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('refundedAt')->hideOnIndex()->hideOnForm(),
            TextareaField::new('reviewReason')->hideOnIndex()->hideOnForm(),
            TextareaField::new('provisioningError')->hideOnIndex()->hideOnForm(),
            IntegerField::new('provisioningAttempts')->hideOnIndex()->hideOnForm(),
            DateTimeField::new('provisioningFailedAt')->hideOnIndex()->hideOnForm(),
            TextField::new('expectedAmount')->hideOnIndex()->hideOnForm(),
            TextField::new('expectedCurrency')->hideOnIndex()->hideOnForm(),
            TextField::new('verifiedAmount')->hideOnIndex()->hideOnForm(),
            TextField::new('verifiedCurrency')->hideOnIndex()->hideOnForm(),
            TextField::new('confirmationSource')->hideOnIndex()->hideOnForm(),
            IntegerField::new('confirmedByAdminId')->hideOnIndex()->hideOnForm(),
            TextField::new('confirmedByTelegramId')->hideOnIndex()->hideOnForm(),
            TextField::new('gatewayTransactionId')->hideOnIndex(),
            TextField::new('authority')->hideOnIndex(),
            TextField::new('paymentUrl')->hideOnIndex(),
            DateTimeField::new('verifiedAt')->hideOnIndex(),
            DateTimeField::new('failedAt')->hideOnIndex(),
            TextField::new('trackingCode')->hideOnIndex(),
            TextField::new('receiptFileId')->hideOnIndex(),
            TextareaField::new('receiptMessage')->hideOnIndex(),
            TextareaField::new('adminNote')->hideOnIndex(),
            DateTimeField::new('submittedAt')->hideOnIndex(),
            DateTimeField::new('confirmedAt')->hideOnIndex(),
            // Crypto fields
            TextField::new('cryptoPaymentId')->hideOnIndex(),
            TextField::new('cryptoPaymentStatus')->hideOnIndex(),
            TextField::new('cryptoPayAmount')->hideOnIndex(),
            TextField::new('cryptoPayCurrency')->hideOnIndex(),
            TextField::new('cryptoAddress')->hideOnIndex(),
            TextField::new('cryptoPriceCurrency')->hideOnIndex(),
            TextField::new('cryptoPurchaseId')->hideOnIndex(),
            DateTimeField::new('cryptoExpiresAt')->hideOnIndex(),
        ];
    }
}
