<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DiscountCode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:discount:create', description: 'Create a discount code')]
final class DiscountCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('code', null, InputOption::VALUE_REQUIRED)
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'percent|fixed')
            ->addOption('value', null, InputOption::VALUE_REQUIRED)
            ->addOption('max-uses', null, InputOption::VALUE_OPTIONAL)
            ->addOption('applies-to', null, InputOption::VALUE_OPTIONAL, 'all|new_service|renewal|add_traffic', 'all')
            ->addOption('days-valid', null, InputOption::VALUE_OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = strtoupper(trim((string) $input->getOption('code')));
        $type = trim((string) $input->getOption('type'));
        $value = (int) $input->getOption('value');
        $maxUsesRaw = $input->getOption('max-uses');
        $appliesTo = trim((string) $input->getOption('applies-to'));
        $daysValidRaw = $input->getOption('days-valid');

        if ('' === $code) {
            $io->error('--code is required.');

            return Command::FAILURE;
        }
        if (!in_array($type, DiscountCode::allowedTypes(), true)) {
            $io->error('--type must be percent|fixed.');

            return Command::FAILURE;
        }
        if (!in_array($appliesTo, DiscountCode::allowedAppliesTo(), true)) {
            $io->error('--applies-to must be all|new_service|renewal|add_traffic.');

            return Command::FAILURE;
        }
        if (DiscountCode::TYPE_PERCENT === $type && ($value < 1 || $value > 100)) {
            $io->error('Percent value must be between 1 and 100.');

            return Command::FAILURE;
        }
        if (DiscountCode::TYPE_FIXED === $type && $value <= 0) {
            $io->error('Fixed value must be greater than 0.');

            return Command::FAILURE;
        }

        $maxUses = null;
        if (null !== $maxUsesRaw && '' !== (string) $maxUsesRaw) {
            $maxUses = (int) $maxUsesRaw;
            if ($maxUses <= 0) {
                $io->error('--max-uses must be greater than 0.');

                return Command::FAILURE;
            }
        }

        $daysValid = null;
        if (null !== $daysValidRaw && '' !== (string) $daysValidRaw) {
            $daysValid = (int) $daysValidRaw;
            if ($daysValid <= 0) {
                $io->error('--days-valid must be greater than 0.');

                return Command::FAILURE;
            }
        }

        $existing = $this->entityManager->getRepository(DiscountCode::class)->findOneBy(['code' => $code]);
        if ($existing instanceof DiscountCode) {
            $io->error('Discount code already exists.');

            return Command::FAILURE;
        }

        $discount = (new DiscountCode())
            ->setCode($code)
            ->setType($type)
            ->setValue($value)
            ->setAppliesTo($appliesTo)
            ->setMaxUses($maxUses)
            ->setIsActive(true)
            ->setUpdatedAt(new \DateTimeImmutable());

        if (null !== $daysValid) {
            $discount
                ->setStartsAt(new \DateTimeImmutable())
                ->setEndsAt((new \DateTimeImmutable())->modify(sprintf('+%d days', $daysValid)));
        }

        $this->entityManager->persist($discount);
        $this->entityManager->flush();

        $io->success(sprintf('Discount code %s created.', $discount->getCode()));

        return Command::SUCCESS;
    }
}
