<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\VpnService;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin customers in the Mini App, read only (CUSTOMERS_VIEW; the web panel has no customer actions beyond editing
 * the record): the newest customers or the bot's search (Telegram id, @username, name, mobile), and a customer's
 * detail with their Telegram info, services, latest orders and a payments summary. Links to services, orders and
 * payments appear only for admins who may open them.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminCustomerController extends AbstractController
{
    private const RECENT = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminLists $lists,
        private readonly AdminRouteAccess $access,
        private readonly MiniAppFragments $fragments,
    ) {
    }

    #[Route('/app/admin/customers', name: 'miniapp_admin_customers', defaults: ['_miniapp_section' => 'admin.customers'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $query = AdminQuery::text($request);
        $tooShort = null !== $query && mb_strlen($query) < 2;

        return $this->fragments->render($request, 'miniapp/admin/customers.html.twig', [
            'query' => $query,
            'tooShort' => $tooShort,
            'list' => $this->lists->customers($tooShort ? null : $query, AdminQuery::page($request)),
        ]);
    }

    #[Route('/app/admin/customers/{id}', name: 'miniapp_admin_customer', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.customers'], methods: ['GET'])]
    public function detail(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $customer = $this->entityManager->find(User::class, $id);
        if (!$customer instanceof User) {
            throw new NotFoundHttpException();
        }

        $payments = $this->entityManager->createQueryBuilder()
            ->select('p.status AS status', 'COUNT(p.id) AS total', 'COALESCE(SUM(p.amount), 0) AS amount')
            ->from(Payment::class, 'p')->join('p.order', 'o')
            ->where('o.user = :user')->setParameter('user', $customer)
            ->groupBy('p.status')->getQuery()->getArrayResult();
        $summary = ['count' => 0, 'confirmed' => 0, 'confirmedAmount' => 0, 'open' => 0];
        foreach ($payments as $row) {
            $summary['count'] += (int) $row['total'];
            if (PaymentStatus::CONFIRMED === $row['status']) {
                $summary['confirmed'] = (int) $row['total'];
                $summary['confirmedAmount'] = (int) $row['amount'];
            } elseif (in_array($row['status'], [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true)) {
                $summary['open'] += (int) $row['total'];
            }
        }

        return $this->fragments->render($request, 'miniapp/admin/customer.html.twig', [
            'c' => AdminCustomerView::summary($customer),
            'createdAt' => $customer->getCreatedAt(),
            'services' => $this->entityManager->getRepository(VpnService::class)->createQueryBuilder('s')
                ->where('s.user = :user')->andWhere('s.status != :deleted')
                ->setParameter('user', $customer)->setParameter('deleted', VpnServiceStatus::DELETED)
                ->orderBy('s.id', 'DESC')->getQuery()->getResult(),
            'orders' => $this->entityManager->getRepository(Order::class)->findBy(['user' => $customer], ['id' => 'DESC'], self::RECENT),
            'payments' => $summary,
            'can' => $this->access->map($user, ['miniapp_admin_service', 'miniapp_admin_order', 'miniapp_admin_payments']),
        ]);
    }
}
