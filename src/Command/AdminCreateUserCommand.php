<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:create-user', description: 'Create an EasyAdmin admin user')]
final class AdminCreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminAuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Admin role', AdminUser::ROLE_SUPER_ADMIN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        $email = trim((string) ($input->getOption('email') ?? ''));
        if ('' === $email) {
            $email = trim((string) $helper->ask($input, $output, new Question('Email: ')));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Invalid email.</error>');

            return Command::FAILURE;
        }

        if ($this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => strtolower($email)]) instanceof AdminUser) {
            $output->writeln('<error>Admin email already exists.</error>');

            return Command::FAILURE;
        }

        $password = (string) ($input->getOption('password') ?? '');
        if ('' === $password) {
            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $helper->ask($input, $output, $question);
        }

        if ('' === trim($password)) {
            $output->writeln('<error>Password is required.</error>');

            return Command::FAILURE;
        }

        $name = trim((string) ($input->getOption('name') ?? ''));
        if ('' === $name) {
            $name = trim((string) $helper->ask($input, $output, new Question('Display name (optional): ', '')));
        }

        $role = trim((string) ($input->getOption('role') ?? ''));
        if ('' === $role) {
            $role = (string) $helper->ask($input, $output, new ChoiceQuestion('Role: ', AdminUser::SUPPORTED_ROLES, AdminUser::ROLE_SUPER_ADMIN));
        }

        if (!in_array($role, AdminUser::SUPPORTED_ROLES, true)) {
            $output->writeln('<error>Unsupported role.</error>');

            return Command::FAILURE;
        }

        $adminUser = (new AdminUser())
            ->setEmail($email)
            ->setDisplayName($name)
            ->setRoles([$role])
            ->setIsActive(true);
        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, $password));

        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();
        $this->auditLogger->record('admin_user.create', 'AdminUser', $adminUser->getId(), ['email' => $adminUser->getEmail(), 'roles' => $adminUser->getAssignedRoles()]);

        $output->writeln(sprintf('<info>Created admin user #%d: %s</info>', $adminUser->getId(), $adminUser->getEmail()));

        return Command::SUCCESS;
    }
}
