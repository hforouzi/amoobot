<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Reseller\Application\ResellerAdministration;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerPricing;
use App\Reseller\Application\ResellerRefunds;
use App\Reseller\Application\ResellerReport;
use App\Reseller\Application\ResellerSettings;
use App\Security\AdminActor;
use App\Security\AdminPermission;
use App\Wallet\Application\WalletQueries;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletKeys;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resellers in the web panel (docs/design/reseller-and-claim.md, S1). RESELLERS_VIEW to look: the list, a profile with
 * its wholesale rules, wallet and recent orders, and the report (with CSV). The writes need RESELLERS_MANAGE
 * (AdminCrudPermissions::ROUTES), are POST only with a CSRF token and are audited (ResellerAdministration): create a
 * profile for an existing customer, edit it, per-plan rules, enable / disable. A refund of a reseller order also needs
 * WALLETS_MANAGE and is confirmed on a second page (ResellerRefunds). The credit limit stays on the wallet page.
 */
final class ResellerAdminController extends AbstractController
{
    use TranslatedFlashes;

    private const PAGE_SIZE = 25;
    private const CSRF = 'reseller_admin';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerAdministration $administration,
        private readonly ResellerPricing $pricing,
        private readonly ResellerRefunds $refunds,
        private readonly ResellerReport $report,
        private readonly ResellerSettings $settings,
        private readonly ResellerCheckout $checkout,
        private readonly WalletService $wallets,
        private readonly WalletQueries $walletQueries,
    ) {
    }

    #[AdminRoute(path: '/resellers', name: 'resellers')]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        /** @var list<Reseller> $rows */
        $rows = $this->entityManager->getRepository(Reseller::class)->createQueryBuilder('r')
            // The user's Telegram account is an inverse one-to-one: joined, or Doctrine loads it once per row.
            ->join('r.user', 'u')->addSelect('u')
            ->leftJoin('u.telegramAccount', 'ta')->addSelect('ta')
            ->orderBy('r.status', 'ASC')->addOrderBy('r.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1)->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->getQuery()->getResult();
        $resellers = array_slice($rows, 0, self::PAGE_SIZE);
        // One query for the page's wallets, by reseller.
        $byUser = $this->walletQueries->forUsers(array_map(static fn (Reseller $r) => $r->getUser(), $resellers));
        $wallets = [];
        foreach ($resellers as $reseller) {
            $wallets[(int) $reseller->getId()] = $byUser[(int) $reseller->getUser()->getId()] ?? null;
        }

        return $this->render('admin/reseller/index.html.twig', [
            'resellers' => $resellers,
            'wallets' => $wallets,
            'hasNext' => count($rows) > self::PAGE_SIZE,
            'page' => $page,
            'enabled' => $this->settings->enabled(),
            'csrf' => self::CSRF,
        ]);
    }

    #[AdminRoute(path: '/resellers/new', name: 'reseller_create', options: ['methods' => ['POST']])]
    public function create(Request $request): RedirectResponse
    {
        if (!$this->validToken($request)) {
            return $this->toList('flash.csrf_expired', 'danger');
        }
        $customer = $this->administration->findCustomer((string) $request->request->get('customer', ''));
        if (null === $customer) {
            return $this->toList('reseller_admin.error.customer', 'danger');
        }
        try {
            $reseller = $this->administration->create($customer, $request->request->all(), $this->admin());
        } catch (\InvalidArgumentException $e) {
            return $this->toList($e->getMessage(), 'danger');
        }

        return $this->back($reseller, 'reseller_admin.flash.created', 'success');
    }

    #[AdminRoute(path: '/resellers/{id}', name: 'reseller', options: ['requirements' => ['id' => '\d+']])]
    public function show(Request $request, int $id): Response
    {
        $reseller = $this->reseller($id);
        $page = max(1, $request->query->getInt('page', 1));
        /** @var list<Order> $rows */
        $rows = $this->entityManager->getRepository(Order::class)->findBy(['reseller' => $reseller], ['id' => 'DESC'], self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);
        $orders = array_slice($rows, 0, self::PAGE_SIZE);
        $refundable = [];
        $refunded = [];
        foreach ($orders as $order) {
            $refunded[(int) $order->getId()] = $this->wallets->findTransaction(WalletKeys::orderRefund((int) $order->getId()));
            $refundable[(int) $order->getId()] = null === $refunded[(int) $order->getId()] ? $this->refunds->paidAmount($order) : null;
        }
        $rules = $this->entityManager->getRepository(ResellerPlanPrice::class)->findBy(['reseller' => $reseller], ['id' => 'ASC']);
        $clipped = [];
        foreach ($rules as $rule) {
            $clipped[(int) $rule->getId()] = $this->pricing->isClipped($reseller, $rule->getPlan(), $rule);
        }

        return $this->render('admin/reseller/show.html.twig', [
            'reseller' => $reseller,
            'discountPercent' => ResellerAdministration::bpToPercent($reseller->getDiscountBp()),
            'wallet' => $this->wallets->find($reseller->getUser()),
            'spentToday' => null === $reseller->getDailySpendLimit() ? null : $reseller->getDailySpendLimit() - (int) $this->checkout->remainingToday($reseller),
            'rules' => $rules,
            'clipped' => $clipped,
            'plans' => $this->entityManager->getRepository(Plan::class)->findBy([], ['title' => 'ASC']),
            'orders' => $orders,
            'refundable' => $refundable,
            'refunded' => $refunded,
            'hasNext' => count($rows) > self::PAGE_SIZE,
            'page' => $page,
            'enabled' => $this->settings->enabled(),
            'floorPercent' => ResellerAdministration::bpToPercent($this->settings->floorBp()),
            'csrf' => self::CSRF,
        ]);
    }

    #[AdminRoute(path: '/resellers/{id}/update', name: 'reseller_update', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function update(Request $request, int $id): RedirectResponse
    {
        $reseller = $this->reseller($id);
        if (!$this->validToken($request)) {
            return $this->back($reseller, 'flash.csrf_expired', 'danger');
        }
        try {
            $this->administration->update($reseller, $request->request->all(), $this->admin());
        } catch (\InvalidArgumentException $e) {
            return $this->back($reseller, $e->getMessage(), 'danger');
        }

        return $this->back($reseller, 'reseller_admin.flash.updated', 'success');
    }

    #[AdminRoute(path: '/resellers/{id}/plan-price', name: 'reseller_plan_price', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function planPrice(Request $request, int $id): RedirectResponse
    {
        $reseller = $this->reseller($id);
        if (!$this->validToken($request)) {
            return $this->back($reseller, 'flash.csrf_expired', 'danger');
        }
        $plan = $this->entityManager->getRepository(Plan::class)->find($request->request->getInt('plan_id'));
        if (!$plan instanceof Plan) {
            return $this->back($reseller, 'reseller_admin.error.plan', 'danger');
        }
        try {
            $clipped = $this->administration->setPlanPrice($reseller, $plan, (string) $request->request->get('kind', ''), (string) $request->request->get('value', ''), $this->admin());
        } catch (\InvalidArgumentException $e) {
            return $this->back($reseller, $e->getMessage(), 'danger');
        }
        if ($clipped) {
            $this->flash('warning', 'reseller_admin.flash.rule_clipped', ['%plan%' => $plan->getTitle()]);
        }

        return $this->back($reseller, 'reseller_admin.flash.rule_saved', 'success');
    }

    #[AdminRoute(path: '/resellers/{id}/plan-price/{planId}/remove', name: 'reseller_plan_price_remove', options: ['requirements' => ['id' => '\d+', 'planId' => '\d+'], 'methods' => ['POST']])]
    public function removePlanPrice(Request $request, int $id, int $planId): RedirectResponse
    {
        $reseller = $this->reseller($id);
        if (!$this->validToken($request)) {
            return $this->back($reseller, 'flash.csrf_expired', 'danger');
        }
        $plan = $this->entityManager->getRepository(Plan::class)->find($planId) ?? throw new NotFoundHttpException();
        $this->administration->removePlanPrice($reseller, $plan, $this->admin());

        return $this->back($reseller, 'reseller_admin.flash.rule_removed', 'success');
    }

    #[AdminRoute(path: '/resellers/{id}/enable', name: 'reseller_enable', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function enable(Request $request, int $id): RedirectResponse
    {
        $reseller = $this->reseller($id);
        if (!$this->validToken($request)) {
            return $this->back($reseller, 'flash.csrf_expired', 'danger');
        }
        $this->administration->enable($reseller, $this->admin());

        return $this->back($reseller, 'reseller_admin.flash.enabled', 'success');
    }

    #[AdminRoute(path: '/resellers/{id}/disable', name: 'reseller_disable', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function disable(Request $request, int $id): RedirectResponse
    {
        $reseller = $this->reseller($id);
        if (!$this->validToken($request)) {
            return $this->back($reseller, 'flash.csrf_expired', 'danger');
        }
        $sessions = $this->administration->disable($reseller, $this->admin());
        $this->flash('success', 'reseller_admin.flash.disabled', ['%sessions%' => $sessions]);

        return $this->redirectToRoute('admin_reseller', ['id' => $reseller->getId()]);
    }

    /**
     * Refund of one reseller order to his wallet (RESELLERS_MANAGE and WALLETS_MANAGE). The first POST shows what will
     * happen; only the confirmed POST writes.
     */
    #[AdminRoute(path: '/resellers/orders/{orderId}/refund', name: 'reseller_order_refund', options: ['requirements' => ['orderId' => '\d+'], 'methods' => ['POST']])]
    public function refund(Request $request, int $orderId): Response
    {
        $this->denyAccessUnlessGranted(AdminPermission::WALLETS_MANAGE->value);
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        $reseller = $order?->getReseller();
        if (!$order instanceof Order || !$reseller instanceof Reseller) {
            throw new NotFoundHttpException();
        }
        if (!$this->validToken($request)) {
            return $this->back($reseller, 'flash.csrf_expired', 'danger');
        }
        $amount = (string) $request->request->get('amount', '');
        $reason = (string) $request->request->get('reason', '');
        if (null !== ($problem = $this->refunds->problem($order, $amount, $reason))) {
            return $this->back($reseller, $problem, 'danger');
        }
        if ('1' !== $request->request->get('confirmed')) {
            return $this->render('admin/reseller/refund_confirm.html.twig', [
                'reseller' => $reseller,
                'order' => $order,
                'amount' => (int) str_replace([',', '٬', ' '], '', $amount),
                'paid' => $this->refunds->paidAmount($order),
                'reason' => trim($reason),
                'csrf' => self::CSRF,
            ]);
        }

        try {
            $this->refunds->refund($order, $amount, $reason, $this->admin());
        } catch (\InvalidArgumentException $e) {
            return $this->back($reseller, $e->getMessage(), 'danger');
        } catch (WalletException $e) {
            return $this->back($reseller, 'wallet_admin.error.'.$e->error, 'danger');
        }

        return $this->back($reseller, 'reseller_admin.flash.refunded', 'success');
    }

    #[AdminRoute(path: '/resellers/report', name: 'reseller_report')]
    public function report(Request $request): Response
    {
        [$from, $to] = self::range($request);
        $rows = $this->report->summary($from, $to->modify('+1 day'));

        if ('csv' === $request->query->get('format')) {
            $lines = [['reseller_id', 'name', 'orders', 'new_services', 'renewals', 'add_traffic', 'wholesale', 'retail', 'discount_given', 'refunds']];
            foreach ($rows as $row) {
                $lines[] = [$row['resellerId'], $row['name'], $row['orders'], $row['newServices'], $row['renewals'], $row['addTraffic'], $row['wholesale'], $row['retail'], $row['discountGiven'], $row['refunds']];
            }
            $handle = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot open a temporary stream.');
            foreach ($lines as $line) {
                fputcsv($handle, array_map(self::csvCell(...), $line), escape: '');
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return new Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf('attachment; filename="resellers-%s-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')),
            ]);
        }

        return $this->render('admin/reseller/report.html.twig', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * One CSV cell, safe to open in a spreadsheet: a text starting with = + - @ TAB or CR would be read as a formula
     * (OWASP "CSV injection"), so it gets a leading ' (shown as text). Numbers are written as they are.
     */
    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return is_string($value) && 1 === preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }

    /**
     * The report's dates (inclusive), this month by default.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function range(Request $request): array
    {
        $parse = static function (mixed $value): ?\DateTimeImmutable {
            $date = is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            return false === $date ? null : $date;
        };
        $from = $parse($request->query->get('from')) ?? new \DateTimeImmutable('first day of this month midnight');
        $to = $parse($request->query->get('to')) ?? new \DateTimeImmutable('today');

        return $to < $from ? [$to, $from] : [$from, $to];
    }

    private function reseller(int $id): Reseller
    {
        $reseller = $this->entityManager->getRepository(Reseller::class)->find($id);
        if (!$reseller instanceof Reseller) {
            throw new NotFoundHttpException();
        }
        $this->entityManager->refresh($reseller);

        return $reseller;
    }

    private function validToken(Request $request): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF, $token);
    }

    private function admin(): AdminActor
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return AdminActor::fromAdminUser($user);
    }

    private function back(Reseller $reseller, string $message, string $type): RedirectResponse
    {
        $this->flash($type, $message);

        return $this->redirectToRoute('admin_reseller', ['id' => $reseller->getId()]);
    }

    private function toList(string $message, string $type): RedirectResponse
    {
        $this->flash($type, $message);

        return $this->redirectToRoute('admin_resellers');
    }
}
