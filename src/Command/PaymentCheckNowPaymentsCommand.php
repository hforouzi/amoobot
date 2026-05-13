<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Payment;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:check-nowpayments', description: 'Check NOWPayments payment status for a payment ID')]
final class PaymentCheckNowPaymentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentConfirmationService $paymentConfirmationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paymentId', InputArgument::REQUIRED, 'Payment entity ID')
            ->addOption('approve', null, InputOption::VALUE_NONE, 'Auto-approve if payment is confirmed (use with caution)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paymentId = (int) $input->getArgument('paymentId');
        $doApprove = (bool) $input->getOption('approve');

        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $io->error(sprintf('Payment #%d not found.', $paymentId));

            return Command::FAILURE;
        }

        $gatewayType = $payment->getGatewayType() ?? $payment->getMethod();
        if (PaymentGatewayType::NOWPAYMENTS !== $gatewayType) {
            $io->error(sprintf('Payment #%d is not a NOWPayments payment (type: %s).', $paymentId, (string) $gatewayType));

            return Command::FAILURE;
        }

        $io->section(sprintf('NOWPayments Status Check — Payment #%d', $paymentId));
        $io->writeln(sprintf('crypto_payment_id:     %s', $payment->getCryptoPaymentId() ?? '(none)'));
        $io->writeln(sprintf('crypto_invoice_id:     %s', $payment->getCryptoInvoiceId() ?? '(none)'));
        $io->writeln(sprintf('crypto_invoice_url:    %s', $payment->getCryptoInvoiceUrl() ?? '(none)'));
        $io->writeln(sprintf('current_payment_status: %s', $payment->getCryptoPaymentStatus() ?? '(none)'));
        $io->writeln(sprintf('payment_status (app):  %s', $payment->getStatus()));
        $io->newLine();

        /** @var NowPaymentsGateway $driver */
        $driver = $this->paymentGatewayRegistry->resolveByType(PaymentGatewayType::NOWPAYMENTS);

        try {
            $result = $driver->verifyPayment($payment);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $io->error(sprintf('Verification failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('verified_status:      %s', $result->message ?? '(none)'));
        $io->writeln(sprintf('paid:                 %s', $result->paid ? 'YES' : 'NO'));
        $io->writeln(sprintf('crypto_payment_status: %s', $payment->getCryptoPaymentStatus() ?? '(none)'));
        $io->writeln(sprintf('actually_paid:        %s', $payment->getCryptoActuallyPaid() ?? '(none)'));

        if (null !== $result->rawResponse) {
            $io->section('Raw API response');
            $io->writeln(json_encode($result->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        if (!$result->paid) {
            $io->warning($result->message ?: 'Payment is not yet confirmed. No action taken.');

            return Command::SUCCESS;
        }

        if (!$doApprove) {
            $io->note('Payment appears confirmed. Run with --approve to trigger provisioning.');

            return Command::SUCCESS;
        }

        $io->writeln('Auto-approving payment...');
        $approvalResult = $this->paymentConfirmationService->confirm($payment, 'cli_check_nowpayments');

        if ($approvalResult->processed) {
            $io->success('Payment approved and service provisioned.');
        } elseif ($approvalResult->alreadyProcessed) {
            $io->info('Payment was already processed.');
        } else {
            $io->error(sprintf('Approval failed: %s', $approvalResult->message ?? 'unknown error'));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
