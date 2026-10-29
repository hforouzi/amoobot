<?php

declare(strict_types=1);

namespace App\Command;

use App\Provisioning\Application\ServiceLinkChange;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Output and the --dry-run / --apply choice of the regenerate commands. Links are shown as scheme, address:port and
 * remark (ServiceLinkChange::describe), never with their UUID or password.
 */
trait LinkChangeOutput
{
    /** null after an error was printed: --apply and --dry-run together. */
    private function applyRequested(InputInterface $input, SymfonyStyle $io): ?bool
    {
        if ($input->getOption('apply') && $input->getOption('dry-run')) {
            $io->error('Use either --apply or --dry-run.');

            return null;
        }

        return (bool) $input->getOption('apply');
    }

    private function printChange(SymfonyStyle $io, int $serviceId, ServiceLinkChange $change, bool $apply): void
    {
        $verb = match ($change->outcome) {
            ServiceLinkChange::UPDATED => $apply ? 'updated' : 'would update',
            ServiceLinkChange::UNCHANGED => 'unchanged',
            ServiceLinkChange::KEPT => 'kept (panel gave no links)',
            ServiceLinkChange::SKIPPED => 'skip',
            default => 'FAILED, stored links kept',
        };
        $io->writeln(sprintf('  service #%d [%s] %s: %d -> %d links%s', $serviceId, $change->source, $verb, count($change->before), count($change->outcome === ServiceLinkChange::UPDATED || $change->outcome === ServiceLinkChange::UNCHANGED ? $change->after : $change->before), null !== $change->error ? ' ('.$change->error.')' : ''));
        if (ServiceLinkChange::UPDATED !== $change->outcome) {
            return;
        }
        foreach ($change->added() as $link) {
            $io->writeln('      + '.ServiceLinkChange::describe($link));
        }
        foreach ($change->removed() as $link) {
            $io->writeln('      - '.ServiceLinkChange::describe($link));
        }
        if (count($change->after) < count($change->before)) {
            $io->writeln(sprintf('      ! fewer links than before (%d -> %d)', count($change->before), count($change->after)));
        }
    }
}
