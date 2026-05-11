<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-default-settings', description: 'Create default Setting rows')]
class CreateDefaultSettingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $paymentCardNumber,
        private readonly string $paymentCardHolder,
        private readonly ?string $paymentDescription,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaults = [
            'payment.card_number' => $this->paymentCardNumber,
            'payment.card_holder' => $this->paymentCardHolder,
            'payment.description' => $this->paymentDescription ?? '',
            'service.notify.expiry_days' => '3,1',
            'service.notify.traffic_thresholds' => '80,95,100',
        ];

        foreach ($defaults as $key => $value) {
            $existing = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $key]);
            if ($existing instanceof Setting) {
                continue;
            }

            $setting = (new Setting())
                ->setKeyName($key)
                ->setValue($value)
                ->setType('string');
            $this->entityManager->persist($setting);
        }

        $this->entityManager->flush();
        $output->writeln('Default settings created.');

        return Command::SUCCESS;
    }
}
