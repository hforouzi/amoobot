<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Entity\Payment;
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

#[AsCommand(name: 'app:payment:test-nowpayments', description: 'Test NOWPayments payment request for a gateway (no provisioning)')]
final class PaymentTestNowPaymentsCommand extends Command
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
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Test amount in gateway currency', '1000000')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Override payment mode (invoice|payment)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gatewayId = (int) $input->getArgument('gatewayId');
        $amount = max(1, (int) $input->getOption('amount'));
        $modeOverride = trim((string) ($input->getOption('mode') ?? ''));

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($gatewayId);
        if (!$gateway instanceof PaymentGateway) {
            $io->error('Gateway not found.');

            return Command::FAILURE;
        }

        if (PaymentGatewayType::NOWPAYMENTS !== $gateway->getType()) {
            $io->error(sprintf('Given gateway type is "%s", not nowpayments.', $gateway->getType()));

            return Command::FAILURE;
        }

        if ('' !== $modeOverride && !in_array($modeOverride, ['invoice', 'payment'], true)) {
            $io->error('Invalid --mode value. Use invoice or payment.');

            return Command::FAILURE;
        }

        if ('' !== $modeOverride) {
            $config = is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
            $config['payment_mode'] = $modeOverride;
            $gateway->setConfig($config);
        }

        if (!$gateway->isNowPaymentsConfigured()) {
            $io->error('NOWPayments gateway is not fully configured. Check api_key, api_base_url, payment_mode, price_currency, and required rate/pay_currency fields for the selected mode.');

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

        /** @var NowPaymentsGateway $driver */
        $driver = $this->paymentGatewayRegistry->resolve($gateway);

        $config = $gateway->getConfig() ?? [];
        $quote = $driver->debugAmount($gateway, $amount);
        $priceAmount = $driver->resolvePriceAmount($payment, $config);
        $rateSnapshot = is_array($quote['rateSnapshot'] ?? null) ? $quote['rateSnapshot'] : [];

        $io->section('NOWPayments Test Request');
        $io->writeln(sprintf('Gateway:        #%d %s', $gateway->getId() ?? 0, $gateway->getTitle()));
        $io->writeln(sprintf('Original amount: %d %s', $amount, $gateway->getCurrency()));
        $io->writeln(sprintf('Mode:           %s', strtoupper((string) ($quote['paymentMode'] ?? $modeOverride ?: ($config['payment_mode'] ?? 'invoice')))));
        $io->writeln(sprintf('Amount unit:    %s', (string) ($quote['amountUnit'] ?? $gateway->getNowPaymentsAmountUnit())));
        $io->writeln(sprintf('Rate field:     %s', (string) ($rateSnapshot['rateField'] ?? '-')));
        $io->writeln(sprintf('Rate used:      %s', null === ($rateSnapshot['rateUsed'] ?? null) ? '-' : (string) $rateSnapshot['rateUsed']));
        $io->writeln(sprintf('Price amount:   %s %s', null !== $priceAmount ? number_format($priceAmount, 4) : 'ERROR', strtoupper((string) ($config['price_currency'] ?? 'usd'))));
        $io->writeln(sprintf('Pay currency:   %s', strtoupper((string) ($config['pay_currency'] ?? ''))));
        $io->writeln(sprintf('Estimated pay:  %s', (string) ($quote['estimatedPayAmount'] ?? '(unavailable)')));
        $io->writeln(sprintf('Min amount:     %s %s', (string) ($quote['minAmount'] ?? '(unavailable)'), strtoupper((string) ($quote['minAmountCurrency'] ?? ''))));
        $io->writeln(sprintf('Eligible:       %s', true === ($quote['canCreate'] ?? false) ? 'YES' : 'NO'));
        $io->writeln(sprintf('API base URL:   %s', $gateway->getNowPaymentsApiBaseUrl()));
        $io->writeln(sprintf('Sandbox:        %s', $gateway->isNowPaymentsSandbox() ? 'YES' : 'NO'));
        $io->newLine();

        if (null === $priceAmount) {
            $io->error((string) ($quote['message'] ?? 'Cannot resolve price amount. Check amount_unit and the matching USD rate field.'));

            return Command::FAILURE;
        }

        if (false === ($quote['canCreate'] ?? false)) {
            $io->error((string) ($quote['message'] ?? 'NOWPayments amount is not eligible for payment creation.'));

            return Command::FAILURE;
        }

        $io->writeln('Sending payment creation request...');

        try {
            $result = $driver->createPayment($payment, $order);
        } catch (\Throwable $e) {
            $io->error(sprintf('Request failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!$result->success) {
            $io->error($result->message ?: 'NOWPayments request failed.');
            if (null !== $result->rawResponse) {
                $io->writeln(json_encode($result->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
            }

            return Command::FAILURE;
        }

        $io->success('NOWPayments payment created successfully.');
        $mode = strtolower((string) ($quote['paymentMode'] ?? $modeOverride ?: ($config['payment_mode'] ?? 'invoice')));
        if ('invoice' === $mode) {
            $io->writeln(sprintf('invoice_id:    %s', $payment->getCryptoInvoiceId() ?? '(none)'));
            $io->writeln(sprintf('invoice_url:   %s', $payment->getCryptoInvoiceUrl() ?? '(none)'));
        }
        $io->writeln(sprintf('payment_id:     %s', $payment->getCryptoPaymentId() ?? '(none)'));
        $io->writeln(sprintf('payment_status: %s', $payment->getCryptoPaymentStatus() ?? '(none)'));
        if ('payment' === $mode) {
            $io->writeln(sprintf('pay_amount:    %s', $payment->getCryptoPayAmount() ?? '(none)'));
            $io->writeln(sprintf('pay_currency:  %s', strtoupper($payment->getCryptoPayCurrency() ?? '(none)')));
            $io->writeln(sprintf('pay_address:   %s', $payment->getCryptoAddress() ?? '(none)'));
        }
        $io->writeln(sprintf('payment_url:   %s', $payment->getPaymentUrl() ?? '(none)'));

        if (null !== $result->rawResponse) {
            $io->section('Raw response');
            $io->writeln(json_encode($result->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return Command::SUCCESS;
    }
}
