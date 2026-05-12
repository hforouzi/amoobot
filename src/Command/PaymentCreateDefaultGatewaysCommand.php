<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->entityManager->getRepository(PaymentGateway::class);

        $manual = $repo->findOneBy(['type' => PaymentGatewayType::MANUAL_CARD]);
        if (!$manual instanceof PaymentGateway) {
            $manual = (new PaymentGateway())
                ->setType(PaymentGatewayType::MANUAL_CARD)
                ->setTitle('Manual Card')
                ->setDescription('Manual card-to-card flow')
                ->setIsActive(true)
                ->setIsDefault(true)
                ->setSortOrder(0)
                ->setCurrency('IRR')
                ->setConfig([]);
            $this->entityManager->persist($manual);
            $io->writeln('Created manual_card gateway.');
        } else {
            $io->writeln('manual_card gateway already exists.');
        }

        $zibal = $repo->findOneBy(['type' => PaymentGatewayType::ZIBAL]);
        if (!$zibal instanceof PaymentGateway) {
            $zibal = (new PaymentGateway())
                ->setType(PaymentGatewayType::ZIBAL)
                ->setTitle('Zibal')
                ->setDescription('Online payment via Zibal')
                ->setIsActive(false)
                ->setIsDefault(false)
                ->setSortOrder(10)
                ->setCurrency('IRR')
                ->setConfig([
                    'merchant' => 'zibal',
                    'sandbox' => true,
                    'callback_base_url' => '',
                ]);
            $this->entityManager->persist($zibal);
            $io->writeln('Created zibal gateway (inactive).');
        } else {
            $io->writeln('zibal gateway already exists.');
        }

        $this->entityManager->flush();
        $io->success('Default gateways ensured.');

        return Command::SUCCESS;
    }
}

