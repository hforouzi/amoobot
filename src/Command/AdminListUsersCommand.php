<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:admin:list-users', description: 'List EasyAdmin admin users')]
final class AdminListUsersCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->entityManager->getRepository(AdminUser::class)->findBy([], ['id' => 'ASC']);

        $table = new Table($output);
        $table->setHeaders(['id', 'email', 'roles', 'active', 'lastLoginAt']);

        foreach ($users as $user) {
            if (!$user instanceof AdminUser) {
                continue;
            }

            $table->addRow([
                $user->getId(),
                $user->getEmail(),
                implode(',', $user->getRoles()),
                $user->isActive() ? 'yes' : 'no',
                $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM) ?? '-',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
