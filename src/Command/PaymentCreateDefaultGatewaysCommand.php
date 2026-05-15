<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use App\Payment\Domain\PaymentGatewayType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:create-default-gateways', description: 'Create default payment gateways')]
final class PaymentCreateDefaultGatewaysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gatewayRepo = $this->entityManager->getRepository(PaymentGateway::class);

        $manual = $this->ensureGateway($io, $gatewayRepo, PaymentGatewayType::MANUAL_CARD, true, 0, 'IRR');
        $this->ensureGateway($io, $gatewayRepo, PaymentGatewayType::ZIBAL, false, 10, 'IRR');
        $this->ensureGateway($io, $gatewayRepo, PaymentGatewayType::NOWPAYMENTS, false, 20, 'IRR');

        $methodRepo = $this->entityManager->getRepository(StorePaymentMethod::class);
        $manualMethod = $methodRepo->findOneBy(['gateway' => $manual], ['id' => 'ASC']);
        if (!$manualMethod instanceof StorePaymentMethod) {
            $manualMethod = (new StorePaymentMethod())
                ->setGateway($manual)
                ->setTitle('کارت به کارت')
                ->setIsActive(true)
                ->setSortOrder(1)
                ->setCurrency('IRR');
            $this->entityManager->persist($manualMethod);
            $io->writeln('Created store payment method for manual_card.');
        } else {
            $io->writeln('Store method for manual_card already exists.');
        }

        $this->entityManager->flush();
        $io->success('Default gateways ensured.');

        return Command::SUCCESS;
    }

    private function ensureGateway(SymfonyStyle $io, object $gatewayRepo, string $type, bool $active, int $sortOrder, string $currency): PaymentGateway
    {
        $gateway = $gatewayRepo->findOneBy(['type' => $type], ['id' => 'ASC']);
        if ($gateway instanceof PaymentGateway) {
            $io->writeln(sprintf('%s gateway already exists.', $type));

            return $gateway;
        }

        $gateway = (new PaymentGateway())
            ->setType($type)
            ->setTitle($this->moduleRegistry->defaultTitle($type))
            ->setDescription((string) $this->moduleRegistry->get($type)['description'])
            ->setIsActive($active)
            ->setIsDefault(PaymentGatewayType::MANUAL_CARD === $type)
            ->setSortOrder($sortOrder)
            ->setCurrency($currency)
            ->setConfig($this->moduleRegistry->defaultConfig($type))
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($gateway);
        $io->writeln(sprintf('Created %s gateway.', $type));

        return $gateway;
    }
}
