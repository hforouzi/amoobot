<?php

declare(strict_types=1);

namespace App\Command;

use App\Payment\Application\Reconcile\WinapayReconcileOutcome;
use App\Payment\Application\Reconcile\WinapayReconciler;
use App\Payment\Application\Reconcile\WinapayReconcileSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists Winapay payments that are still pending and, with --apply, verifies them at Winapay and settles the paid ones
 * (WinapayReconciler).
 *
 * The dry-run never calls Winapay: PaymentVerification answers 100 once and 101 after that, so a verify in a preview
 * would use up the proof that --apply needs. It only reads the database, so it works with the setting off too, to show
 * what a run would touch. --apply does nothing unless payments.winapay_reconcile_enabled is on.
 */
#[AsCommand(name: 'app:payments:winapay-reconcile', description: 'List (and with --apply, verify and settle) Winapay payments still pending')]
final class PaymentsWinapayReconcileCommand extends Command
{
    public function __construct(
        private readonly WinapayReconciler $reconciler,
        private readonly WinapayReconcileSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Verify at Winapay and settle (default: dry-run, Winapay is not called and nothing is written)')
            ->addOption('min-age-minutes', null, InputOption::VALUE_REQUIRED, 'Only payments created at least this long ago', '10')
            ->addOption('max-age-hours', null, InputOption::VALUE_REQUIRED, 'Only payments created at most this long ago', '24')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max payments to handle in one run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $minAge = self::wholeNumber($input->getOption('min-age-minutes'));
        $maxAge = self::wholeNumber($input->getOption('max-age-hours'));
        $limit = self::wholeNumber($input->getOption('limit'));
        if (null === $minAge || null === $maxAge || null === $limit || $limit < 1 || 60 * $maxAge <= $minAge) {
            $io->error('--min-age-minutes, --max-age-hours and --limit must be whole numbers, --limit at least 1, and the maximum age above the minimum age.');

            return Command::INVALID;
        }

        $enabled = $this->settings->enabled();
        $io->title(sprintf('Pending Winapay payments (%s)', $apply ? 'APPLY' : 'dry-run'));
        if ($apply && !$enabled) {
            $io->warning(sprintf('%s is off: Winapay was not called and nothing was changed.', WinapayReconcileSettings::ENABLED));

            return Command::SUCCESS;
        }

        $found = $this->reconciler->candidates(new \DateTimeImmutable(sprintf('-%d minutes', $minAge)), new \DateTimeImmutable(sprintf('-%d hours', $maxAge)), $limit + 1);
        $moreThanLimit = count($found) > $limit;
        $candidates = array_slice($found, 0, $limit);

        $rows = [];
        $counts = [];
        foreach ($candidates as $candidate) {
            $action = $apply ? $this->reconciler->reconcile($candidate['id'])->value : 'would verify';
            $counts[$action] = ($counts[$action] ?? 0) + 1;
            $rows[] = [$candidate['id'], $candidate['orderId'], $candidate['createdAt']->format('Y-m-d H:i'), $action];
        }

        if ([] === $rows) {
            $io->success('No pending Winapay payments in this window.');
        } else {
            $io->table(['Payment', 'Order', 'Created', 'Result'], $rows);
            $io->listing(array_map(static fn (string $action, int $count): string => sprintf('%s: %d', $action, $count), array_keys($counts), $counts));
        }
        if ($moreThanLimit) {
            $io->warning(sprintf('More than %d payments match: the newest were handled. Raise --limit to reach the others.', $limit));
        }
        if (!$apply) {
            $io->note(sprintf('Dry-run: Winapay was not called and nothing was changed. %s is %s.', WinapayReconcileSettings::ENABLED, $enabled ? 'on' : 'off'));
        }

        return ($counts[WinapayReconcileOutcome::ERROR->value] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private static function wholeNumber(mixed $value): ?int
    {
        return is_string($value) && 1 === preg_match('/^\d{1,6}$/D', $value) ? (int) $value : null;
    }
}
