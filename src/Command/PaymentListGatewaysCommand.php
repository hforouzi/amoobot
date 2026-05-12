<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:list-gateways', description: 'List payment gateways')]
final class PaymentListGatewaysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gateways = $this->entityManager->getRepository(PaymentGateway::class)
            ->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        if ([] === $gateways) {
            $io->warning('No payment gateways found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($gateways as $gateway) {
            if (!$gateway instanceof PaymentGateway) {
                continue;
            }
            $rows[] = [
                $gateway->getId(),
                $gateway->getTitle(),
                $gateway->getType(),
                $gateway->isActive() ? 'yes' : 'no',
                $gateway->isDefault() ? 'yes' : 'no',
                $gateway->getCurrency(),
            ];
        }

        $io->table(['id', 'title', 'type', 'active', 'default', 'currency'], $rows);

        return Command::SUCCESS;
    }
}

