<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:discount:validate', description: 'Validate a discount code against order context')]
final class DiscountValidateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiscountCodeService $discountCodeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Discount code')
            ->addOption('user-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('amount', null, InputOption::VALUE_REQUIRED)
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'new_service|renewal|add_traffic');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = (string) $input->getArgument('code');
        $amount = (int) $input->getOption('amount');
        $type = trim((string) $input->getOption('type'));
        $userId = (int) ($input->getOption('user-id') ?? 0);

        if ($amount <= 0) {
            $io->error('--amount must be greater than 0.');

            return Command::FAILURE;
        }
        if (!in_array($type, OrderType::ALL, true)) {
            $io->error('--type must be new_service|renewal|add_traffic.');

            return Command::FAILURE;
        }

        $user = null;
        if ($userId > 0) {
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user instanceof User) {
                $io->error('User not found.');

                return Command::FAILURE;
            }
        }

        if (!$user instanceof User) {
            $user = $this->entityManager->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
            if (!$user instanceof User) {
                $io->error('No user exists for validation; pass --user-id.');

                return Command::FAILURE;
            }
        }

        $result = $this->discountCodeService->validateCode($code, $user, $type, null, $amount);
        if (!$result->valid) {
            $io->error($result->message);

            return Command::FAILURE;
        }

        $io->success('Code is valid.');
        $io->listing([
            sprintf('discount_code: %s', $result->discountCode?->getCode() ?? '-'),
            sprintf('discount_amount: %d', $result->discountAmount),
            sprintf('final_amount: %d', $result->finalAmount),
        ]);

        return Command::SUCCESS;
    }
}
