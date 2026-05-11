<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceTrafficAddonService;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:test-add-traffic', description: 'Test add traffic for an existing service without creating payment')]
final class ServiceTestAddTrafficCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceTrafficAddonService $serviceTrafficAddonService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('serviceId', InputArgument::REQUIRED, 'Target service id')
            ->addOption('traffic-gb', null, InputOption::VALUE_REQUIRED, 'Added traffic in GB', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceId = (int) $input->getArgument('serviceId');
        $trafficGb = max(0, (int) $input->getOption('traffic-gb'));

        if ($serviceId <= 0 || $trafficGb <= 0) {
            $io->error('serviceId and traffic-gb must be greater than zero.');

            return Command::FAILURE;
        }

        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $io->error('VpnService not found.');

            return Command::FAILURE;
        }

        $sourceOrder = $service->getOrder();
        if (!$sourceOrder instanceof Order) {
            $io->error('Source order not found on service; cannot build test add-traffic order.');

            return Command::FAILURE;
        }

        $metadata = [
            'addTraffic' => true,
            'targetServiceId' => $service->getId(),
            'trafficGb' => $trafficGb,
        ];

        $testOrder = (new Order())
            ->setUser($service->getUser())
            ->setPlan($sourceOrder->getPlan())
            ->setType(OrderType::ADD_TRAFFIC)
            ->setTargetService($service)
            ->setAmount(0)
            ->setMetadata($metadata);

        $beforeExpires = $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود';
        $beforeTraffic = (string) ($service->getTrafficLimitGb() ?? 0);

        try {
            $result = $this->serviceTrafficAddonService->addTraffic($service, $trafficGb, $testOrder);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $io->error(sprintf('Add traffic failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->section('Add traffic diagnostics');
        $io->listing([
            sprintf('service_id: %d', $serviceId),
            sprintf('panel_type: %s', $service->getPanel()?->getType() ?? 'dummy'),
            sprintf('before_expires_at: %s', $beforeExpires),
            sprintf('after_expires_at: %s', $service->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'نامحدود'),
            sprintf('before_traffic_limit_gb: %s', $beforeTraffic),
            sprintf('after_traffic_limit_gb: %d', $result->newTrafficLimitGb),
            sprintf('added_traffic_gb: %d', $result->addedTrafficGb),
        ]);

        return Command::SUCCESS;
    }
}
