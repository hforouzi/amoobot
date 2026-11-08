<?php

declare(strict_types=1);

namespace App\Command;

use App\Plugin\PluginUpgradePlan;
use App\Plugin\PluginUpgrader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Upgrades an installed plugin in place (PluginUpgrader). Prints names only (versions, permissions, config field names,
 * gateway ids), never a config value.
 */
#[AsCommand(name: 'app:plugin:upgrade', description: 'Upgrade an installed plugin from a newer ZIP package')]
final class PluginUpgradeCommand extends Command
{
    public function __construct(
        private readonly PluginUpgrader $upgrader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('zipPath', InputArgument::REQUIRED, 'Path to the new plugin ZIP package')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check everything and print the plan; change nothing')
            ->addOption('accept-new-permissions', null, InputOption::VALUE_NONE, 'Grant the permissions the new version adds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $result = $this->upgrader->upgrade(
            (string) $input->getArgument('zipPath'),
            $dryRun,
            true === $input->getOption('accept-new-permissions'),
            self::actorLabel(),
        );

        if (null !== $result->plan) {
            $this->printPlan($io, $result->plan);
        }

        if (!$result->success) {
            $io->error(array_merge([$dryRun ? 'Dry run: the upgrade would be refused.' : 'Plugin upgrade refused.'], $result->errors));

            return Command::FAILURE;
        }

        $plan = $result->plan ?? throw new \LogicException('A successful upgrade has a plan.');
        if ($result->dryRun) {
            $io->success(sprintf('Dry run: %s can be upgraded from %s to %s. Nothing was changed.', $plan->code, $plan->oldVersion, $plan->newVersion));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Plugin %s upgraded from %s to %s (status %s kept).', $plan->code, $plan->oldVersion, $plan->newVersion, $plan->status));
        $io->text(sprintf('Previous files kept in %s', self::relativeBackup((string) $result->backupDir)));
        $io->warning([
            'Reload PHP-FPM now, so it drops the old plugin classes from OPcache:',
            sprintf('sudo systemctl reload php%d.%d-fpm', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
            sprintf('Then check: php bin/console app:plugin:doctor %s', $plan->code),
        ]);

        return Command::SUCCESS;
    }

    private function printPlan(SymfonyStyle $io, PluginUpgradePlan $plan): void
    {
        $io->title(sprintf('Upgrade plan: %s', $plan->code));
        $lines = [
            sprintf('Version: %s -> %s', $plan->oldVersion, $plan->newVersion),
            sprintf('Status: %s (kept)', $plan->status),
        ];
        if ($plan->oldMainClass !== $plan->newMainClass) {
            $lines[] = sprintf('Main class: %s -> %s', $plan->oldMainClass, $plan->newMainClass);
        }
        $lines[] = 'Permissions added: '.self::names($plan->addedPermissions);
        $lines[] = 'Permissions removed: '.self::names($plan->removedPermissions);
        $lines[] = 'Config fields added: '.self::names($plan->addedFields);
        $lines[] = 'Config fields removed: '.self::names($plan->removedFields);
        if ([] !== $plan->newlyRequiredFields) {
            $lines[] = 'Newly required, no default: '.self::names($plan->newlyRequiredFields);
        }
        $lines[] = 'Gateways using it: '.([] === $plan->gatewayIds ? 'none' : implode(', ', array_map(static fn (int $id): string => '#'.$id, $plan->gatewayIds)));
        foreach ($plan->obsoleteKeys as $gatewayId => $keys) {
            $lines[] = sprintf('Gateway #%d keeps keys the new schema no longer has: %s', $gatewayId, implode(', ', $keys));
        }
        foreach ($plan->missingRequired as $gatewayId => $fields) {
            $lines[] = sprintf('Gateway #%d lacks newly required: %s', $gatewayId, implode(', ', $fields));
        }
        $io->listing($lines);
    }

    /**
     * @param list<string> $names
     */
    private static function names(array $names): string
    {
        return [] === $names ? 'none' : implode(', ', $names);
    }

    /** The operating system user running the command, and the admin behind sudo when there is one. */
    private static function actorLabel(): string
    {
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $user = is_string($user) && '' !== $user ? $user : get_current_user();
        $label = 'cli:'.$user;
        $sudoUser = getenv('SUDO_USER');
        if (is_string($sudoUser) && '' !== $sudoUser && $sudoUser !== $user) {
            $label .= ' (sudo by '.$sudoUser.')';
        }

        return mb_substr((string) preg_replace('/[^\w .:@()-]/u', '?', $label), 0, 190);
    }

    private static function relativeBackup(string $path): string
    {
        $position = strpos($path, 'var'.\DIRECTORY_SEPARATOR.'plugins'.\DIRECTORY_SEPARATOR);

        return false === $position ? $path : substr($path, $position);
    }
}
