<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\MiniApp\Session\MiniAppSessionManager;
use App\Reseller\Domain\ResellerStatus;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admin changes to resellers (RESELLERS_MANAGE): create a profile for an existing customer (only admins do; there is no
 * self-service request), edit its default discount, daily cap, display name and note, set or remove a per-plan rule,
 * enable and disable. Every change is audited (reseller.*) with the values before and after. Form input arrives as the
 * web form sends it; a refused change saves nothing and names the problem (an admin-domain message key).
 *
 * Disabling takes the reseller row lock first (as ResellerCheckout does), so a purchase in flight either completed
 * before it or sees the profile disabled; it then ends the reseller's Mini App sessions. Services already sold keep
 * running: they are paid for.
 */
final class ResellerAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppSessionManager $sessions,
        private readonly ResellerPricing $pricing,
    ) {
    }

    /**
     * The customer a new profile is for: by user id, or by Telegram id / @username.
     */
    public function findCustomer(string $query): ?User
    {
        $query = ltrim(trim($query), '@');
        if ('' === $query) {
            return null;
        }
        $account = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $query])
            ?? $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['username' => $query]);
        if ($account instanceof TelegramAccount) {
            return $account->getUser();
        }
        $user = ctype_digit($query) ? $this->entityManager->getRepository(User::class)->find((int) $query) : null;

        return $user instanceof User ? $user : null;
    }

    /**
     * @param array<string, mixed> $form discount_percent, daily_spend_limit, display_name, note
     *
     * @return array{field: string, message: string}|null
     */
    public function profileProblem(array $form): ?array
    {
        return match (true) {
            null === self::percentToBp(self::text($form, 'discount_percent'), Reseller::MAX_DISCOUNT_BP) => ['field' => 'discount_percent', 'message' => 'reseller_admin.error.discount'],
            '' !== self::text($form, 'daily_spend_limit') && null === self::positiveAmount(self::text($form, 'daily_spend_limit')) => ['field' => 'daily_spend_limit', 'message' => 'reseller_admin.error.daily_limit'],
            mb_strlen(self::text($form, 'display_name')) > 64 => ['field' => 'display_name', 'message' => 'reseller_admin.error.display_name'],
            mb_strlen(self::text($form, 'note')) > 255 => ['field' => 'note', 'message' => 'reseller_admin.error.note'],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $form see profileProblem()
     *
     * @throws \InvalidArgumentException a message key: nothing was saved
     */
    public function create(User $user, array $form, AdminActor $admin): Reseller
    {
        if (null !== ($problem = $this->profileProblem($form))) {
            throw new \InvalidArgumentException($problem['message']);
        }
        if (null !== $this->entityManager->getRepository(Reseller::class)->findOneBy(['user' => $user])) {
            throw new \InvalidArgumentException('reseller_admin.error.exists');
        }
        $reseller = (new Reseller($user))->setCreatedByAdminId($admin->adminUserId);
        $this->apply($reseller, $form);
        $this->entityManager->persist($reseller);
        $this->entityManager->flush();
        $this->auditLogger->record('reseller.create', 'Reseller', $reseller->getId(), ['customer_id' => $user->getId()] + self::values($reseller), $admin);

        return $reseller;
    }

    /**
     * @param array<string, mixed> $form see profileProblem()
     *
     * @throws \InvalidArgumentException a message key: nothing was saved
     */
    public function update(Reseller $reseller, array $form, AdminActor $admin): void
    {
        if (null !== ($problem = $this->profileProblem($form))) {
            throw new \InvalidArgumentException($problem['message']);
        }
        $before = self::values($reseller);
        $this->apply($reseller, $form);
        $this->entityManager->flush();
        $after = self::values($reseller);
        if ($before !== $after) {
            $this->auditLogger->record('reseller.update', 'Reseller', $reseller->getId(), ['from' => $before, 'to' => $after], $admin);
        }
    }

    /**
     * A per-plan rule: kind "percent" (value in percent, two decimals at most) or "fixed" (Toman, fixed plans only).
     *
     * @return bool whether the floor overrides the new rule (the page warns)
     *
     * @throws \InvalidArgumentException a message key: nothing was saved
     */
    public function setPlanPrice(Reseller $reseller, Plan $plan, string $kind, string $value, AdminActor $admin): bool
    {
        $override = $this->entityManager->getRepository(ResellerPlanPrice::class)->findOneBy(['reseller' => $reseller, 'plan' => $plan]);
        $before = $override instanceof ResellerPlanPrice ? ['discount_bp' => $override->getDiscountBp(), 'fixed_amount' => $override->getFixedAmount()] : null;
        $override ??= new ResellerPlanPrice($reseller, $plan);

        if ('percent' === $kind) {
            $bp = self::percentToBp($value, Reseller::MAX_DISCOUNT_BP) ?? throw new \InvalidArgumentException('reseller_admin.error.discount');
            $override->usePercent($bp, $admin->adminUserId);
        } elseif ('fixed' === $kind) {
            if ($plan->isCustomizable()) {
                throw new \InvalidArgumentException('reseller_admin.error.fixed_on_customizable');
            }
            $amount = self::positiveAmount($value) ?? throw new \InvalidArgumentException('reseller_admin.error.fixed_amount');
            $override->useFixedAmount($amount, $admin->adminUserId);
        } else {
            throw new \InvalidArgumentException('reseller_admin.error.rule_kind');
        }

        $this->entityManager->persist($override);
        $this->entityManager->flush();
        $this->auditLogger->record('reseller.plan_price', 'Reseller', $reseller->getId(), [
            'plan_id' => $plan->getId(),
            'from' => $before,
            'to' => ['discount_bp' => $override->getDiscountBp(), 'fixed_amount' => $override->getFixedAmount()],
        ], $admin);

        return $this->pricing->isClipped($reseller, $plan, $override);
    }

    public function removePlanPrice(Reseller $reseller, Plan $plan, AdminActor $admin): void
    {
        $override = $this->entityManager->getRepository(ResellerPlanPrice::class)->findOneBy(['reseller' => $reseller, 'plan' => $plan]);
        if (!$override instanceof ResellerPlanPrice) {
            return;
        }
        $before = ['discount_bp' => $override->getDiscountBp(), 'fixed_amount' => $override->getFixedAmount()];
        $this->entityManager->remove($override);
        $this->entityManager->flush();
        $this->auditLogger->record('reseller.plan_price', 'Reseller', $reseller->getId(), ['plan_id' => $plan->getId(), 'from' => $before, 'to' => null], $admin);
    }

    public function enable(Reseller $reseller, AdminActor $admin): void
    {
        if ($this->changeStatus($reseller, ResellerStatus::ACTIVE)) {
            $this->auditLogger->record('reseller.enable', 'Reseller', $reseller->getId(), ['customer_id' => $reseller->getUser()->getId()], $admin);
        }
    }

    /**
     * Stops the reseller at once: no purchase can start or finish its order after this (ResellerCheckout re-checks
     * under the same lock), and his Mini App sessions end. Existing services keep running.
     *
     * @return int the Mini App sessions ended
     */
    public function disable(Reseller $reseller, AdminActor $admin): int
    {
        if (!$this->changeStatus($reseller, ResellerStatus::DISABLED)) {
            return 0;
        }
        $telegramId = $reseller->getUser()->getTelegramAccount()?->getTelegramId();
        $sessions = null === $telegramId ? 0 : $this->sessions->revokeAllFor($telegramId);
        $this->auditLogger->record('reseller.disable', 'Reseller', $reseller->getId(), ['customer_id' => $reseller->getUser()->getId(), 'sessions_revoked' => $sessions], $admin);

        return $sessions;
    }

    /** Percent text ("12", "12.5", "12.25") to basis points within 0..$max; null when unreadable or out of range. */
    public static function percentToBp(string $percent, int $max): ?int
    {
        $percent = str_replace(['٫', ','], '.', trim($percent));
        if (1 !== preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $percent, $m)) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $bp <= $max ? $bp : null;
    }

    /** Basis points as a percent for forms and pages ("12.5"). */
    public static function bpToPercent(int $bp): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($bp, 100), $bp % 100), '0'), '.');
    }

    /** Under the reseller row lock; false when it already had that status. */
    private function changeStatus(Reseller $reseller, string $status): bool
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeQuery('SELECT id FROM reseller WHERE id = ? FOR UPDATE', [$reseller->getId()]);
            $this->entityManager->refresh($reseller);
            if ($reseller->getStatus() === $status) {
                $connection->commit();

                return false;
            }
            $reseller->setStatus($status);
            $this->entityManager->flush();
            $connection->commit();

            return true;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $form
     */
    private function apply(Reseller $reseller, array $form): void
    {
        $limit = self::text($form, 'daily_spend_limit');
        $reseller
            ->setDiscountBp((int) self::percentToBp(self::text($form, 'discount_percent'), Reseller::MAX_DISCOUNT_BP))
            ->setDailySpendLimit('' === $limit ? null : self::positiveAmount($limit))
            ->setDisplayName(self::text($form, 'display_name'))
            ->setNote(self::text($form, 'note'));
    }

    /**
     * @return array{discount_bp: int, daily_spend_limit: int|null, display_name: string|null, note: string|null}
     */
    private static function values(Reseller $reseller): array
    {
        return [
            'discount_bp' => $reseller->getDiscountBp(),
            'daily_spend_limit' => $reseller->getDailySpendLimit(),
            'display_name' => $reseller->getDisplayName(),
            'note' => $reseller->getNote(),
        ];
    }

    private static function positiveAmount(string $value): ?int
    {
        $value = str_replace([',', '٬', ' '], '', trim($value));

        return 1 === preg_match('/^\d{1,13}$/', $value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function text(array $form, string $field): string
    {
        $value = $form[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
