<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:test-custom-api', description: 'Test Custom API payment request for a gateway')]
final class PaymentTestCustomApiCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('gatewayId', InputArgument::REQUIRED, 'PaymentGateway id (custom_api)')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Test amount', '10000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gatewayId = (int) $input->getArgument('gatewayId');
        $amount = max(1, (int) $input->getOption('amount'));

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($gatewayId);
        if (!$gateway instanceof PaymentGateway) {
            $io->error('Gateway not found.');

            return Command::FAILURE;
        }
        if (PaymentGatewayType::CUSTOM_API !== $gateway->getType()) {
            $io->error('Given gateway is not custom_api.');

            return Command::FAILURE;
        }

        $payment = (new Payment())
            ->setGateway($gateway)
            ->setGatewayType($gateway->getType())
            ->setMethod($gateway->getType())
            ->setCurrency($gateway->getCurrency())
            ->setAmount($amount)
            ->setPayableAmount($amount);
        $order = (new Order())
            ->setAmount($amount);
        $payment->setOrder($order);

        try {
            $result = $this->paymentGatewayRegistry->resolve($gateway)->createPayment($payment, $order);
        } catch (\Throwable $e) {
            $io->error(sprintf('Request failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('success: %s', $result->success ? 'true' : 'false'));
        $io->writeln(sprintf('paymentUrl: %s', (string) ($result->paymentUrl ?? '')));
        $io->writeln(sprintf('transactionId: %s', (string) ($result->transactionId ?? '')));
        $io->writeln(sprintf('authority: %s', (string) ($result->authority ?? '')));
        $io->writeln(sprintf('message: %s', (string) ($result->message ?? '')));

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }
}
