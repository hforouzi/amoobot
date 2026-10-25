<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * app:wallet:reconcile also checks the claim-link invariants (read only): every claimed link has its ownership event, a
 * service's owner is the one its last ownership event names, an open link still belongs to its owner, to its service
 * and to an active reseller. A clean history reports nothing; each kind of damage made by hand is found by its code.
 */
final class ClaimReconcileTest extends IntegrationTestCase
{
    use ClaimFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testAHealthyHistoryReportsNothing(): void
    {
        $this->claimWith($this->makeLink()->token, '8701');
        $this->buy();
        $this->makeLink((int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM vpn_service'));

        self::assertSame(['balances' => [], 'payments' => []], $this->reconciler()->report());
    }

    /**
     * @return iterable<string, array{string, \Closure(self): void}>
     */
    public static function damage(): iterable
    {
        yield 'owner changed by hand after a claim' => [WalletReconciler::CLAIM_OWNER_MISMATCH, static function (self $t): void {
            $t->claimWith($t->makeLink()->token, '8701');
            $t->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ?', [$t->claimer('8799')->getId()]);
        }];
        yield 'owner differs from the last ownership event' => [WalletReconciler::OWNERSHIP_EVENT_OWNER_MISMATCH, static function (self $t): void {
            $t->claimWith($t->makeLink()->token, '8701');
            $t->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ?', [$t->user->getId()]);
        }];
        yield 'claimed without its ownership event' => [WalletReconciler::CLAIM_WITHOUT_OWNERSHIP_EVENT, static function (self $t): void {
            $t->claimWith($t->makeLink()->token, '8701');
            $t->em->getConnection()->executeStatement('DELETE FROM service_ownership_event');
        }];
        yield 'open link of a service someone else owns' => [WalletReconciler::CLAIM_OPEN_OWNER_CHANGED, static function (self $t): void {
            $t->makeLink();
            $t->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ?', [$t->claimer('8799')->getId()]);
        }];
        yield 'closed link still holding its service' => [WalletReconciler::CLAIM_OPEN_SERVICE_MISMATCH, static function (self $t): void {
            $t->makeLink();
            $t->em->getConnection()->executeStatement('SET SESSION check_constraint_checks = 0');
            try {
                $t->em->getConnection()->executeStatement("UPDATE service_claim SET status = 'expired'");
            } finally {
                $t->em->getConnection()->executeStatement('SET SESSION check_constraint_checks = 1');
            }
        }];
        yield 'open link of a disabled reseller' => [WalletReconciler::CLAIM_OPEN_RESELLER_DISABLED, static function (self $t): void {
            $t->makeLink();
            $t->em->getConnection()->executeStatement("UPDATE reseller SET status = 'disabled'");
        }];
    }

    /**
     * @param \Closure(self): void $break
     */
    #[DataProvider('damage')]
    public function testEachBrokenInvariantIsReported(string $code, \Closure $break): void
    {
        $break($this);

        $codes = array_column($this->reconciler()->report()['payments'], 'problem');
        self::assertContains($code, $codes);
    }

    private function reconciler(): WalletReconciler
    {
        return self::getContainer()->get(WalletReconciler::class);
    }
}
