<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:test-plugin-gateway', description: 'Validate that a plugin payment gateway can be loaded safely')]
final class PaymentTestPluginGatewayCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('gatewayId', InputArgument::REQUIRED, 'PaymentGateway ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find((int) $input->getArgument('gatewayId'));
        if (!$gateway instanceof PaymentGateway) {
            $io->error('Payment gateway not found.');

            return Command::FAILURE;
        }

        if (null === $gateway->getPluginCode()) {
            $io->error('This payment gateway is not a plugin gateway.');

            return Command::FAILURE;
        }

        try {
            $driver = $this->paymentGatewayRegistry->resolve($gateway);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!$driver instanceof PluginPaymentGatewayDriverAdapter) {
            $io->error('Resolved gateway is not a plugin adapter.');

            return Command::FAILURE;
        }

        $io->success('Plugin gateway loaded successfully.');
        $io->table(['field', 'value'], [
            ['plugin code', (string) $gateway->getPluginCode()],
            ['class name', $driver->getPluginClass()],
            ['configured', $this->moduleRegistry->isConfigured($gateway) ? 'yes' : 'no'],
            ['can create payment', 'yes'],
        ]);

        return Command::SUCCESS;
    }
}
