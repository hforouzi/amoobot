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

#[AsCommand(name: 'app:payment:test-zibal', description: 'Test Zibal payment request for a gateway')]
final class PaymentTestZibalCommand extends Command
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
            ->addArgument('gatewayId', InputArgument::REQUIRED, 'PaymentGateway id (zibal)')
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
        if (PaymentGatewayType::ZIBAL !== $gateway->getType()) {
            $io->error('Given gateway is not zibal.');

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

        if (!$result->success) {
            $io->error($result->message ?: 'Zibal request failed.');
            if (null !== $result->rawResponse) {
                $io->writeln(json_encode($result->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            }

            return Command::FAILURE;
        }

        $io->success('Zibal request created successfully.');
        $io->writeln(sprintf('paymentUrl: %s', (string) ($result->paymentUrl ?? '')));
        $io->writeln(sprintf('trackId: %s', (string) ($result->transactionId ?? '')));

        return Command::SUCCESS;
    }
}

