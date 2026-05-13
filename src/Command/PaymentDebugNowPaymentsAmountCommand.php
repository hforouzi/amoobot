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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:debug-nowpayments-amount', description: 'Debug NOWPayments amount conversion and minimum checks')]
final class PaymentDebugNowPaymentsAmountCommand extends Command
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
            ->addArgument('gatewayId', InputArgument::REQUIRED, 'PaymentGateway id (nowpayments)')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Original order amount in gateway currency', '4500000');
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

        if (PaymentGatewayType::NOWPAYMENTS !== $gateway->getType()) {
            $io->error(sprintf('Given gateway type is "%s", not nowpayments.', $gateway->getType()));

            return Command::FAILURE;
        }

        /** @var NowPaymentsGateway $driver */
        $driver = $this->paymentGatewayRegistry->resolve($gateway);
        $quote = $driver->debugAmount($gateway, $amount);
        $rateSnapshot = is_array($quote['rateSnapshot'] ?? null) ? $quote['rateSnapshot'] : [];

        $io->section('NOWPayments Amount Debug');
        $io->listing([
            sprintf('gateway id: %d', $gatewayId),
            sprintf('original amount: %d %s', $amount, $gateway->getCurrency()),
            sprintf('amount unit: %s', (string) ($quote['amountUnit'] ?? $gateway->getNowPaymentsAmountUnit())),
            sprintf('conversion rate field: %s', (string) ($rateSnapshot['rateField'] ?? '-')),
            sprintf('conversion rate: %s', null === ($rateSnapshot['rateUsed'] ?? null) ? '-' : (string) $rateSnapshot['rateUsed']),
            sprintf('price amount: %s %s', null === ($quote['priceAmount'] ?? null) ? 'ERROR' : (string) $quote['priceAmount'], strtoupper((string) ($quote['priceCurrency'] ?? 'usd'))),
            sprintf('pay currency: %s', strtoupper((string) ($quote['payCurrency'] ?? ''))),
            sprintf('estimated crypto amount: %s', (string) ($quote['estimatedPayAmount'] ?? '(unavailable)')),
            sprintf('min amount: %s %s', (string) ($quote['minAmount'] ?? '(unavailable)'), strtoupper((string) ($quote['minAmountCurrency'] ?? ''))),
            sprintf('eligible: %s', true === ($quote['canCreate'] ?? false) ? 'yes' : 'no'),
            sprintf('message: %s', (string) ($quote['message'] ?? '')),
        ]);

        $estimate = $quote['estimate'] ?? null;
        if (is_array($estimate)) {
            $io->section('Estimate');
            $io->writeln(json_encode($estimate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        $minAmountCheck = $quote['minAmountCheck'] ?? null;
        if (is_array($minAmountCheck)) {
            $io->section('Min amount');
            $io->writeln(json_encode($minAmountCheck, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return true === ($quote['canCreate'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}
