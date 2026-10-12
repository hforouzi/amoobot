<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Dev and test run MariaDB/MySQL in strict mode (config/packages/doctrine.yaml), like production
 * must: over-long or invalid values are errors, never silently truncated.
 */
final class StrictSqlModeTest extends IntegrationTestCase
{
    public function testSessionIsStrict(): void
    {
        $mode = (string) $this->em->getConnection()->fetchOne('SELECT @@SESSION.sql_mode');

        self::assertContains('STRICT_TRANS_TABLES', explode(',', $mode), $mode);
    }

    public function testOverLongValueIsRejectedNotTruncated(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $payment = $this->createPayment($order);

        $this->expectException(DriverException::class);
        $this->em->getConnection()->executeStatement('UPDATE payment SET amount_flag = ? WHERE id = ?', [str_repeat('x', 33), $payment->getId()]);
    }

    public function testJunkFromAProviderStillEndsInReviewInsteadOfAFailedSettlement(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $longCurrency = str_repeat('Z', 40);
        $verification = new PaymentVerificationResult(success: true, paid: true, amount: '1500000', currency: $longCurrency);

        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'test');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome, $result->message);
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status']);
        self::assertSame(str_repeat('Z', 15).'…', $row['verified_currency'], 'descriptive value clipped explicitly');
        self::assertStringContainsString($longCurrency, (string) $row['review_reason'], 'the full reported value is kept in the review reason');
    }

    public function testDescriptiveProviderValuesAreClippedButReferencesAreNot(): void
    {
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()));
        $payment->setCryptoPaymentStatus(str_repeat('s', 100))->setConfirmationSource(str_repeat('c', 100));
        $this->em->flush();

        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(64, mb_strlen((string) $row['crypto_payment_status']));
        self::assertSame(64, mb_strlen((string) $row['confirmation_source']));

        $this->expectException(DriverException::class);
        $payment->setCryptoPaymentId(str_repeat('9', 129));
        $this->em->flush();
    }
}
