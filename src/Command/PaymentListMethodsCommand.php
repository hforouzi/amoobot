<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorePaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:list-methods', description: 'List store payment methods')]
final class PaymentListMethodsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $methods = $this->entityManager->getRepository(StorePaymentMethod::class)
            ->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        if ([] === $methods) {
            $io->warning('No store payment methods found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($methods as $method) {
            if (!$method instanceof StorePaymentMethod) {
                continue;
            }
            $gateway = $method->getGateway();
            $rows[] = [
                $method->getId(),
                $method->getTitle(),
                $gateway->getId(),
                $gateway->getType(),
                $gateway->getTitle(),
                $method->isActive() ? 'yes' : 'no',
                $method->getSortOrder(),
                null === $method->getMinAmount() ? '-' : $method->getMinAmount(),
                null === $method->getMaxAmount() ? '-' : $method->getMaxAmount(),
                $method->getCurrency(),
            ];
        }

        $io->table(['id', 'title', 'gateway_id', 'gateway_type', 'gateway_title', 'active', 'sort', 'min', 'max', 'currency'], $rows);

        return Command::SUCCESS;
    }
}

