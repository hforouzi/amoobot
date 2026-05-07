<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Payment;
use App\Payment\Application\PaymentApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:test-provision', description: 'Run payment provisioning flow via PaymentApprovalService confirm')]
final class PaymentTestProvisionCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentApprovalService $paymentApprovalService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('paymentId', InputArgument::REQUIRED, 'Payment id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paymentId = (int) $input->getArgument('paymentId');
        if ($paymentId <= 0) {
            $io->error('paymentId must be greater than zero.');

            return Command::FAILURE;
        }

        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $io->error('Payment not found.');

            return Command::FAILURE;
        }

        $order = $payment->getOrder();
        $plan = $order->getPlan();
        $inbound = $plan->getInbound();
        $panel = $inbound?->getPanel();

        $io->section('Safe diagnostics');
        $io->listing([
            sprintf('source: %s', 'telegram_payment_approval'),
            sprintf('paymentId: %d', $payment->getId() ?? 0),
            sprintf('paymentStatus: %s', $payment->getStatus()),
            sprintf('orderId: %d', $order->getId() ?? 0),
            sprintf('orderStatus: %s', $order->getStatus()),
            sprintf('planId: %d', $plan->getId() ?? 0),
            sprintf('planInboundId: %d', $inbound?->getId() ?? 0),
            sprintf('panelId: %d', $panel?->getId() ?? 0),
            sprintf('panelType: %s', (string) ($panel?->getType() ?? 'dummy')),
            sprintf('remoteInboundId: %s', (string) ($inbound?->getRemoteInboundId() ?? '')),
        ]);

        try {
            $result = $this->paymentApprovalService->confirm($payment, 'telegram_payment_approval');
        } catch (\Throwable $e) {
            $io->error(sprintf('Provisioning execution failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($result->alreadyProcessed) {
            $io->warning($result->message);
            if (null !== $result->vpnService) {
                $io->writeln(sprintf('serviceId: %d', $result->vpnService->getId() ?? 0));
                $io->writeln(sprintf('remoteId: %s', (string) ($result->vpnService->getRemoteId() ?? '')));
            }

            return Command::SUCCESS;
        }

        if (!$result->processed) {
            $io->error($result->message);

            return Command::FAILURE;
        }

        $io->success($result->message);
        if (null !== $result->vpnService) {
            $io->writeln(sprintf('serviceId: %d', $result->vpnService->getId() ?? 0));
            $io->writeln(sprintf('remoteId: %s', (string) ($result->vpnService->getRemoteId() ?? '')));
            $io->writeln(sprintf('username: %s', (string) ($result->vpnService->getUsername() ?? '')));
            $io->writeln(sprintf('subscriptionUrl: %s', (string) ($result->vpnService->getSubscriptionUrl() ?? '')));
        }

        return Command::SUCCESS;
    }
}
