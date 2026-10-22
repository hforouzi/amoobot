<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\Application\PaymentTabs;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppHeaders;
use App\MiniApp\UI\MiniAppLocale;
use App\Payment\Application\PaymentModeration;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin payments in the Mini App: the list with the web panel's status tabs (PaymentTabs) and a tracking-code search,
 * a payment's detail (amounts, order, customer, method, history, the card-to-card receipt through an authenticated
 * endpoint) and the moderation actions, run by the same PaymentModeration as the web panel and the bot. Permissions:
 * AdminRoutePermissions, enforced by MiniAppAdminGuard; a button is rendered only when its route is allowed.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminPaymentController extends AbstractController
{
    public const ACTIONS = ['miniapp_admin_payment_approve', 'miniapp_admin_payment_retry', 'miniapp_admin_payment_reject', 'miniapp_admin_payment_reject_refunded'];
    /** A receipt is a photo the customer sent; anything larger is not served. */
    private const RECEIPT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminLists $lists,
        private readonly AdminRouteAccess $access,
        private readonly AdminActions $actions,
        private readonly PaymentModeration $moderation,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
        private readonly TelegramApiClient $telegram,
    ) {
    }

    #[Route('/app/admin/payments', name: 'miniapp_admin_payments', defaults: ['_miniapp_section' => 'admin.payments'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $tab = PaymentTabs::normalize($request->query->getString('tab', 'all'));
        $query = AdminQuery::text($request);

        return $this->fragments->render($request, 'miniapp/admin/payments.html.twig', [
            'tab' => $tab,
            'tabs' => array_keys(PaymentTabs::TABS),
            'counts' => PaymentTabs::countByTab($this->entityManager),
            'query' => $query,
            'list' => $this->lists->payments($tab, $query, AdminQuery::page($request)),
        ]);
    }

    #[Route('/app/admin/payments/{id}', name: 'miniapp_admin_payment', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['GET'])]
    public function detail(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $payment = $this->payment($id);

        return $this->fragments->render($request, 'miniapp/admin/payment.html.twig', [
            'p' => self::view($payment),
            'can' => $this->access->map($user, [...self::ACTIONS, 'miniapp_admin_order', 'miniapp_admin_customer']),
            'actions' => self::availableActions($payment->getStatus()),
        ]);
    }

    /**
     * The card-to-card receipt photo, fetched from Telegram by the server and streamed to an admin with PAYMENTS_VIEW;
     * never a public or Telegram URL (that one would carry the bot token). The app loads it with the session token.
     */
    #[Route('/app/api/admin/payments/{id}/receipt', name: 'miniapp_admin_payment_receipt', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['GET'])]
    public function receipt(int $id): Response
    {
        $fileId = $this->payment($id)->getReceiptFileId();
        $file = null === $fileId || '' === $fileId ? null : $this->telegram->downloadFile($fileId, self::RECEIPT_MAX_BYTES);
        $type = null === $file ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($file);
        if (null === $file || !in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new NotFoundHttpException();
        }

        return MiniAppHeaders::apply(new Response($file, Response::HTTP_OK, ['Content-Type' => $type, 'Content-Disposition' => 'inline']));
    }

    #[Route('/app/api/admin/payments/{id}/approve', name: 'miniapp_admin_payment_approve', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]
    public function approve(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->moderate($request, $user, $id, 'approve', fn (Payment $p): SettlementResult => $this->moderation->approve($p, $user->admin, $user->telegramUserId(), PaymentModeration::CHANNEL_MINIAPP));
    }

    #[Route('/app/api/admin/payments/{id}/retry', name: 'miniapp_admin_payment_retry', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]
    public function retry(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->moderate($request, $user, $id, 'retry', fn (Payment $p): SettlementResult => $this->moderation->retry($p, $user->admin, $user->telegramUserId(), PaymentModeration::CHANNEL_MINIAPP));
    }

    #[Route('/app/api/admin/payments/{id}/reject', name: 'miniapp_admin_payment_reject', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]
    public function reject(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->moderate($request, $user, $id, 'reject', fn (Payment $p): SettlementResult => $this->moderation->reject($p, null, false, $user->admin, $user->telegramUserId(), PaymentModeration::CHANNEL_MINIAPP));
    }

    #[Route('/app/api/admin/payments/{id}/reject-refunded', name: 'miniapp_admin_payment_reject_refunded', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]
    public function rejectRefunded(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->moderate($request, $user, $id, 'reject_refunded', fn (Payment $p): SettlementResult => $this->moderation->reject($p, null, true, $user->admin, $user->telegramUserId(), PaymentModeration::CHANNEL_MINIAPP));
    }

    /**
     * The actions the web panel offers on a payment in this status (PaymentCrudController::configureActions).
     *
     * @return list<string>
     */
    public static function availableActions(string $status): array
    {
        $open = [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW];

        return array_values(array_filter([
            in_array($status, $open, true) ? 'miniapp_admin_payment_approve' : null,
            PaymentStatus::PROVISIONING_FAILED === $status ? 'miniapp_admin_payment_retry' : null,
            in_array($status, [...$open, PaymentStatus::PROVISIONING_FAILED], true) ? 'miniapp_admin_payment_reject' : null,
            in_array($status, [PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true) ? 'miniapp_admin_payment_reject_refunded' : null,
        ]));
    }

    /**
     * @param callable(Payment): SettlementResult $decide
     */
    private function moderate(Request $request, MiniAppUser $user, int $id, string $action, callable $decide): JsonResponse
    {
        $payment = $this->payment($id);
        $locale = MiniAppLocale::fromRequest($request);
        $confirm = $this->translator->trans('admin.payments.confirm.'.$action, [
            '%amount%' => number_format($payment->getAmount()),
            '%tracking%' => (string) ($payment->getOrder()->getTrackingCode() ?? '#'.$id),
        ], 'miniapp', $locale);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($payment, $decide, $locale): array {
            $result = $decide($payment);
            $done = in_array($result->outcome, [SettlementOutcome::CONFIRMED, SettlementOutcome::REJECTED], true);

            return [
                'outcome' => $result->outcome->value,
                'message' => $this->translator->trans('admin.payments.outcome.'.$result->outcome->value, [], 'miniapp', $locale),
                // The settlement's own text can carry a review reason or a panel / gateway error: masked.
                'detail' => $done ? null : AdminText::safe($result->message),
                'status' => match ($result->outcome) {
                    SettlementOutcome::CONFIRMED, SettlementOutcome::REJECTED => Response::HTTP_OK,
                    SettlementOutcome::ERROR => Response::HTTP_SERVICE_UNAVAILABLE,
                    SettlementOutcome::PROVISIONING_FAILED => Response::HTTP_BAD_GATEWAY,
                    default => Response::HTTP_CONFLICT,
                },
            ];
        });
    }

    private function payment(int $id): Payment
    {
        $payment = $this->entityManager->find(Payment::class, $id);

        return $payment instanceof Payment ? $payment : throw new NotFoundHttpException();
    }

    /**
     * What the detail page shows, and nothing else: no gateway configuration, payloads, payment URLs or tokens.
     *
     * @return array<string, mixed>
     */
    public static function view(Payment $payment): array
    {
        $order = $payment->getOrder();
        $events = array_filter([
            'created' => $payment->getCreatedAt(),
            'submitted' => $payment->getSubmittedAt(),
            'verified' => $payment->getVerifiedAt(),
            'failed' => $payment->getFailedAt(),
            'provisioning_failed' => $payment->getProvisioningFailedAt(),
            'confirmed' => $payment->getConfirmedAt(),
            'refunded' => $payment->getRefundedAt(),
        ]);
        asort($events);

        return [
            'id' => (int) $payment->getId(),
            'status' => $payment->getStatus(),
            'amount' => $payment->getAmount(),
            'payable' => $payment->getPayableAmount(),
            'expected' => null === $payment->getExpectedAmount() ? null : trim($payment->getExpectedAmount().' '.$payment->getExpectedCurrency()),
            'verified' => null === $payment->getVerifiedAmount() ? null : trim($payment->getVerifiedAmount().' '.$payment->getVerifiedCurrency()),
            'amountFlag' => $payment->getAmountFlag(),
            'reviewReason' => AdminText::safe($payment->getReviewReason()),
            'provisioningError' => AdminText::safe($payment->getProvisioningError()),
            'attempts' => $payment->getProvisioningAttempts(),
            'method' => $payment->getStorePaymentMethod()?->getTitle() ?? $payment->getGateway()?->getTitle(),
            'gatewayType' => $payment->getGatewayType() ?? $payment->getGateway()?->getType(),
            'transaction' => $payment->getGatewayTransactionId(),
            'confirmationSource' => $payment->getConfirmationSource(),
            'hasReceipt' => null !== $payment->getReceiptFileId() && '' !== $payment->getReceiptFileId(),
            'receiptMessage' => $payment->getReceiptMessage(),
            'adminNote' => $payment->getAdminNote(),
            'events' => $events,
            'order' => [
                'id' => (int) $order->getId(),
                'type' => $order->getType(),
                'status' => $order->getStatus(),
                'tracking' => $order->getTrackingCode(),
                'plan' => $order->getPlan()->getTitle(),
                'amount' => $order->getAmount(),
            ],
            'customer' => AdminCustomerView::summary($order->getUser()),
        ];
    }
}
