<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Setting;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sales:status', description: 'Show sales availability settings')]
final class SalesStatusCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesSettingsProvider $salesSettingsProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('new_orders_enabled: %s', $this->salesSettingsProvider->newOrdersEnabled() ? 'true' : 'false'));
        $output->writeln(sprintf('renewals_enabled: %s', $this->salesSettingsProvider->renewalsEnabled() ? 'true' : 'false'));
        $output->writeln(sprintf('add_traffic_enabled: %s', $this->salesSettingsProvider->addTrafficEnabled() ? 'true' : 'false'));
        $output->writeln(sprintf(
            'disabled_message: %s (source: %s)',
            $this->salesSettingsProvider->disabledMessage(),
            $this->source(SalesSettingsProvider::DISABLED_MESSAGE)
        ));

        return Command::SUCCESS;
    }

    private function source(string $key): string
    {
        $setting = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $key]);
        if ($setting instanceof Setting && null !== $setting->getValue() && '' !== trim($setting->getValue())) {
            return 'DB';
        }

        return 'default';
    }
}
