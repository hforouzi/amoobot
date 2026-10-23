<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Application\AdminPaymentAlerter;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Second review, important 1: a wallet settlement used to read "reversal_of_id = ? LOCK IN SHARE MODE" for a row that
 * does not exist; under REPEATABLE READ that is a GAP lock, held for the whole settlement including the panel call.
 * It blocked other customers' ledger writes and deadlocked with the same customer's next wallet write; the deadlock's
 * failed rollback then lost the settlement's state (reported PROVISIONING_FAILED, stored as pending).
 *
 * A wallet payment is settled in a subprocess with a slow panel while this process writes to the ledger.
 */
final class WalletSettlementLockTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;
    use SubprocessAssertions;

    private const PANEL_DELAY_MS = 3000;
    /** Far below the panel delay: a write that waited for the settlement takes about PANEL_DELAY_MS. */
    private const NOT_BLOCKED_SECONDS = 1.5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
    }

    private function wallets(): WalletService
    {
        return self::getContainer()->get(WalletService::class);
    }

    private function fund(User $user, int $amount): void
    {
        $this->wallets()->credit($user, $amount, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    /** Starts pay() of the order in a subprocess with a slow panel; returns once its settlement is calling the panel. */
    private function slowPayInBackground(int $orderId): Process
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $process = new Process([$php, dirname(__DIR__).'/Support/bin/wallet-pay.php', (string) $orderId], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => (string) self::PANEL_DELAY_MS], null, 120);
        $process->start();
        $connection = $this->em->getConnection();
        $deadline = microtime(true) + 60;
        while (false === $connection->fetchOne("SELECT id FROM payment WHERE order_id = ? AND method = 'wallet'", [$orderId])) {
            if (microtime(true) > $deadline || !$process->isRunning()) {
                self::fail('The subprocess did not open its wallet payment: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(50_000);
        }
        usleep(800_000); // settle(): order/payment locks, the debit check, then the panel sleeps PANEL_DELAY_MS

        return $process;
    }

    /** @return array{float, string} seconds, outcome */
    private function timed(\Closure $write): array
    {
        $start = microtime(true);
        $outcome = (string) $write();

        return [microtime(true) - $start, $outcome];
    }

    /**
     * Another customer's top-up credit during the panel call. Its key "other:..." sorts just below the settlement's
     * debit key "purchase:payment:<id>" in uniq_wallet_transaction_key, so any lock the settlement holds on the gap
     * below its debit makes this insert wait for the whole panel call. Two did: the locking read of the missing
     * reversal (reversal_of_id, a gap lock), and, on MariaDB 10.6+ (CI runs 10.11), the "LOCK IN SHARE MODE" read of the
     * existing debit itself, which takes a next-key lock (record + the gap below it) there; MariaDB 10.8 locks only the
     * record, so this test can pass on 10.8 with that read and fails on 10.6+.
     */
    public function testAnotherCustomersTopUpCreditDoesNotWaitForTheSettlementsPanelCall(): void
    {
        $other = $this->createUser();
        $this->fund($this->user, 200000);
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);

        $process = $this->slowPayInBackground((int) $order->getId());
        [$seconds] = $this->timed(fn () => $this->wallets()->credit($other, 1000, WalletTransactionType::TOPUP, 'other:'.random_int(1, PHP_INT_MAX), WalletActor::system())->getId());
        $process->wait();

        self::assertSame(SettlementOutcome::CONFIRMED->value, self::assertSubprocessClean($process, 'wallet-pay.php'));
        self::assertLessThan(self::NOT_BLOCKED_SECONDS, $seconds, sprintf('another customer\'s credit waited %.2fs for the settlement', $seconds));
    }

    /** Steady state (older reversals exist): another customer's cancel of a newer wallet payment does not wait. */
    public function testAnotherCustomersCancelDoesNotWaitForTheSettlementsPanelCall(): void
    {
        $other = $this->createUser();
        $this->fund($this->user, 200000);
        $this->fund($other, 400000);
        $old = $this->createOrder($other, $this->plan, OrderType::NEW_SERVICE, 10000);
        self::getContainer()->get(WalletPayment::class)->open($old);
        self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($old);

        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $process = $this->slowPayInBackground((int) $order->getId());
        $newer = $this->createOrder($other, $this->plan, OrderType::NEW_SERVICE, 20000);
        self::getContainer()->get(WalletPayment::class)->open($newer);
        [$seconds, $outcome] = $this->timed(fn () => self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($newer)->outcome->value);
        $process->wait();

        self::assertSame(SettlementOutcome::CONFIRMED->value, self::assertSubprocessClean($process, 'wallet-pay.php'));
        self::assertSame(SettlementOutcome::REJECTED->value, $outcome);
        self::assertLessThan(self::NOT_BLOCKED_SECONDS, $seconds, sprintf('another customer\'s cancel waited %.2fs for the settlement', $seconds));
    }

    /** Same customer, a second order paid from the wallet while the first one is being provisioned: both succeed. */
    public function testTheSameCustomerPayingASecondOrderDuringASettlementDoesNotDeadlock(): void
    {
        $this->fund($this->user, 400000);
        $first = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $second = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);

        $process = $this->slowPayInBackground((int) $first->getId());
        $outcome = self::getContainer()->get(WalletPayment::class)->pay($second)->outcome->value;
        $process->wait();

        self::assertSame(SettlementOutcome::CONFIRMED->value, self::assertSubprocessClean($process, 'wallet-pay.php'), 'the first order');
        self::assertSame(SettlementOutcome::CONFIRMED->value, $outcome, 'the second order');
        $connection = $this->em->getConnection();
        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM payment WHERE status = 'confirmed'"));
        self::assertSame(100000, (int) $connection->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));
    }

    /**
     * The deterministic form of the old deadlock: while order 1 is provisioned, the same customer cancels a newer
     * wallet order. The cancel locks the customer's user row and writes a reversal (in the old gap); provisioning
     * order 1 then inserts its service (a foreign key check on that user row). Before: one of them was killed.
     */
    public function testTheSameCustomerCancellingASecondOrderDuringASettlementDoesNotDeadlock(): void
    {
        $this->fund($this->user, 400000);
        // An older reversal, so opening the second payment below is never itself in the old gap.
        $old = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 10000);
        self::getContainer()->get(WalletPayment::class)->open($old);
        self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($old);
        $first = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);

        $process = $this->slowPayInBackground((int) $first->getId());
        $second = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 100000);
        self::getContainer()->get(WalletPayment::class)->open($second);
        $cancel = self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($second)->outcome->value;
        $process->wait();

        self::assertSame(SettlementOutcome::CONFIRMED->value, self::assertSubprocessClean($process, 'wallet-pay.php'), 'the first order');
        self::assertSame(SettlementOutcome::REJECTED->value, $cancel, 'the cancel of the second order');
        self::assertSame(250000, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));
    }

    /**
     * What the deadlock did: the database kills the transaction in the middle of the ORM's flush (a savepoint), so
     * rolling back fails. The connection is closed, the provisioning failure is then really stored (not a savepoint
     * of a dead transaction), and the admins are alerted.
     */
    public function testARollbackThatFailsDoesNotLoseTheSettlementsState(): void
    {
        $this->fund($this->user, 200000);
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $killer = new class {
            public bool $armed = true;

            public function onFlush(OnFlushEventArgs $args): void
            {
                $em = $args->getObjectManager();
                foreach ($em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($this->armed && $entity instanceof VpnService) {
                        $this->armed = false;
                        // The server ends the transaction (as a deadlock victim's is), then the statement fails.
                        $em->getConnection()->executeStatement('ROLLBACK');
                        throw new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $killer);

        try {
            $result = self::getContainer()->get(WalletPayment::class)->pay($order);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::onFlush, $killer);
        }

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        // An independent connection: what is really committed.
        [$host, $port, $database, $user, $password] = $this->databaseParams();
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database), $user, $password);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $pdo->query('SELECT status FROM payment WHERE order_id = '.(int) $order->getId())->fetchColumn());
        self::assertSame('provisioning_failed', $pdo->query('SELECT status FROM `order` WHERE id = '.(int) $order->getId())->fetchColumn());
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $alerts = array_filter($this->http->telegramTexts(), static fn (string $text): bool => str_contains($text, '🚨 پرداخت تایید شد اما ساخت سرویس ناموفق بود'));
        self::assertNotSame([], $alerts, 'the admins were alerted');
    }

    /** The rollback fails and the payment stays pending (failure before fulfilment): the admins are told, not silence. */
    public function testARollbackThatFailsBeforeFulfilmentAlertsTheAdmins(): void
    {
        $this->fund($this->user, 200000);
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 150000);
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        $killer = new class {
            public bool $armed = true;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->armed) {
                    // The first flush of the settlement (in lock(), before fulfilment) dies with its transaction.
                    $this->armed = false;
                    $args->getObjectManager()->getConnection()->executeStatement('ROLLBACK');
                    throw new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
                }
            }
        };
        $paymentEntity = $this->em->find(\App\Entity\Payment::class, $payment->getId()) ?? throw new \LogicException();
        $paymentEntity->setAdminNote('touched'); // gives the settlement's first flush something to write
        $this->em->getEventManager()->addEventListener(Events::onFlush, $killer);
        try {
            $result = self::getContainer()->get(PaymentSettlementService::class)->settle($paymentEntity, \App\Payment\Application\Settlement\VerifiedPayment::fromGateway(new \App\Payment\Domain\Dto\PaymentVerificationResult(success: true, paid: true, transactionId: 'wallet:1', amount: '150000', currency: 'IRT')), WalletPayment::SOURCE);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::onFlush, $killer);
        }

        self::assertSame(SettlementOutcome::ERROR, $result->outcome);
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
        $alerts = array_filter($this->http->telegramTexts(), static fn (string $text): bool => str_contains($text, AdminPaymentAlerter::SETTLEMENT_INTERRUPTED_TITLE));
        self::assertCount(1, $alerts, 'the admins were told the pending payment needs a decision');
    }

    /** @return array{string, int, string, string, string} host, port, database, user, password of the test connection */
    private function databaseParams(): array
    {
        $params = $this->em->getConnection()->getParams();

        return [(string) ($params['host'] ?? '127.0.0.1'), (int) ($params['port'] ?? 3306), (string) ($params['dbname'] ?? ''), (string) ($params['user'] ?? 'root'), (string) ($params['password'] ?? '')];
    }
}
