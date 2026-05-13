<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Shop\Application\OrderTrackingCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:orders:backfill-tracking-codes', description: 'Backfill missing tracking codes for orders')]
final class OrdersBackfillTrackingCodesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderTrackingCodeService $orderTrackingCodeService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $orders = $this->entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->where('o.trackingCode IS NULL OR o.trackingCode = :empty')
            ->setParameter('empty', '')
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        $updated = 0;
        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $this->orderTrackingCodeService->assignIfMissing($order);
            ++$updated;
        }
        $this->entityManager->flush();

        $io->success(sprintf('Backfill completed. Updated orders: %d', $updated));

        return Command::SUCCESS;
    }
}

