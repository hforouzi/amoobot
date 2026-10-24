<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Admin\UI\Support\CsvCell;
use App\Entity\AdminUser;
use App\Entity\Order;
use App\Entity\Wallet;
use App\Reseller\Application\ResellerRefunds;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\QueryCounter;
use App\Tests\Support\ResellerFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletAdministration;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

/**
 * Second reseller review: M1 (a nonce with a trailing newline / space / NUL is another nonce, refused), L6 (reconcile
 * flags a reseller order without its nonce), L1 (admin wallet writes retried on a deadlock), L2 (reseller page queries
 * stay flat), L3 (CSV cells after leading whitespace), L4 (a held reseller order is never shown as a card-to-card
 * order; Cancel gives the money back once), L5 (the reseller's "not enough money" text), and the daily cap's day.
 */
final class ResellerReview2Test extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppTestTrait;
    use ResellerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->fund(1000000);
        $this->http->reset();
        $this->answerGateways();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function suffixes(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'space' => [' '];
        yield 'NUL' => ["\0"];
    }

    /** M1: through the checkout service. */
    #[DataProvider('suffixes')]
    public function testANonceWithASuffixIsAnotherNonceAndRefused(string $suffix): void
    {
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $altered = new ResellerConfirmation($confirmation->amount, $confirmation->nonce.$suffix);

        $this->assertRefusedWith('confirmation_invalid', fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $altered), 'before the real one');
        $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $confirmation);
        $this->assertRefusedWith('confirmation_invalid', fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $altered), 'after the real one');

        $this->assertOneOrderOneDebit();
    }

    /** M1: through a bot callback (Telegram passes callback data through unchanged). */
    #[DataProvider('suffixes')]
    public function testABotCallbackWithASuffixBuysNothingMore(string $suffix): void
    {
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());
        $pay = $this->lastCallback('rs_buy:');
        $this->pressButton(self::CUSTOMER, $pay);
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, $pay.$suffix);

        $this->assertOneOrderOneDebit();
        self::assertTrue($this->sentTextContains($this->botText('reseller.confirmation_expired')), 'an unknown confirmation shows a fresh one');
    }

    /** M1 sweep: the ledger key and the admin adjustment key refuse a trailing newline too. */
    public function testLedgerAndFormKeysRefuseATrailingNewline(): void
    {
        try {
            $this->wallets()->credit($this->user, 1000, WalletTransactionType::TOPUP, "seed:newline\n", WalletActor::system());
            self::fail('a ledger key with a newline was accepted');
        } catch (\InvalidArgumentException) {
        }
        $administration = self::getContainer()->get(WalletAdministration::class);
        self::assertNull($administration->adjustmentProblem('credit', '1000', 'goodwill', str_repeat('a', 32)));
        self::assertSame('wallet_admin.error.form_expired', $administration->adjustmentProblem('credit', '1000', 'goodwill', str_repeat('a', 32)."\n"));
    }

    /** L6. */
    public function testReconcileFlagsAResellerOrderWithoutItsNonce(): void
    {
        $this->buy();
        self::assertSame(['balances' => [], 'payments' => []], $this->reconcile());
        $this->em->getConnection()->executeStatement('UPDATE `order` SET reseller_nonce = NULL');

        self::assertContains(WalletReconciler::RESELLER_ORDER_WITHOUT_NONCE, array_column($this->reconcile()['payments'], 'problem'));
    }

    /** L1: a freeze that is a deadlock victim twice succeeds on the third attempt; one that keeps failing is refused. */
    public function testAFreezeIsRetriedOnADeadlock(): void
    {
        $this->wallets()->walletFor($this->user);
        $admin = AdminActor::environmentBotAdmin('990001');

        $administration = $this->administrationFailing(2);
        $administration->setFrozen($this->user, true, $admin);
        self::assertSame(WalletStatus::FROZEN, $this->walletStatus(), 'frozen on the third attempt');
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'wallet.freeze'"), 'audited once');

        $administration = $this->administrationFailing(WalletAdministration::DEADLOCK_ATTEMPTS);
        try {
            $administration->setFrozen($this->user, false, $admin);
            self::fail('a write still deadlocked was reported as done');
        } catch (WalletException $e) {
            self::assertSame('busy', $e->error);
        }
        self::assertSame(WalletStatus::FROZEN, $this->walletStatus(), 'nothing changed');
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'wallet.unfreeze'"));
    }

    /** L1: an adjustment retried after a deadlock is applied and audited once. */
    public function testAnAdjustmentIsRetriedOnADeadlockAndAppliedOnce(): void
    {
        $balance = $this->balance();
        $this->administrationFailing(1)->adjust($this->user, 'credit', '5000', 'goodwill', str_repeat('k', 32), AdminActor::environmentBotAdmin('990001'));

        self::assertSame($balance + 5000, $this->balance());
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'adjustment'"));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'wallet.adjust'"));
    }

    /** L1: inside a caller's transaction a deadlock is not retried (that transaction is gone). */
    public function testNoRetryInsideACallersTransaction(): void
    {
        $this->wallets()->walletFor($this->user);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->administrationFailing(1)->setFrozen($this->user, true, AdminActor::environmentBotAdmin('990001'));
            self::fail('retried inside a transaction');
        } catch (WalletException $e) {
            self::assertSame('busy', $e->error);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /** L2: the reseller page's queries do not grow with its orders, refunds and rules. */
    public function testTheResellerPageKeepsAFlatQueryCount(): void
    {
        $this->fund(5000000);
        $this->buy();
        $this->planRule($this->plan, 2500);
        $this->startMiniAppClient();
        $admin = (new AdminUser())->setEmail('l2@shop.test')->setPassword('x')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
        $few = $this->queries('/admin/resellers/'.$this->reseller->getId());

        // queries() cleared the entity manager: work with managed copies again.
        $reseller = $this->em->find(\App\Entity\Reseller::class, $this->reseller->getId()) ?? self::fail();
        $inbound = $this->em->find(\App\Entity\VpnInbound::class, $this->inbound->getId()) ?? self::fail();
        foreach (range(1, 10) as $i) {
            $this->buy();
            $this->em->persist((new \App\Entity\ResellerPlanPrice($reseller, $this->createPlan(100000 + $i * 1000, $inbound)))->usePercent(1000, null));
        }
        $this->em->flush();
        $refunds = self::getContainer()->get(ResellerRefunds::class);
        foreach (array_slice($this->em->getRepository(Order::class)->findBy(['reseller' => $this->reseller]), 0, 3) as $order) {
            $this->em->getConnection()->executeStatement("UPDATE vpn_service SET status = 'suspended' WHERE order_id = ?", [$order->getId()]);
            $refunds->refund($order, 1000, 'customer left', AdminActor::environmentBotAdmin('990001'));
        }
        $many = $this->queries('/admin/resellers/'.$this->reseller->getId());

        self::assertLessThanOrEqual($few + 2, $many, sprintf('reseller page: %d queries with 1 order and 1 rule, %d with 11 orders (3 refunded) and 11 rules', $few, $many));
        $page = $this->client->request('GET', '/admin/resellers/'.$this->reseller->getId());
        self::assertCount(3, $page->filter('[data-refunded]'));
        self::assertCount(11, $page->filter('[data-rule-plan]'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function cells(): iterable
    {
        yield 'space then =' => [' =1+1', "' =1+1"];
        yield 'LF then -' => ["\n-1+1", "'\n-1+1"];
        yield 'CR then +' => ["\r+1", "'\r+1"];
        yield 'TAB then @' => ["\t@SUM(A1)", "'\t@SUM(A1)"];
        yield 'no-break space then =' => ["\u{00A0}=HYPERLINK(\"x\")", "'\u{00A0}=HYPERLINK(\"x\")"];
        yield 'spaces, then text' => ['  Ali shop', '  Ali shop'];
        yield 'a raw TAB alone' => ["\tAli", "'\tAli"];
        yield 'a raw CR alone' => ["\rAli", "'\rAli"];
        yield 'invalid UTF-8 is checked raw' => ["\xff=1", "\xff=1"];
        yield 'empty' => ['', ''];
        yield 'an integer' => [-1, '-1'];
    }

    /** L3. */
    #[DataProvider('cells')]
    public function testCsvCellsAfterLeadingWhitespace(mixed $value, string $expected): void
    {
        self::assertSame($expected, CsvCell::of($value));
    }

    /** L4: "continue my order" on a held reseller order: its state and Cancel, never the card-to-card summary. */
    public function testAHeldResellerOrderIsNotACardToCardOrder(): void
    {
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $order = (new Order())->setUser($this->user)->setPlan($this->plan)->setType(OrderType::NEW_SERVICE)->setAmount($confirmation->amount)->setStatus(OrderStatus::WAITING_PAYMENT)
            ->attributeToReseller($this->reseller, $confirmation->amount, 150000, 75000)->setResellerNonce($confirmation->nonce)->setTrackingCode('TRKHELD2')
            ->setMetadata(['trafficGb' => 50, 'durationDays' => 30, 'priceSnapshot' => ['finalAmount' => $confirmation->amount]]);
        $this->em->persist($order);
        $this->em->flush();
        self::getContainer()->get(WalletPayment::class)->open($order, true);
        $balance = $this->balance();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, 'resume_incomplete_order:'.$order->getId());

        self::assertFalse($this->sentTextContains(self::CARD_NUMBER), 'no card number');
        self::assertTrue($this->sentTextContains($this->botText('reseller.state_held', ['order' => ['trackingCode' => 'TRKHELD2', 'amount' => '120,000', 'hours' => 24]])));
        self::assertContains('cancel_incomplete_order:'.$order->getId(), $this->sentCallbacks());

        $this->pressButton(self::CUSTOMER, 'cancel_incomplete_order:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'incomplete_order_cancel_confirm:order:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'incomplete_order_cancel_confirm:order:'.$order->getId());

        self::assertSame($balance + 120000, $this->balance(), 'given back once');
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'reversal'"));
        self::assertSame(OrderStatus::CANCELLED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        self::assertSame(['balances' => [], 'payments' => []], $this->reconcile());
    }

    /** L5. */
    public function testNotEnoughMoneyAsksToTopUpNotForAnotherMethod(): void
    {
        $this->wallets()->debit($this->user, 990000, WalletTransactionType::ADJUSTMENT, 'drain', WalletActor::system());
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());
        $pay = $this->lastCallback('rs_buy:');
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, $pay);

        self::assertTrue($this->sentTextContains($this->botText('reseller.error.wallet_insufficient')));
        self::assertFalse($this->sentTextContains($this->botText('wallet.pay_insufficient')));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
    }

    /** The daily cap's "today" is the UTC day (Kernel::TIMEZONE), the zone order.created_at is stored in. */
    public function testTheDailyCapUsesTheUtcDay(): void
    {
        $this->reseller->setDailySpendLimit(300000);
        $this->em->flush();
        $this->buy();
        $todayUtc = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ?', [$todayUtc->modify('-1 second')->format('Y-m-d H:i:s')]);
        self::assertSame(300000, $this->checkout()->remainingToday($this->reseller), 'one second before 00:00 UTC: yesterday');
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ?', [$todayUtc->format('Y-m-d H:i:s')]);
        self::assertSame(180000, $this->checkout()->remainingToday($this->reseller), 'at 00:00 UTC: today');
    }

    /**
     * WalletAdministration over a WalletService whose next $deadlocks writes are deadlock victims (rolled back by the
     * database before anything was written), then real.
     */
    private function administrationFailing(int $deadlocks): WalletAdministration
    {
        $container = self::getContainer();
        $wallets = new class($container->get(EntityManagerInterface::class), $container->get(ManagerRegistry::class), new NullLogger(), $deadlocks) extends WalletService {
            public function __construct(EntityManagerInterface $entityManager, ManagerRegistry $registry, NullLogger $logger, private int $deadlocks)
            {
                parent::__construct($entityManager, $registry, $logger);
            }

            public function setStatus(\App\Entity\User $user, string $status, WalletActor $by): Wallet
            {
                $this->deadlockOnce();

                return parent::setStatus($user, $status, $by);
            }

            public function credit(\App\Entity\User $user, int $amount, string $type, string $idempotencyKey, WalletActor $by, ?string $referenceType = null, ?string $referenceId = null, ?string $note = null, ?\Closure $onWritten = null): \App\Entity\WalletTransaction
            {
                $this->deadlockOnce();

                return parent::credit($user, $amount, $type, $idempotencyKey, $by, $referenceType, $referenceId, $note, $onWritten);
            }

            private function deadlockOnce(): void
            {
                if ($this->deadlocks-- > 0) {
                    throw new DeadlockException(new class('Deadlock found when trying to get lock; try restarting transaction', 1213) extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                        public function getSQLState(): string
                        {
                            return '40001';
                        }
                    }, null);
                }
            }
        };

        return new WalletAdministration($wallets, $container->get(AdminAuditLogger::class), $container->get(EntityManagerInterface::class), new NullLogger());
    }

    private function walletStatus(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM wallet WHERE user_id = ?', [$this->user->getId()]);
    }

    private function assertOneOrderOneDebit(): void
    {
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'one order');
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'"), 'one debit');
        self::assertSame(1000000 - 120000, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], $this->reconcile());
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertRefusedWith(string $error, \Closure $call, string $case): void
    {
        try {
            $call();
            self::fail('accepted: '.$case);
        } catch (ResellerException $e) {
            self::assertSame($error, $e->error, $case);
        }
    }

    /**
     * @return array{balances: list<array<string, mixed>>, payments: list<array<string, mixed>>}
     */
    private function reconcile(): array
    {
        return self::getContainer()->get(WalletReconciler::class)->report();
    }

    private function lastCallback(string $prefix): string
    {
        $matching = array_values(array_filter($this->sentCallbacks(), static fn (string $data): bool => str_starts_with($data, $prefix)));
        self::assertNotSame([], $matching, 'no button '.$prefix.'…');

        return $matching[count($matching) - 1];
    }

    private function queries(string $path): int
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $this->em->clear();
        $counter->start();
        $this->client->request('GET', $path);
        $count = $counter->stop();
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return $count;
    }
}
