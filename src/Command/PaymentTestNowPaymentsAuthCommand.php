<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:test-nowpayments-auth', description: 'Test NOWPayments auth/config using x-api-key')]
final class PaymentTestNowPaymentsAuthCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('gatewayId', InputArgument::REQUIRED, 'PaymentGateway id (nowpayments)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gatewayId = (int) $input->getArgument('gatewayId');

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($gatewayId);
        if (!$gateway instanceof PaymentGateway) {
            $io->error('Gateway not found.');

            return Command::FAILURE;
        }

        if (PaymentGatewayType::NOWPAYMENTS !== $gateway->getType()) {
            $io->error(sprintf('Given gateway type is "%s", not nowpayments.', $gateway->getType()));

            return Command::FAILURE;
        }

        /** @var NowPaymentsGateway $driver */
        $driver = $this->paymentGatewayRegistry->resolve($gateway);
        $result = $driver->testAuthentication($gateway);

        $io->section('NOWPayments Auth Test');
        $io->writeln(sprintf('gateway_id: %d', $gatewayId));
        $io->writeln(sprintf('api_key_configured: %s', (string) ($result['api_key_configured'] ?? 'no')));
        $io->writeln(sprintf('api_key_length: %d', (int) ($result['api_key_length'] ?? 0)));
        $io->writeln(sprintf('api_key_prefix: %s', (string) ($result['api_key_prefix'] ?? '')));
        $io->writeln(sprintf('endpoint: %s', (string) ($result['endpoint'] ?? '')));
        $io->writeln(sprintf('sandbox: %s', (string) ($result['sandbox'] ?? 'no')));
        $io->writeln(sprintf('http_status: %d', (int) ($result['statusCode'] ?? 0)));
        $io->writeln(sprintf('success: %s', true === ($result['success'] ?? false) ? 'yes' : 'no'));
        $io->writeln(sprintf('message: %s', (string) ($result['message'] ?? '')));

        $response = $result['response'] ?? null;
        if (is_array($response)) {
            $io->section('sanitized_response');
            $io->writeln(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return true === ($result['success'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}
