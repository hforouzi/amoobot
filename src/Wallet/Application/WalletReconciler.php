<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\ResellerStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks the wallets. Read only: it reports, it never changes a wallet, a ledger row or a payment.
 *
 * mismatches(): every wallet's cached balance against its ledger: the balance must equal the sum of the ledger
 * amounts, and the last ledger row's balance_after must equal the balance; every row's balance_after must equal the
 * running sum up to it.
 *
 * paymentMismatches(): payments against the ledger, through the ledger keys the payment flows write (WalletKeys):
 * a confirmed top-up without its credit, a top-up credit whose payment is not confirmed, a live wallet payment without
 * its debit, a rejected payment whose debit was never given back, a debit given back while its payment is still live,
 * a purchase debit without a payment, a held debit whose amount is not its payment's amount, a wallet payment
 * still pending (its debit held) while its order is no longer waiting for payment (paid otherwise, cancelled,
 * expired: nothing would ever give that debit back), and a wallet payment in provisioning_failed / needs_review whose
 * debit is held while its order failed, was cancelled or expired, or another payment of the order is confirmed.
 *
 * Reseller orders (docs/design/reseller-and-claim.md, 4.4): one whose customer is not the reseller's own account (his
 * price on someone else's order, paid from that wallet); one without its confirmation nonce (not created by
 * ResellerCheckout, so not protected against a second delivery); one paid any other way than from the wallet; one whose
 * held wallet debit, amount and wholesale snapshot disagree; one whose wholesale snapshot is below what the pricing
 * allows (min(floor, retail)); a service whose sold-by reseller is not its order's reseller; and the admin refunds
 * (refund:order:N): without their reseller order (or credited to another wallet), above what that order's wallet
 * payment debited, or while the order's service is running again.
 *
 * Claim links (S2, docs/design/reseller-and-claim.md 4.4; listed with the payments, no money involved): an open link
 * whose service is no longer owned by the link's owner (it can never be used), an open link whose open_service_id is
 * not its service (or a closed one that still holds it), an open link of a disabled reseller (the disable revokes them),
 * a claimed link without its ownership event or whose event names other users, a claimed link whose service has
 * another owner with no later ownership event (claim_owner_mismatch), and a service whose current owner is not the one
 * its last ownership event moved it to.
 *
 * report() runs both in one read-only transaction, so every query sees the same snapshot even while payments are
 * being settled (no false alarm from a write that landed between two queries).
 */
final class WalletReconciler
{
    public const TOPUP_NOT_CREDITED = 'topup_not_credited';
    public const TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT = 'topup_credit_without_confirmed_payment';
    public const WALLET_PAYMENT_WITHOUT_DEBIT = 'wallet_payment_without_debit';
    public const DEBIT_NOT_RETURNED = 'debit_not_returned';
    public const REVERSAL_OF_LIVE_PAYMENT = 'reversal_of_live_payment';
    public const PURCHASE_WITHOUT_PAYMENT = 'purchase_without_payment';
    public const PENDING_WALLET_PAYMENT_ORDER_NOT_WAITING = 'pending_wallet_payment_order_not_waiting';
    public const DEBIT_AMOUNT_MISMATCH = 'debit_amount_mismatch';
    public const HELD_DEBIT_ORDER_CLOSED = 'held_debit_order_closed';
    public const HELD_DEBIT_ORDER_PAID_OTHERWISE = 'held_debit_order_paid_otherwise';
    public const RESELLER_ORDER_NOT_WALLET_PAID = 'reseller_order_not_wallet_paid';
    public const RESELLER_DEBIT_SNAPSHOT_MISMATCH = 'reseller_debit_snapshot_mismatch';
    public const RESELLER_ORDER_BELOW_FLOOR = 'reseller_order_below_floor';
    public const RESELLER_SERVICE_ATTRIBUTION_MISMATCH = 'reseller_service_attribution_mismatch';
    public const RESELLER_ORDER_WRONG_CUSTOMER = 'reseller_order_wrong_customer';
    public const RESELLER_ORDER_WITHOUT_NONCE = 'reseller_order_without_nonce';
    public const REFUND_WITHOUT_ORDER = 'refund_without_order';
    public const REFUND_EXCEEDS_PAID = 'refund_exceeds_paid';
    public const REFUND_ON_LIVE_SERVICE = 'refund_on_live_service';
    public const CLAIM_OPEN_OWNER_CHANGED = 'claim_open_owner_changed';
    public const CLAIM_OPEN_SERVICE_MISMATCH = 'claim_open_service_mismatch';
    public const CLAIM_OPEN_RESELLER_DISABLED = 'claim_open_reseller_disabled';
    public const CLAIM_WITHOUT_OWNERSHIP_EVENT = 'claim_without_ownership_event';
    public const CLAIM_OWNER_MISMATCH = 'claim_owner_mismatch';
    public const OWNERSHIP_EVENT_OWNER_MISMATCH = 'ownership_event_owner_mismatch';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{balances: list<array{walletId: int, userId: int, problem: string, cached: int, expected: int}>, payments: list<array{problem: string, paymentId: int|null, transactionId: int|null, walletId: int|null, detail: string}>}
     */
    public function report(): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            return ['balances' => $this->mismatches(), 'payments' => $this->paymentMismatches()];
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return list<array{walletId: int, userId: int, problem: string, cached: int, expected: int}>
     */
    public function mismatches(): array
    {
        $connection = $this->entityManager->getConnection();
        $mismatches = [];

        $rows = $connection->fetchAllAssociative(
            'SELECT w.id, w.user_id, w.balance, COALESCE(SUM(t.amount), 0) AS ledger_sum,
                    (SELECT l.balance_after FROM wallet_transaction l WHERE l.wallet_id = w.id ORDER BY l.id DESC LIMIT 1) AS last_balance_after
             FROM wallet w LEFT JOIN wallet_transaction t ON t.wallet_id = w.id
             GROUP BY w.id, w.user_id, w.balance
             ORDER BY w.id'
        );
        foreach ($rows as $row) {
            $base = ['walletId' => (int) $row['id'], 'userId' => (int) $row['user_id'], 'cached' => (int) $row['balance']];
            if ((int) $row['balance'] !== (int) $row['ledger_sum']) {
                $mismatches[] = $base + ['problem' => 'balance', 'expected' => (int) $row['ledger_sum']];
            }
            if (null !== $row['last_balance_after'] && (int) $row['balance'] !== (int) $row['last_balance_after']) {
                $mismatches[] = $base + ['problem' => 'last_balance_after', 'expected' => (int) $row['last_balance_after']];
            }
        }

        // Every row's running balance (ledger rows are in id order per wallet).
        $running = [];
        $result = $connection->executeQuery('SELECT t.id, t.wallet_id, w.user_id, t.amount, t.balance_after FROM wallet_transaction t JOIN wallet w ON w.id = t.wallet_id ORDER BY t.wallet_id, t.id');
        while (false !== ($row = $result->fetchAssociative())) {
            $walletId = (int) $row['wallet_id'];
            $running[$walletId] = ($running[$walletId] ?? 0) + (int) $row['amount'];
            if ($running[$walletId] !== (int) $row['balance_after']) {
                $mismatches[] = ['walletId' => $walletId, 'userId' => (int) $row['user_id'], 'problem' => sprintf('balance_after of transaction #%d', (int) $row['id']), 'cached' => (int) $row['balance_after'], 'expected' => $running[$walletId]];
                // Continue from the stored value so one bad row is reported once, not for every later row.
                $running[$walletId] = (int) $row['balance_after'];
            }
        }

        return $mismatches;
    }

    /**
     * @return list<array{problem: string, paymentId: int|null, transactionId: int|null, walletId: int|null, detail: string}>
     */
    public function paymentMismatches(): array
    {
        $connection = $this->entityManager->getConnection();
        $parameters = [
            'confirmed' => PaymentStatus::CONFIRMED,
            'rejected' => PaymentStatus::REJECTED,
            'walletTopUp' => OrderType::WALLET_TOPUP,
            'wallet' => Payment::METHOD_WALLET,
            'topup' => WalletTransactionType::TOPUP,
            'purchase' => WalletTransactionType::PURCHASE,
            'pending' => PaymentStatus::PENDING,
            'submitted' => PaymentStatus::SUBMITTED,
            'waitingPayment' => OrderStatus::WAITING_PAYMENT,
            'provisioningFailed' => PaymentStatus::PROVISIONING_FAILED,
            'needsReview' => PaymentStatus::NEEDS_REVIEW,
            'orderFailed' => OrderStatus::FAILED,
            'orderCancelled' => OrderStatus::CANCELLED,
            'orderExpired' => OrderStatus::EXPIRED,
            'refund' => WalletTransactionType::REFUND,
            'serviceSuspended' => VpnServiceStatus::SUSPENDED,
            'serviceDeleted' => VpnServiceStatus::DELETED,
            'claimOpen' => ClaimStatus::OPEN,
            'claimClaimed' => ClaimStatus::CLAIMED,
            'resellerDisabled' => ResellerStatus::DISABLED,
        ];
        // The ledger keys of the payment flows (WalletKeys): 'topup:payment:<id>' and 'purchase:payment:<id>'.
        $topUpKey = "CONCAT('topup:payment:', p.id)";
        $purchaseKey = "CONCAT('purchase:payment:', p.id)";
        // An admin refund of a reseller order (WalletKeys::orderRefund): 'refund:order:<id>' (the id starts at character 14).
        $refundOrder = "o.id = CAST(SUBSTRING(t.idempotency_key, 14) AS UNSIGNED) AND t.idempotency_key = CONCAT('refund:order:', o.id)";

        $checks = [
            self::TOPUP_NOT_CREDITED => "SELECT p.id AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('confirmed top-up of order #', o.id, ' has no credit in the ledger') AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 LEFT JOIN wallet_transaction c ON c.idempotency_key = $topUpKey
                 WHERE o.type = :walletTopUp AND p.status = :confirmed AND c.id IS NULL",
            self::TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT => "SELECT p.id AS payment_id, c.id AS transaction_id, c.wallet_id, CONCAT('credit of ', c.amount, ' but the payment is ', COALESCE(p.status, 'missing')) AS detail
                 FROM wallet_transaction c
                 LEFT JOIN payment p ON p.id = CAST(SUBSTRING(c.idempotency_key, 15) AS UNSIGNED) AND c.idempotency_key = $topUpKey
                 WHERE c.type = :topup AND c.idempotency_key LIKE 'topup:payment:%' AND (p.id IS NULL OR p.status <> :confirmed)",
            self::WALLET_PAYMENT_WITHOUT_DEBIT => "SELECT p.id AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('wallet payment is ', p.status, ' and has no debit in the ledger') AS detail
                 FROM payment p
                 LEFT JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 WHERE p.method = :wallet AND p.status <> :rejected AND d.id IS NULL",
            self::DEBIT_NOT_RETURNED => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('payment is rejected; debit of ', -d.amount, ' was never given back') AS detail
                 FROM payment p
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE p.status = :rejected AND r.id IS NULL",
            self::REVERSAL_OF_LIVE_PAYMENT => "SELECT p.id AS payment_id, r.id AS transaction_id, r.wallet_id, CONCAT('debit given back but the payment is ', p.status) AS detail
                 FROM payment p
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE p.status <> :rejected",
            self::PURCHASE_WITHOUT_PAYMENT => "SELECT NULL AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('purchase debit ', d.idempotency_key, ' has no payment') AS detail
                 FROM wallet_transaction d
                 LEFT JOIN payment p ON p.id = CAST(SUBSTRING(d.idempotency_key, 18) AS UNSIGNED) AND d.idempotency_key = $purchaseKey
                 WHERE d.type = :purchase AND p.id IS NULL",
            // open() debits the payment's payable amount; a held debit of any other amount took the wrong sum.
            self::DEBIT_AMOUNT_MISMATCH => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('held debit of ', -d.amount, ' but the payment is for ', COALESCE(p.payable_amount, p.amount)) AS detail
                 FROM payment p
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE r.id IS NULL AND -d.amount <> COALESCE(p.payable_amount, p.amount)",
            // A wallet payment kept for an admin decision (retry / review) still holds its debit, but its order can no longer
            // be paid (failed, cancelled, expired, e.g. after an edit of the order) or another payment of it is confirmed
            // (the customer paid twice): nothing would ever give that debit back.
            self::HELD_DEBIT_ORDER_CLOSED => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('wallet payment is ', p.status, ' with its debit of ', -d.amount, ' held but order #', o.id, ' is ', o.status) AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE p.method = :wallet AND p.status IN (:provisioningFailed, :needsReview) AND r.id IS NULL AND o.status IN (:orderFailed, :orderCancelled, :orderExpired)",
            self::HELD_DEBIT_ORDER_PAID_OTHERWISE => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('wallet payment is ', p.status, ' with its debit of ', -d.amount, ' held but payment #', MIN(c.id), ' of order #', p.order_id, ' is confirmed') AS detail
                 FROM payment p
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 JOIN payment c ON c.order_id = p.order_id AND c.id <> p.id AND c.status = :confirmed
                 WHERE p.method = :wallet AND p.status IN (:provisioningFailed, :needsReview) AND r.id IS NULL
                 GROUP BY p.id, d.id, d.wallet_id, p.status, d.amount, p.order_id",
            self::PENDING_WALLET_PAYMENT_ORDER_NOT_WAITING => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('wallet payment is ', p.status, ' but order #', o.id, ' is ', o.status) AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 LEFT JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 WHERE p.method = :wallet AND p.status IN (:pending, :submitted) AND o.status <> :waitingPayment",
            // A reseller order is paid from the wallet only (no gateway is ever offered for it).
            self::RESELLER_ORDER_NOT_WALLET_PAID => "SELECT p.id AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('reseller order #', o.id, ' has a ', p.method, ' payment (', p.status, ')') AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 WHERE o.reseller_id IS NOT NULL AND p.method <> :wallet",
            // The wholesale snapshot is what is paid: the held debit, the order amount and the snapshot are one number.
            self::RESELLER_DEBIT_SNAPSHOT_MISMATCH => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('reseller order #', o.id, ': debit ', -d.amount, ', order amount ', o.amount, ', wholesale snapshot ', COALESCE(o.wholesale_amount, 'missing')) AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE o.reseller_id IS NOT NULL AND r.id IS NULL
                   AND (o.wholesale_amount IS NULL OR -d.amount <> o.wholesale_amount OR o.amount <> o.wholesale_amount)",
            // ResellerPricing never goes below min(floor, retail): retail wins only during a shop-wide promotion.
            self::RESELLER_ORDER_BELOW_FLOOR => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('reseller order #', o.id, ' (', o.status, '): wholesale ', COALESCE(o.wholesale_amount, 'missing'), ', floor ', COALESCE(o.reseller_floor_amount, 'missing'), ', retail ', COALESCE(o.retail_amount, 'missing'), ', amount ', o.amount) AS detail
                 FROM `order` o
                 WHERE o.reseller_id IS NOT NULL
                   AND (o.wholesale_amount IS NULL OR o.reseller_floor_amount IS NULL OR o.retail_amount IS NULL
                        OR o.wholesale_amount < LEAST(o.reseller_floor_amount, o.retail_amount) OR o.amount <> o.wholesale_amount)",
            // A reseller buys for himself (ResellerCheckout puts his own account on the order): anything else is his price
            // on someone else's order.
            self::RESELLER_ORDER_WRONG_CUSTOMER => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('reseller order #', o.id, ' belongs to customer #', o.user_id, ' but reseller #', r.id, ' is customer #', r.user_id) AS detail
                 FROM `order` o JOIN reseller r ON r.id = o.reseller_id
                 WHERE o.user_id <> r.user_id",
            // ResellerCheckout stores the confirmation's nonce on every reseller order (unique per reseller).
            self::RESELLER_ORDER_WITHOUT_NONCE => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('reseller order #', o.id, ' (', o.status, ') has no confirmation nonce') AS detail
                 FROM `order` o
                 WHERE o.reseller_id IS NOT NULL AND o.reseller_nonce IS NULL",
            self::RESELLER_SERVICE_ATTRIBUTION_MISMATCH => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('service #', s.id, ' is sold by reseller ', COALESCE(s.sold_by_reseller_id, 'none'), ' but its order #', o.id, ' is of reseller ', COALESCE(o.reseller_id, 'none')) AS detail
                 FROM vpn_service s JOIN `order` o ON o.id = s.order_id
                 WHERE NOT (s.sold_by_reseller_id <=> o.reseller_id)",
            self::REFUND_WITHOUT_ORDER => "SELECT NULL AS payment_id, t.id AS transaction_id, t.wallet_id, CONCAT('refund ', t.idempotency_key, ' has no reseller order of this wallet''s customer') AS detail
                 FROM wallet_transaction t JOIN wallet w ON w.id = t.wallet_id
                 LEFT JOIN `order` o ON $refundOrder AND o.reseller_id IS NOT NULL AND o.user_id = w.user_id
                 WHERE t.idempotency_key LIKE 'refund:order:%' AND o.id IS NULL",
            self::REFUND_EXCEEDS_PAID => "SELECT p.id AS payment_id, t.id AS transaction_id, t.wallet_id, CONCAT('refund of ', t.amount, ' for order #', o.id, ' but its wallet payment debited ', COALESCE(-d.amount, 0)) AS detail
                 FROM wallet_transaction t JOIN `order` o ON $refundOrder
                 LEFT JOIN payment p ON p.order_id = o.id AND p.method = :wallet AND p.status = :confirmed
                 LEFT JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 WHERE t.type = :refund AND (d.id IS NULL OR t.amount > -d.amount)",
            // Refunded because the customer no longer had the service; it is running again (a warning to look at).
            self::REFUND_ON_LIVE_SERVICE => "SELECT NULL AS payment_id, t.id AS transaction_id, t.wallet_id, CONCAT('refund for order #', o.id, ' but service #', s.id, ' is ', s.status) AS detail
                 FROM wallet_transaction t JOIN `order` o ON $refundOrder
                 JOIN vpn_service s ON s.id = COALESCE(o.target_service_id, (SELECT MIN(s2.id) FROM vpn_service s2 WHERE s2.order_id = o.id))
                 WHERE t.type = :refund AND s.status NOT IN (:serviceSuspended, :serviceDeleted)",
            // Claim links. An open link is only usable while the service still belongs to the link's owner.
            self::CLAIM_OPEN_OWNER_CHANGED => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('open claim link #', c.id, ' of service #', s.id, ' is from customer #', c.from_user_id, ' but the service belongs to customer #', s.user_id) AS detail
                 FROM service_claim c JOIN vpn_service s ON s.id = c.service_id
                 WHERE c.status = :claimOpen AND s.user_id <> c.from_user_id",
            self::CLAIM_OPEN_SERVICE_MISMATCH => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('claim link #', c.id, ' is ', c.status, ' with open_service_id ', COALESCE(c.open_service_id, 'NULL'), ' (service #', c.service_id, ')') AS detail
                 FROM service_claim c
                 WHERE (c.status = :claimOpen AND NOT (c.open_service_id <=> c.service_id)) OR (c.status <> :claimOpen AND c.open_service_id IS NOT NULL)",
            self::CLAIM_OPEN_RESELLER_DISABLED => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('open claim link #', c.id, ' of disabled reseller #', r.id) AS detail
                 FROM service_claim c JOIN reseller r ON r.id = c.reseller_id
                 WHERE c.status = :claimOpen AND r.status = :resellerDisabled",
            self::CLAIM_WITHOUT_OWNERSHIP_EVENT => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('claimed link #', c.id, ' of service #', c.service_id, ' has no matching ownership event') AS detail
                 FROM service_claim c
                 LEFT JOIN service_ownership_event e ON e.claim_id = c.id AND e.service_id = c.service_id AND e.from_user_id = c.from_user_id AND e.to_user_id = c.claimed_by_user_id
                 WHERE c.status = :claimClaimed AND e.id IS NULL",
            self::CLAIM_OWNER_MISMATCH => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('service #', s.id, ' was claimed by customer #', c.claimed_by_user_id, ' (link #', c.id, ') but belongs to customer #', s.user_id, ' with no later ownership change') AS detail
                 FROM service_claim c JOIN vpn_service s ON s.id = c.service_id
                 JOIN service_ownership_event e ON e.claim_id = c.id
                 WHERE c.status = :claimClaimed AND s.user_id <> c.claimed_by_user_id
                   AND NOT EXISTS (SELECT 1 FROM service_ownership_event l WHERE l.service_id = s.id AND l.id > e.id)",
            self::OWNERSHIP_EVENT_OWNER_MISMATCH => "SELECT NULL AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('service #', s.id, ' belongs to customer #', s.user_id, ' but its last ownership event #', e.id, ' moved it to customer #', e.to_user_id) AS detail
                 FROM service_ownership_event e JOIN vpn_service s ON s.id = e.service_id
                 WHERE e.id = (SELECT MAX(l.id) FROM service_ownership_event l WHERE l.service_id = e.service_id) AND s.user_id <> e.to_user_id",
        ];

        $mismatches = [];
        foreach ($checks as $problem => $sql) {
            foreach ($this->rows($connection, $sql, $parameters) as $row) {
                $mismatches[] = [
                    'problem' => $problem,
                    'paymentId' => null === $row['payment_id'] ? null : (int) $row['payment_id'],
                    'transactionId' => null === $row['transaction_id'] ? null : (int) $row['transaction_id'],
                    'walletId' => null === $row['wallet_id'] ? null : (int) $row['wallet_id'],
                    'detail' => (string) $row['detail'],
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Only the named parameters a query uses (DBAL refuses unused ones).
     *
     * @param array<string, string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Connection $connection, string $sql, array $parameters): array
    {
        $used = array_filter($parameters, static fn (string $name): bool => 1 === preg_match('/:'.$name.'\b/', $sql), ARRAY_FILTER_USE_KEY);

        return $connection->fetchAllAssociative($sql.' ORDER BY 1, 2', $used);
    }
}
