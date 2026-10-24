<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Plan;
use App\Payment\Application\Settlement\SettlementResult;
use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerSettings;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;

/**
 * A reseller on top of the Mini App shop fixtures: the shop's customer ($this->user, Telegram id CUSTOMER) becomes a
 * reseller with a 20 % discount, the wallet and resellers are switched on, and the plan costs 150,000 Toman (fixed).
 */
trait ResellerFixtures
{
    use MiniAppShopFixtures;

    protected Reseller $reseller;

    protected function setUpReseller(int $discountBp = 2000): void
    {
        $this->setUpShop();
        $this->setting(WalletSettings::ENABLED, 'true');
        $this->setting(ResellerSettings::ENABLED, 'true');
        $this->reseller = (new Reseller($this->user))->setDiscountBp($discountBp);
        $this->em->persist($this->reseller);
        $this->em->flush();
    }

    protected function checkout(): ResellerCheckout
    {
        return self::getContainer()->get(ResellerCheckout::class);
    }

    /** A new service bought as the bot does it: quote (with its confirmation), then pay with that confirmation. */
    protected function buy(?int $planId = null): SettlementResult
    {
        $planId ??= (int) $this->plan->getId();
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, $planId);

        return $this->checkout()->buyNewService($this->user, $planId, $confirmation);
    }

    protected function renewSold(int $serviceId): SettlementResult
    {
        [, , , $confirmation] = $this->checkout()->quoteRenewal($this->user, $serviceId);

        return $this->checkout()->renew($this->user, $serviceId, $confirmation);
    }

    protected function addTrafficSold(int $serviceId, int $trafficGb): SettlementResult
    {
        [, , , $confirmation] = $this->checkout()->quoteAddTraffic($this->user, $serviceId, $trafficGb);

        return $this->checkout()->addTraffic($this->user, $serviceId, $trafficGb, $confirmation);
    }

    protected function wallets(): WalletService
    {
        return self::getContainer()->get(WalletService::class);
    }

    protected function fund(int $amount): void
    {
        $this->wallets()->credit($this->user, $amount, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    protected function balance(): int
    {
        return (int) ($this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]) ?: 0);
    }

    protected function planRule(Plan $plan, ?int $discountBp, ?int $fixedAmount = null): ResellerPlanPrice
    {
        $rule = new ResellerPlanPrice($this->reseller, $plan);
        null !== $fixedAmount ? $rule->useFixedAmount($fixedAmount, null) : $rule->usePercent((int) $discountBp, null);
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    /** Rows of a table (reseller tests check that a refused purchase left nothing behind). */
    protected function sqlInt(string $sql): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql);
    }
}
