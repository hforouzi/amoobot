<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\Entity\Order;
use App\Entity\VpnService;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Checkout\PaymentProgress;
use App\MiniApp\Security\MiniAppUser;
use App\Payment\Domain\PaymentDisplayState;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\Checkout\OrderLifecycle;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\ReceiptSubmission;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer's orders in the Mini App ("My orders", paged) and paying one: choosing a payment method, then the
 * payment's own page (card details and receipt
 * upload, or the gateway to open), which polls the payment status until a final state. Read only: the choices are
 * sent to MiniAppCheckoutController. Every id is checked against the signed-in customer (404 otherwise).
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppOrderController extends AbstractController
{
    private const PAGE_SIZE = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MiniAppFragments $fragments,
        private readonly MiniAppCheckout $checkout,
        private readonly PaymentStarter $paymentStarter,
        private readonly PaymentProgress $progress,
        private readonly OrderLifecycle $orderLifecycle,
        private readonly WalletPayment $walletPayment,
        private readonly WalletService $wallets,
    ) {
    }

    #[Route('/app/view/orders/{id}/pay', name: 'miniapp_order_pay', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]
    public function pay(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        try {
            $order = $this->checkout->payableOrder($user->customer(), $id);
        } catch (CheckoutFailure) {
            throw new NotFoundHttpException();
        }
        $payment = $this->orderLifecycle->latestPayment($order);

        return $this->fragments->render($request, 'miniapp/view/order_pay.html.twig', [
            'order' => $order,
            'summary' => self::summary($order),
            'methods' => $this->paymentStarter->methodsFor($order),
            'walletOffer' => $this->walletPayment->canPay($order) ? [
                'amount' => $this->paymentStarter->payableAmount($order),
                'available' => $this->wallets->find($order->getUser())?->getAvailable() ?? 0,
            ] : null,
            'openPayment' => null !== $payment && PaymentStatus::PENDING === $payment->getStatus() && null !== $payment->getGateway() ? $payment : null,
        ]);
    }

    #[Route('/app/view/payments/{id}', name: 'miniapp_payment', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]
    public function payment(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $payment = $this->progress->ownedPayment($user->customer(), $id) ?? throw new NotFoundHttpException();
        $state = $this->progress->state($payment);
        $gateway = $payment->getGateway();
        $order = $payment->getOrder();

        return $this->fragments->render($request, 'miniapp/view/payment.html.twig', [
            'payment' => $payment,
            'order' => $order,
            'summary' => self::summary($order),
            'state' => $state,
            'final' => PaymentDisplayState::isFinal($state),
            'kind' => null !== $gateway ? PaymentStarter::kindOf($gateway) : ($payment->isWalletPayment() ? 'wallet' : 'manual_card'),
            'card' => ReceiptSubmission::isManual($payment) ? $this->paymentStarter->manualCard($gateway) : null,
            'canUploadReceipt' => ReceiptSubmission::isManual($payment) && PaymentStatus::PENDING === $payment->getStatus(),
            'service' => $this->progress->service($payment),
            'canCancel' => PaymentDisplayState::PENDING === $state && $this->orderLifecycle->canCancel($order, $payment),
        ]);
    }

    #[Route('/app/view/orders', name: 'miniapp_orders', defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]
    public function orders(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $page = max(1, min(1000, $request->query->getInt('page', 1)));
        /** @var list<Order> $orders */
        $orders = $this->entityManager->getRepository(Order::class)->findBy(['user' => $user->customer()], ['id' => 'DESC'], self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);

        return $this->fragments->render($request, 'miniapp/view/orders.html.twig', [
            'orders' => array_slice($orders, 0, self::PAGE_SIZE),
            'page' => $page,
            'hasNext' => count($orders) > self::PAGE_SIZE,
        ]);
    }

    #[Route('/app/view/orders/{id}', name: 'miniapp_order', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]
    public function order(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $order = $this->orderLifecycle->ownedOrder($user->customer(), $id) ?? throw new NotFoundHttpException();
        $payment = $this->orderLifecycle->latestPayment($order);
        $service = in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)
            ? $order->getTargetService()
            : $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order], ['id' => 'DESC']);
        $payable = OrderStatus::WAITING_PAYMENT === $order->getStatus() && $this->orderLifecycle->canEditForPayment($order)
            && (null === $payment || PaymentStatus::PENDING !== $payment->getStatus() || null === $payment->getGateway());

        return $this->fragments->render($request, 'miniapp/view/order.html.twig', [
            'order' => $order,
            'summary' => self::summary($order),
            'payment' => $payment,
            'paymentState' => null !== $payment ? $this->progress->state($payment) : null,
            'service' => $service instanceof VpnService && VpnServiceStatus::DELETED !== $service->getStatus() ? $service : null,
            'payable' => $payable,
        ]);
    }

    /**
     * What the order is, from its own metadata (never recomputed): for the summaries.
     *
     * @return array{type: string, plan: ?string, trafficGb: ?int, durationDays: ?int, unlimitedDuration: bool, baseAmount: int, globalDiscountAmount: int, discountCode: ?string, discountAmount: int, finalAmount: int, serviceId: ?int}
     */
    public static function summary(Order $order): array
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $snapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        // A wallet top-up has no plan: no plan, traffic or duration lines.
        $plan = $order->getPlan();
        $trafficGb = $metadata['trafficGb'] ?? (OrderType::ADD_TRAFFIC === $order->getType() ? null : $plan?->getTrafficGb());
        $durationDays = OrderType::ADD_TRAFFIC === $order->getType() ? null : ($metadata['durationDays'] ?? $plan?->getDurationDays());

        return [
            'type' => $order->getType(),
            'plan' => $plan?->getTitle(),
            'trafficGb' => is_numeric($trafficGb) ? (int) $trafficGb : null,
            'durationDays' => is_numeric($durationDays) ? (int) $durationDays : null,
            'unlimitedDuration' => true === ($metadata['unlimitedDuration'] ?? false) || (OrderType::NEW_SERVICE === $order->getType() && true === $plan?->isUnlimitedDuration()),
            'baseAmount' => (int) ($snapshot['baseAmount'] ?? $order->getAmount()),
            'globalDiscountAmount' => (int) ($snapshot['globalDiscountAmount'] ?? 0),
            'discountCode' => isset($snapshot['discountCode']) && '' !== (string) $snapshot['discountCode'] ? (string) $snapshot['discountCode'] : null,
            'discountAmount' => (int) ($snapshot['discountCodeAmount'] ?? 0),
            'finalAmount' => $order->getAmount(),
            'serviceId' => $order->getTargetService()?->getId(),
        ];
    }
}
