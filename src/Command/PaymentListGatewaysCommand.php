<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:list-gateways', description: 'List configured payment gateway rows')]
final class PaymentListGatewaysCommand extends Command
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
        $gateways = $this->entityManager->getRepository(PaymentGateway::class)
            ->createQueryBuilder('gateway')
            ->andWhere('gateway.type IN (:types)')
            ->setParameter('types', $this->moduleRegistry->supportedTypes())
            ->orderBy('gateway.sortOrder', 'ASC')
            ->addOrderBy('gateway.id', 'ASC')
            ->getQuery()
            ->getResult();

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
                $this->moduleRegistry->isConfigured($gateway) ? 'yes' : 'no',
                $gateway->getCurrency(),
            ];
        }

        $io->table(['id', 'title', 'type', 'active', 'configured', 'currency'], $rows);

        return Command::SUCCESS;
    }
}
