<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\StorePaymentMethodResolver;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:debug-methods-for-order', description: 'Debug why store payment methods are accepted or skipped for an order')]
final class PaymentDebugMethodsForOrderCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorePaymentMethodResolver $storePaymentMethodResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('orderId', InputArgument::REQUIRED, 'Order id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderId = (int) $input->getArgument('orderId');
        if ($orderId <= 0) {
            $io->error('orderId must be greater than zero.');

            return Command::FAILURE;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            $io->error('Order not found.');

            return Command::FAILURE;
        }

        $diagnostics = $this->storePaymentMethodResolver->getDiagnostics($order);
        $rows = [];
        $methods = $diagnostics['methods'] ?? [];

        foreach (is_array($methods) ? $methods : [] as $method) {
            if (!is_array($method)) {
                continue;
            }

            $rows[] = [
                (int) ($method['methodId'] ?? 0),
                (string) ($method['methodTitle'] ?? ''),
                true === ($method['methodIsActive'] ?? false) ? 'yes' : 'no',
                (string) ($method['methodCurrency'] ?? 'IRR'),
                null === ($method['methodMinAmount'] ?? null) ? '-' : (string) $method['methodMinAmount'],
                null === ($method['methodMaxAmount'] ?? null) ? '-' : (string) $method['methodMaxAmount'],
                (int) ($method['gatewayId'] ?? 0),
                (string) ($method['gatewayType'] ?? ''),
                (string) ($method['gatewayTitle'] ?? ''),
                true === ($method['gatewayIsActive'] ?? false) ? 'yes' : 'no',
                true === ($method['gatewayConfigured'] ?? false) ? 'yes' : 'no',
                true === ($method['hasDriver'] ?? false) ? 'yes' : 'no',
                (int) ($method['orderAmount'] ?? 0),
                (int) ($method['orderPayableAmount'] ?? 0),
                (string) ($method['orderCurrency'] ?? 'IRR'),
                true === ($method['accepted'] ?? false) ? 'accepted' : 'skipped',
                (string) ($method['skipReason'] ?? ''),
            ];
        }

        $io->section(sprintf('Order #%d method diagnostics', (int) ($diagnostics['orderId'] ?? 0)));
        $io->listing([
            sprintf('order amount: %d', (int) ($diagnostics['amount'] ?? 0)),
            sprintf('order payableAmount: %d', (int) ($diagnostics['payableAmount'] ?? 0)),
            sprintf('order currency: %s', (string) ($diagnostics['currency'] ?? 'IRR')),
            sprintf('active store methods count: %d', (int) ($diagnostics['activeStorePaymentMethodCount'] ?? 0)),
            sprintf('available methods count: %d', count($this->storePaymentMethodResolver->getAvailableMethods($order))),
        ]);

        if ([] === $rows) {
            $io->warning('No StorePaymentMethod records found.');

            return Command::SUCCESS;
        }

        $io->table([
            'method_id',
            'method_title',
            'method_active',
            'method_currency',
            'min',
            'max',
            'gateway_id',
            'gateway_type',
            'gateway_title',
            'gateway_active',
            'gateway_configured',
            'has_driver',
            'order_amount',
            'order_payableAmount',
            'order_currency',
            'result',
            'reason',
        ], $rows);

        return Command::SUCCESS;
    }
}
