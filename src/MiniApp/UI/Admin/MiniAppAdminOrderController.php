<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin orders in the Mini App, read only: the web panel's only order actions are its generic record editor and
 * delete (ORDERS_MANAGE), which this release does not bring to the app. List with a status filter and a search by
 * tracking code or number; detail with the customer, the target service and the order's payments.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminLists $lists,
        private readonly AdminRouteAccess $access,
        private readonly MiniAppFragments $fragments,
    ) {
    }

    #[Route('/app/admin/orders', name: 'miniapp_admin_orders', defaults: ['_miniapp_section' => 'admin.orders'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $status = $request->query->getString('status');
        $status = in_array($status, OrderStatus::ALL, true) ? $status : null;
        $query = AdminQuery::text($request);

        return $this->fragments->render($request, 'miniapp/admin/orders.html.twig', [
            'status' => $status,
            'statuses' => OrderStatus::ALL,
            'query' => $query,
            'list' => $this->lists->orders($status, $query, AdminQuery::page($request)),
        ]);
    }

    #[Route('/app/admin/orders/{id}', name: 'miniapp_admin_order', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.orders'], methods: ['GET'])]
    public function detail(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $order = $this->entityManager->find(Order::class, $id);
        if (!$order instanceof Order) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/admin/order.html.twig', [
            'order' => $order,
            'customer' => AdminCustomerView::summary($order->getUser()),
            'payments' => $this->entityManager->getRepository(Payment::class)->findBy(['order' => $order], ['id' => 'DESC']),
            'can' => $this->access->map($user, ['miniapp_admin_customer', 'miniapp_admin_service', 'miniapp_admin_payment']),
        ]);
    }
}
