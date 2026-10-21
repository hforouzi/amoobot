<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\Order;
use App\Security\AdminPermission;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: orders ("admin.o"): paged list filtered by status, filter menu, detail. Read-only (ORDERS_VIEW).
 * Callbacks: l:<page>:<status index or a>, f, v:<id>.
 */
final class AdminOrdersHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminScreen $screen,
        private readonly AdminBotReplies $replies,
    ) {
    }

    public function area(): string
    {
        return 'o';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 'f', 'v'], true) ? [AdminPermission::ORDERS_VIEW] : [];
    }

    public function handle(AdminRequest $request): void
    {
        match ($request->verb) {
            'f' => $this->filters($request),
            'v' => $this->detail($request, $request->intArg(0)),
            default => $this->list($request, $request->intArg(0), $request->arg(1, 'a')),
        };
    }

    public function list(AdminRequest $request, int $page, string $statusCode = 'a'): void
    {
        $status = OrderStatus::ALL[(int) $statusCode] ?? null;
        $statusCode = null === $status || !ctype_digit($statusCode) ? 'a' : $statusCode;
        if ('a' === $statusCode) {
            $status = null;
        }

        $query = $this->entityManager->getRepository(Order::class)->createQueryBuilder('o');
        if (null !== $status) {
            $query->where('o.status = :status')->setParameter('status', $status);
        }
        $total = (int) (clone $query)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
        [$page, $pages] = $this->screen->page($page, $total);
        /** @var list<Order> $orders */
        $orders = $query->orderBy('o.id', 'DESC')->setFirstResult($page * AdminScreen::PAGE_SIZE)->setMaxResults(AdminScreen::PAGE_SIZE)->getQuery()->getResult();

        $lines = [$this->screen->text('admin.orders.title', ['filter' => $status ?? $this->screen->label('button.admin.all'), 'page_info' => $this->screen->pageInfo($page, $pages)])];
        $buttons = [];
        foreach ($orders as $order) {
            $lines[] = $this->screen->text('admin.orders.line', $this->variables($order));
            $buttons[] = $this->screen->rawButton('#'.$order->getId(), 'admin.o:v:'.$order->getId());
        }
        if ([] === $orders) {
            $lines[] = $this->screen->text('admin.list_empty');
        }

        $rows = array_chunk($buttons, 5);
        $rows[] = [$this->screen->button('button.admin.filter', 'admin.o:f')];
        $this->screen->send($request, implode("\n\n", $lines), $this->screen->listKeyboard($rows, $this->screen->pagerRow($page, $pages, 'admin.o:l:%d:'.$statusCode)));
    }

    private function filters(AdminRequest $request): void
    {
        $buttons = [$this->screen->button('button.admin.all', 'admin.o:l:0:a')];
        foreach (OrderStatus::ALL as $index => $status) {
            $buttons[] = $this->screen->rawButton($status, 'admin.o:l:0:'.$index);
        }
        $this->screen->send($request, $this->screen->text('admin.orders.filter_title'), $this->screen->listKeyboard(array_chunk($buttons, 3), [], 'admin.o:l:0:a'));
    }

    private function detail(AdminRequest $request, int $id): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.o:l:0:a');

            return;
        }

        $rows = [];
        $userId = $order->getUser()->getId();
        $rows[] = [$this->screen->rawButton('👤 '.$this->replies->identity($order->getUser()->getTelegramAccount()), 'admin_user_view:'.$userId)];
        $this->screen->send($request, $this->screen->text('admin.orders.detail', $this->variables($order) + [
            'tracking' => $order->getTrackingCode() ?? '-',
            'service' => null === $order->getTargetService() ? '-' : '#'.$order->getTargetService()->getId(),
        ]), $this->screen->listKeyboard($rows, [], 'admin.o:l:0:a'));
    }

    /**
     * @return array<string, string|int>
     */
    private function variables(Order $order): array
    {
        return [
            'id' => (int) $order->getId(),
            'status' => $order->getStatus(),
            'type' => $this->replies->orderType($order->getType()),
            'amount' => AdminScreen::money($order->getAmount()),
            'user' => $this->replies->identity($order->getUser()->getTelegramAccount()),
            'plan' => $order->getPlan()->getTitle(),
            'date' => $order->getCreatedAt()->format('Y-m-d H:i'),
        ];
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
