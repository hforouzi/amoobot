<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\Order;
use App\Entity\Wallet;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Third review, S1: a deadlock inside the wallet debit's savepoint (WalletPayment::open()) makes the server roll the
 * whole transaction back, so the rollback of the savepoint fails. open() used to leave DBAL two levels deep in a
 * transaction the server no longer had and the entity manager closed: the bot worker's NEXT update failed with
 * "SAVEPOINT DOCTRINE_2 does not exist". Now open() rolls back like the settlement (TransactionRollback): the
 * connection is closed and the entity manager reset, and the next operation in the same process works.
 */
final class WalletOpenRollbackFailureTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const PRICE = 150000;
    private const FUNDED = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, self::FUNDED, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    /** An independent connection: it sees only what was really committed. */
    private function outside(): Connection
    {
        return DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    /**
     * The next flush that writes a wallet balance (the debit inside open()): the server-side transaction is rolled back
     * under DBAL's feet, as a deadlock victim's is, and the flush fails.
     */
    private function deadlockTheNextDebit(EntityManagerInterface $em): void
    {
        $em->getEventManager()->addEventListener([Events::onFlush], new class {
            private bool $armed = true;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if (!$this->armed) {
                    return;
                }
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Wallet) {
                        $this->armed = false;
                        // Raw SQL: DBAL does not see it, so it still counts the transaction levels it opened.
                        $args->getObjectManager()->getConnection()->executeStatement('ROLLBACK');

                        throw new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction (simulated)');
                    }
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function walletPayCallback(Order $order, int $updateId): array
    {
        return ['update_id' => $updateId, 'callback_query' => [
            'id' => 'cb-'.$updateId, 'from' => ['id' => (int) self::CUSTOMER, 'first_name' => 'T'],
            'message' => ['message_id' => 1, 'chat' => ['id' => (int) self::CUSTOMER, 'type' => 'private']],
            // The confirmation's "Confirm" button (wallet_pay: only asks; WalletBotHandler).
            'data' => 'wallet_pay_confirm:'.$order->getId(),
        ]];
    }

    public function testTheBotWorkersNextUpdateWorksAfterAFailedRollbackInTheDebit(): void
    {
        $first = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $second = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $handler = self::getContainer()->get(TelegramUpdateHandler::class);
        $outside = $this->outside();
        $this->em->clear();
        $this->deadlockTheNextDebit($em);

        $handler->handle($this->walletPayCallback($first, 1));

        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel(), 'no transaction left that the server does not have');
        self::assertTrue($em->isOpen());
        self::assertSame(0, (int) $outside->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = ?', [$first->getId()]), 'neither the payment nor the debit was written');
        self::assertSame(self::FUNDED, (int) $outside->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));

        // The next update of the same worker process pays the other order, for real (committed).
        $handler->handle($this->walletPayCallback($second, 2));

        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel());
        self::assertSame('confirmed', $outside->fetchOne("SELECT status FROM payment WHERE order_id = ? AND method = 'wallet'", [$second->getId()]));
        self::assertSame('provisioned', $outside->fetchOne('SELECT status FROM `order` WHERE id = ?', [$second->getId()]));
        self::assertSame(self::FUNDED - self::PRICE, (int) $outside->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());

        // And the first order can still be paid from the wallet, once.
        $retry = self::getContainer()->get(WalletPayment::class)->pay($em->find(Order::class, $first->getId()) ?? throw new \LogicException());
        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        self::assertSame(self::FUNDED - 2 * self::PRICE, (int) $outside->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));
    }
}
