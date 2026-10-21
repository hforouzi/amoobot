<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: store payment methods and gateways ("admin.m", GATEWAYS_MANAGE): lists, active on/off, method order.
 * No configuration or secrets in the bot (web panel only).
 * Callbacks: l (methods), t:<id> / tk:<id>:<0|1>, u|d:<id> / uk|dk:<id> (move up/down), g (gateways),
 * gt:<id> / gk:<id>:<0|1>.
 */
final class AdminPaymentMethodsHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'm';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 't', 'tk', 'u', 'd', 'uk', 'dk', 'g', 'gt', 'gk'], true) ? [AdminPermission::GATEWAYS_MANAGE] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $id = $request->intArg(0);
        match ($request->verb) {
            't' => $this->askToggleMethod($request, $id),
            'tk' => $this->toggleMethod($request, $id, '1' === $request->arg(1)),
            'u', 'd' => $this->askMove($request, $id, 'u' === $request->verb),
            'uk', 'dk' => $this->move($request, $id, 'uk' === $request->verb),
            'g' => $this->gateways($request),
            'gt' => $this->askToggleGateway($request, $id),
            'gk' => $this->toggleGateway($request, $id, '1' === $request->arg(1)),
            default => $this->methods($request),
        };
    }

    private function methods(AdminRequest $request): void
    {
        $methods = $this->orderedMethods();
        $lines = [$this->screen->text('admin.methods.title')];
        $rows = [];
        foreach ($methods as $position => $method) {
            $lines[] = $this->screen->text('admin.methods.line', [
                'state' => $method->isActive() ? '✅' : '⏸',
                'position' => $position + 1,
                'title' => $method->getTitle(),
                'gateway' => $method->getGateway()->getTitle(),
            ]);
            $rows[] = [
                $this->screen->rawButton(($method->isActive() ? '⏸ ' : '▶️ ').$method->getTitle(), 'admin.m:t:'.$method->getId()),
                $this->screen->button('button.admin.up', 'admin.m:u:'.$method->getId()),
                $this->screen->button('button.admin.down', 'admin.m:d:'.$method->getId()),
            ];
        }
        if ([] === $methods) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $lines[] = $this->screen->text('admin.gateways.web_only');
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard($rows));
    }

    private function gateways(AdminRequest $request): void
    {
        /** @var list<PaymentGateway> $gateways */
        $gateways = $this->entityManager->getRepository(PaymentGateway::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        $lines = [$this->screen->text('admin.gateways.title')];
        $rows = [];
        foreach ($gateways as $gateway) {
            $lines[] = $this->screen->text('admin.gateways.line', [
                'state' => $gateway->isActive() ? '✅' : '⏸',
                'id' => (int) $gateway->getId(),
                'title' => $gateway->getTitle(),
                'type' => $gateway->getType(),
            ]);
            $rows[] = [$this->screen->rawButton(($gateway->isActive() ? '⏸ ' : '▶️ ').$gateway->getTitle(), 'admin.m:gt:'.$gateway->getId())];
        }
        if ([] === $gateways) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $lines[] = $this->screen->text('admin.gateways.web_only');
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard($rows));
    }

    private function askToggleMethod(AdminRequest $request, int $id): void
    {
        $method = $this->entityManager->getRepository(StorePaymentMethod::class)->find($id);
        if (!$method instanceof StorePaymentMethod) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.m:l');

            return;
        }
        $activate = !$method->isActive();
        $this->screen->confirm($request, $this->screen->text($activate ? 'admin.methods.confirm_activate' : 'admin.methods.confirm_deactivate', ['title' => $method->getTitle()]), 'admin.m:tk:'.$id.':'.($activate ? '1' : '0'), 'admin.m:l');
    }

    private function toggleMethod(AdminRequest $request, int $id, bool $active): void
    {
        $method = $this->entityManager->getRepository(StorePaymentMethod::class)->find($id);
        if (!$method instanceof StorePaymentMethod) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.m:l');

            return;
        }
        if ($method->isActive() === $active) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.m:l');

            return;
        }
        $method->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->screen->audit($request, $active ? 'store_payment_method.activate' : 'store_payment_method.deactivate', 'StorePaymentMethod', $id, ['title' => $method->getTitle()]);
        $this->screen->notice($request, 'admin.done', [], 'admin.m:l');
    }

    private function askMove(AdminRequest $request, int $id, bool $up): void
    {
        $method = $this->entityManager->getRepository(StorePaymentMethod::class)->find($id);
        if (!$method instanceof StorePaymentMethod) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.m:l');

            return;
        }
        $this->screen->confirm($request, $this->screen->text($up ? 'admin.methods.confirm_up' : 'admin.methods.confirm_down', ['title' => $method->getTitle()]), ($up ? 'admin.m:uk:' : 'admin.m:dk:').$id, 'admin.m:l');
    }

    /** Swaps the method with its neighbour (positions renumbered 10, 20, ... first, so equal sort orders work). */
    private function move(AdminRequest $request, int $id, bool $up): void
    {
        $methods = $this->orderedMethods();
        $index = null;
        foreach ($methods as $position => $method) {
            if ($method->getId() === $id) {
                $index = $position;
            }
        }
        $neighbour = null === $index ? null : ($up ? $index - 1 : $index + 1);
        if (null === $index || !isset($methods[$neighbour])) {
            $this->screen->notice($request, null === $index ? 'admin.not_found' : 'admin.methods.cannot_move', [], 'admin.m:l');

            return;
        }

        [$methods[$index], $methods[$neighbour]] = [$methods[$neighbour], $methods[$index]];
        foreach ($methods as $position => $method) {
            $method->setSortOrder(($position + 1) * 10)->setUpdatedAt(new \DateTimeImmutable());
        }
        $this->entityManager->flush();
        $this->screen->audit($request, 'store_payment_method.move', 'StorePaymentMethod', $id, ['direction' => $up ? 'up' : 'down', 'position' => $neighbour + 1]);
        $this->methods($request);
    }

    private function askToggleGateway(AdminRequest $request, int $id): void
    {
        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($id);
        if (!$gateway instanceof PaymentGateway) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.m:g');

            return;
        }
        $activate = !$gateway->isActive();
        $this->screen->confirm($request, $this->screen->text($activate ? 'admin.gateways.confirm_activate' : 'admin.gateways.confirm_deactivate', ['title' => $gateway->getTitle()]), 'admin.m:gk:'.$id.':'.($activate ? '1' : '0'), 'admin.m:g');
    }

    private function toggleGateway(AdminRequest $request, int $id, bool $active): void
    {
        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($id);
        if (!$gateway instanceof PaymentGateway) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.m:g');

            return;
        }
        if ($gateway->isActive() === $active) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.m:g');

            return;
        }
        $gateway->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->screen->audit($request, $active ? 'payment_gateway.activate' : 'payment_gateway.deactivate', 'PaymentGateway', $id, ['title' => $gateway->getTitle(), 'type' => $gateway->getType()]);
        $this->screen->notice($request, 'admin.done', [], 'admin.m:g');
    }

    /**
     * @return list<StorePaymentMethod>
     */
    private function orderedMethods(): array
    {
        return $this->entityManager->getRepository(StorePaymentMethod::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
