<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TetraminatorNotifyFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The return page never shows what a gateway's verification said: those messages are for logs and admins. The
 * customer sees the display state's own text.
 */
final class ReturnPageVerificationTextTest extends IntegrationTestCase
{
    use TetraminatorNotifyFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTetraminator();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function inquiryAnswers(): iterable
    {
        yield 'not paid' => [self::tetraUnpaid(), PaymentStatus::PENDING];
        yield 'unknown payment_status' => [['status' => 200, 'body' => ['status' => true, 'payment_status' => 'on_hold_review']], PaymentStatus::PENDING];
        yield 'HTTP 500' => [['status' => 500, 'body' => ['message' => 'internal provider failure']], PaymentStatus::PENDING];
        yield 'network error' => [['transport' => 'SSL handshake to api.tetraminator.com failed'], PaymentStatus::PENDING];
        yield 'paid, another amount (review)' => [self::tetraPaid(160000), PaymentStatus::NEEDS_REVIEW];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('inquiryAnswers')]
    public function testTetraminatorShowsOnlyTheDisplayStateText(array $answer, string $status): void
    {
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([$answer]);

        $this->client->request('GET', PaymentReturnUrl::path($payment));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertCount(1, $this->tetraminatorCalls(), 'the page still asks, as before');
        self::assertSame($status, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
        foreach (['Tetraminator', 'tetraminator', 'payment_status', 'on_hold_review', 'HTTP', 'inquiry', 'internal provider failure', 'SSL', 'manual review', self::TETRA_PAY_ID, 'وضعیت فعلی', 'وضعیت:'] as $text) {
            self::assertStringNotContainsString($text, $html);
        }
        if (PaymentStatus::PENDING === $status) {
            self::assertStringContainsString('پرداخت شما هنوز توسط درگاه تایید نشده است', $html);
        }
    }

    public function testAnotherGatewaysRawVerificationMessageIsNotShownEither(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm']);
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound())), $gateway, PaymentStatus::PENDING)
            ->setGatewayTransactionId('trk-77')->setAuthority('trk-77');
        $this->em->flush();
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 202, 'message' => 'RAW-PROVIDER-TEXT order not paid']);

        $this->client->request('GET', PaymentReturnUrl::path($payment));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('RAW-PROVIDER-TEXT', (string) $this->client->getResponse()->getContent());
    }
}
