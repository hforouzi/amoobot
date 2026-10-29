<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Reseller;
use App\Entity\User;
use App\Entity\VpnService;
use App\Wallet\Application\WalletActor;
use App\Wallet\Domain\WalletTransactionType;

/**
 * The reseller Mini App (S3) on top of the claim fixtures: resellers and claim links on, the reseller ($this->user,
 * Telegram id CUSTOMER) funded and owning one service he sold ($this->sold), plus a second, unrelated reseller
 * (OTHER_RESELLER) with a service of his own ($this->othersService) for the ownership (IDOR) checks. Requests go
 * through the real Mini App (initData sign-in, session token, CSRF, Idempotency-Key).
 */
trait ResellerMiniAppFixtures
{
    use ClaimFixtures;
    use MiniAppTestTrait;

    protected const OTHER_RESELLER = '8611';

    protected Reseller $otherReseller;
    protected User $otherUser;
    protected VpnService $othersService;

    protected function setUpResellerMiniApp(): void
    {
        $this->setUpClaims();
        $this->em->getConnection()->executeStatement('DELETE FROM reseller_customer_service');
        $this->em->getConnection()->executeStatement('DELETE FROM reseller_customer');
        $this->otherUser = $this->createUser(self::OTHER_RESELLER);
        $this->otherReseller = (new Reseller($this->otherUser))->setDiscountBp(1000);
        $this->em->persist($this->otherReseller);
        $this->em->flush();
        $this->wallets()->credit($this->otherUser, 1_000_000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->otherUser, (int) $this->plan->getId());
        $this->checkout()->buyNewService($this->otherUser, (int) $this->plan->getId(), $confirmation);
        $this->othersService = $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->otherReseller]) ?? self::fail('no service for the other reseller');
        $this->resetClaimState();
        $this->startMiniAppClient();
    }

    /**
     * A purchase as app.js makes it: the call (Idempotency-Key), the 428 confirmation, then the same call with its
     * token (X-Admin-Confirm) and the same key.
     *
     * @param array<string, mixed> $body
     * @param array{token: string, csrf: string} $session
     *
     * @return array{array<string, mixed>, string} the final answer and the confirm token used
     */
    protected function buyThroughApp(array $session, string $path, array $body, ?string $key = null): array
    {
        $key ??= self::newKey();
        $first = $this->appPostJson($path, $session, $body, $key);
        self::assertSame(428, $this->httpStatus(), (string) json_encode($first));
        $token = (string) ($first['confirm']['token'] ?? self::fail('no confirmation'));

        return [$this->appPostJson($path, $session, $body, $key, null, ['HTTP_X_ADMIN_CONFIRM' => $token]), $token];
    }

    protected function soldCount(): int
    {
        return $this->sqlInt('SELECT COUNT(*) FROM vpn_service WHERE sold_by_reseller_id = '.(int) $this->reseller->getId());
    }

    protected function resellerOrders(): int
    {
        return $this->sqlInt('SELECT COUNT(*) FROM `order` WHERE reseller_id = '.(int) $this->reseller->getId());
    }
}
