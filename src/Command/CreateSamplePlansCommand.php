<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-sample-plans', description: 'Create sample plans for telegram shop')]
class CreateSamplePlansCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plans = [
            ['title' => 'تست یک ماهه', 'days' => 30, 'traffic' => 50, 'price' => 100000],
            ['title' => 'اقتصادی سه ماهه', 'days' => 90, 'traffic' => 150, 'price' => 270000],
        ];

        foreach ($plans as $data) {
            $existing = $this->entityManager->getRepository(Plan::class)->findOneBy(['title' => $data['title']]);
            if ($existing instanceof Plan) {
                continue;
            }

            $plan = (new Plan())
                ->setTitle($data['title'])
                ->setDurationDays($data['days'])
                ->setTrafficGb($data['traffic'])
                ->setPrice($data['price'])
                ->setIsActive(true);

            $this->entityManager->persist($plan);
        }

        $this->entityManager->flush();
        $output->writeln('Sample plans are ready.');

        return Command::SUCCESS;
    }
}
