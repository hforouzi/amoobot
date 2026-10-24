<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\AdminAuditLog;
use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Application\ResellerRefunds;
use App\Security\AdminActor;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletTransactionType;

/**
 * Refunds of reseller orders (design 4.2) and the reconcile checks for resellers (4.4): a refund only for a provisioned
 * wallet-paid reseller order whose service is stopped, at most what was paid, once, with a reason, audited with it;
 * every reseller invariant reconcile checks is reported when broken (and only then).
 */
final class ResellerRefundAndReconcileTest extends IntegrationTestCase
{
    use ResellerFixtures;

    private AdminActor $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->admin = AdminActor::environmentBotAdmin('990001');
        $this->fund(500000);
    }

    public function testARefundFollowsTheRules(): void
    {
        [$order, $service] = $this->bought();
        $refunds = $this->refunds();

        self::assertSame(120000, $refunds->paidAmount($order));
        self::assertSame('reseller_admin.refund.error.service_live', $refunds->problem($order, 120000, 'customer left'));
        $service->setStatus(VpnServiceStatus::SUSPENDED);
        $this->em->flush();
        self::assertSame('reseller_admin.refund.error.amount', $refunds->problem($order, 120001, 'customer left'), 'more than was paid');
        self::assertSame('reseller_admin.refund.error.amount', $refunds->problem($order, '0', 'customer left'));
        self::assertSame('reseller_admin.refund.error.reason', $refunds->problem($order, 50000, 'x'));
        self::assertNull($refunds->problem($order, '50,000', 'customer left'));

        $balance = $this->balance();
        $row = $refunds->refund($order, '50,000', 'customer left', $this->admin);
        self::assertSame(WalletTransactionType::REFUND, $row->getType());
        self::assertSame(WalletKeys::orderRefund((int) $order->getId()), $row->getIdempotencyKey());
        self::assertSame($balance + 50000, $this->balance());
        $audit = $this->em->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'reseller.order_refund']) ?? self::fail('no audit entry');
        self::assertSame(50000, $audit->getDetails()['amount'] ?? null);
        self::assertSame('customer left', $audit->getDetails()['reason'] ?? null);

        self::assertSame('reseller_admin.refund.error.already_refunded', $refunds->problem($order, 10000, 'again please'), 'once per order');
        try {
            $refunds->refund($order, 10000, 'again please', $this->admin);
            self::fail('refunded twice');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('reseller_admin.refund.error.already_refunded', $e->getMessage());
        }
        self::assertSame($balance + 50000, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], $this->reconciler()->report());
    }

    public function testOnlyProvisionedWalletPaidResellerOrdersCanBeRefunded(): void
    {
        $retail = $this->createOrder($this->user, $this->plan);
        self::assertSame('reseller_admin.refund.error.not_reseller_order', $this->refunds()->problem($retail, 1000, 'reason'));

        [$order] = $this->bought();
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'provisioning_failed' WHERE id = ?", [$order->getId()]);
        $this->em->refresh($order);
        self::assertSame('reseller_admin.refund.error.not_provisioned', $this->refunds()->problem($order, 1000, 'reason'));
    }

    public function testReconcileReportsEveryBrokenResellerInvariant(): void
    {
        [$order, $service] = $this->bought();
        $connection = $this->em->getConnection();
        self::assertSame([], $this->problems());

        // A snapshot that does not match what was debited, and one below min(floor, retail).
        $connection->executeStatement('UPDATE `order` SET wholesale_amount = 119000 WHERE id = ?', [$order->getId()]);
        self::assertContains(WalletReconciler::RESELLER_DEBIT_SNAPSHOT_MISMATCH, $this->problems());
        $connection->executeStatement('UPDATE `order` SET wholesale_amount = 120000, reseller_floor_amount = 130000 WHERE id = ?', [$order->getId()]);
        self::assertSame([WalletReconciler::RESELLER_ORDER_BELOW_FLOOR], $this->problems());
        $connection->executeStatement('UPDATE `order` SET reseller_floor_amount = 75000 WHERE id = ?', [$order->getId()]);

        // A service attributed to nobody although its order is a reseller's.
        $connection->executeStatement('UPDATE vpn_service SET sold_by_reseller_id = NULL WHERE id = ?', [$service->getId()]);
        self::assertSame([WalletReconciler::RESELLER_SERVICE_ATTRIBUTION_MISMATCH], $this->problems());
        $connection->executeStatement('UPDATE vpn_service SET sold_by_reseller_id = ? WHERE id = ?', [$this->reseller->getId(), $service->getId()]);

        // A gateway payment on a reseller order.
        $this->createPayment($order, $this->createGateway());
        self::assertSame([WalletReconciler::RESELLER_ORDER_NOT_WALLET_PAID], $this->problems());
        $connection->executeStatement("DELETE FROM payment WHERE method <> 'wallet'");

        // Refunds written outside ResellerRefunds: without a reseller order, above what was paid, on a live service.
        $this->wallets()->credit($this->user, 1000, WalletTransactionType::REFUND, 'refund:order:999999', WalletActor::system());
        self::assertSame([WalletReconciler::REFUND_WITHOUT_ORDER], $this->problems());
        $connection->executeStatement("DELETE FROM wallet_transaction WHERE idempotency_key = 'refund:order:999999'");
        $connection->executeStatement('UPDATE wallet SET balance = balance - 1000');
        $this->wallets()->credit($this->user, 130000, WalletTransactionType::REFUND, WalletKeys::orderRefund((int) $order->getId()), WalletActor::system());
        self::assertSame([WalletReconciler::REFUND_EXCEEDS_PAID, WalletReconciler::REFUND_ON_LIVE_SERVICE], $this->problems());
    }

    /**
     * @return array{Order, VpnService}
     */
    private function bought(): array
    {
        $this->buy();
        $order = $this->em->getRepository(Order::class)->findOneBy(['reseller' => $this->reseller], ['id' => 'DESC']) ?? self::fail('no order');
        $this->em->refresh($order);
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order]) ?? self::fail('no service');

        return [$order, $service];
    }

    /**
     * @return list<string> the payment problems' codes, sorted
     */
    private function problems(): array
    {
        $report = $this->reconciler()->report();
        self::assertSame([], $report['balances'], 'balances stay consistent');
        $codes = array_values(array_unique(array_map(static fn (array $m): string => $m['problem'], $report['payments'])));
        sort($codes);

        return $codes;
    }

    private function refunds(): ResellerRefunds
    {
        return self::getContainer()->get(ResellerRefunds::class);
    }

    private function reconciler(): WalletReconciler
    {
        return self::getContainer()->get(WalletReconciler::class);
    }
}
