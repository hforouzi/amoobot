<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\TrialBackingPlans;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the trial plans' backing plans (TrialBackingPlans: trial_plan.backing_plan_id, or a trial order; never the
 * title) and what is wrong with them: a backing plan must be inactive, at price 0, not customizable / priced per GB or
 * day, and without wholesale rules (docs/design/trial-backing-plans.md). Dry run by default; --apply fixes them, plan by
 * plan under the plan row's lock, and audits each fix. Running it again changes nothing (idempotent).
 *
 * It deletes no plan, order or service: only the wholesale rules of a backing plan (--apply). Plans merely titled
 * "[Trial] ..." that are not backing plans are listed as a note and left alone: they are sales plans.
 */
#[AsCommand(name: 'app:trial-plans:backing-audit', description: 'List trial backing plans and fix inconsistencies (dry run; --apply to write)')]
final class TrialBackingPlansAuditCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TrialBackingPlans $trialBackingPlans,
        private readonly AdminAuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the fixes (without it nothing is changed)')
            ->setHelp(<<<'HELP'
                Lists every backing plan of a trial plan (also those of trial plans deleted earlier, known by their
                trial orders) with its trial plan, orders, services, wholesale rules and discount codes, and the
                problems found:
                  - active                 -> deactivated
                  - priced (price, per GB, per day) or customizable -> price 0, not customizable
                  - wholesale (reseller) rules -> removed
                Nothing is written unless you pass --apply. Running it again after --apply finds nothing to fix.
                Plans only titled "[Trial] ..." that are no trial's backing plan are listed as a note and not touched.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $ids = $this->trialBackingPlans->allIds();

        $io->title($apply ? 'Trial backing plans (applying fixes)' : 'Trial backing plans (dry run)');
        $rows = [];
        $toFix = 0;
        $fixed = 0;
        foreach ($ids as $id) {
            $plan = $this->describe($id);
            if (null === $plan) {
                continue;
            }
            $problems = $this->problems($plan);
            $rows[] = [
                $id,
                $plan['title'],
                null === $plan['trial_id'] ? '- (deleted)' : sprintf('#%d %s', $plan['trial_id'], $plan['trial_title']),
                $plan['orders'],
                $plan['services'],
                $plan['codes'],
                [] === $problems ? 'ok' : implode(', ', $problems),
            ];
            if ([] === $problems) {
                continue;
            }
            ++$toFix;
            if ($apply && $this->fix($id)) {
                ++$fixed;
            }
        }

        if ([] === $rows) {
            $io->writeln('No backing plans.');
        } else {
            $io->table(['Plan', 'Title', 'Trial plan', 'Orders', 'Services', 'Discount codes', 'Problems'], $rows);
        }

        $lookalikes = $this->connection->fetchAllAssociative(
            "SELECT id, title, is_active FROM plan WHERE title LIKE '[Trial]%' ORDER BY id",
        );
        $lookalikes = array_values(array_filter($lookalikes, static fn (array $row): bool => !in_array((int) $row['id'], $ids, true)));
        if ([] !== $lookalikes) {
            $io->note(array_merge(
                ['Plans titled "[Trial] ..." that are no trial\'s backing plan (no trial plan and no trial order refers to them). They are ordinary sales plans and are left alone; rename, deactivate or delete them in Plans if they are leftovers:'],
                array_map(static fn (array $row): string => sprintf('Plan #%d (%s)%s', $row['id'], $row['title'], (int) $row['is_active'] ? '' : ', inactive'), $lookalikes),
            ));
        }

        if (0 === $toFix) {
            $io->success('Nothing to fix.');
        } elseif ($apply) {
            $io->success(sprintf('Fixed %d backing plan(s).', $fixed));
        } else {
            $io->warning(sprintf('%d backing plan(s) to fix. Nothing was changed; run again with --apply to fix them.', $toFix));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{title: string, is_active: int, price: int, price_per_gb: int, price_per_day: int, is_customizable: int, trial_id: int|null, trial_title: string|null, orders: int, services: int, rules: int, codes: int}|null
     */
    private function describe(int $id, bool $lock = false): ?array
    {
        $plan = $this->connection->fetchAssociative(
            'SELECT title, is_active, price, price_per_gb, price_per_day, is_customizable FROM plan WHERE id = ?'.($lock ? ' FOR UPDATE' : ''),
            [$id],
        );
        if (false === $plan) {
            return null;
        }
        $trial = $this->connection->fetchAssociative('SELECT id, title FROM trial_plan WHERE backing_plan_id = ? ORDER BY id LIMIT 1', [$id]);

        return [
            'title' => (string) $plan['title'],
            'is_active' => (int) $plan['is_active'],
            'price' => (int) $plan['price'],
            'price_per_gb' => (int) $plan['price_per_gb'],
            'price_per_day' => (int) $plan['price_per_day'],
            'is_customizable' => (int) $plan['is_customizable'],
            'trial_id' => false === $trial ? null : (int) $trial['id'],
            'trial_title' => false === $trial ? null : (string) $trial['title'],
            'orders' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE plan_id = ?', [$id]),
            'services' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vpn_service s JOIN `order` o ON o.id = s.order_id WHERE o.plan_id = ?', [$id]),
            'rules' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reseller_plan_price WHERE plan_id = ?', [$id]),
            'codes' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM discount_code WHERE plan_id = ?', [$id]),
        ];
    }

    /**
     * @param array{is_active: int, price: int, price_per_gb: int, price_per_day: int, is_customizable: int, rules: int} $plan
     *
     * @return list<string>
     */
    private function problems(array $plan): array
    {
        $problems = [];
        if (1 === $plan['is_active']) {
            $problems[] = 'active';
        }
        if (0 !== $plan['price'] || 0 !== $plan['price_per_gb'] || 0 !== $plan['price_per_day'] || 1 === $plan['is_customizable']) {
            $problems[] = 'priced';
        }
        if ($plan['rules'] > 0) {
            $problems[] = sprintf('%d wholesale rule(s)', $plan['rules']);
        }

        return $problems;
    }

    /** One plan, under its row's lock: read again, fix what is still wrong, audit. False when nothing was left to fix. */
    private function fix(int $id): bool
    {
        return $this->connection->transactional(function (Connection $db) use ($id): bool {
            $plan = $this->describe($id, true);
            if (null === $plan || [] === $this->problems($plan)) {
                return false;
            }
            $db->executeStatement(
                'UPDATE plan SET is_active = 0, price = 0, price_per_gb = NULL, price_per_day = NULL, is_customizable = 0, updated_at = ? WHERE id = ?',
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id],
            );
            $db->executeStatement('DELETE FROM reseller_plan_price WHERE plan_id = ?', [$id]);
            $this->auditLogger->record('trial_plan.backing_plan_fixed', 'Plan', $id, [
                'title' => $plan['title'],
                'problems' => $this->problems($plan),
                'was' => ['isActive' => (bool) $plan['is_active'], 'price' => $plan['price'], 'pricePerGb' => $plan['price_per_gb'], 'pricePerDay' => $plan['price_per_day'], 'isCustomizable' => (bool) $plan['is_customizable']],
                'wholesaleRulesRemoved' => $plan['rules'],
            ]);

            return true;
        });
    }
}
