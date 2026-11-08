<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Setting;
use App\Payment\Application\Reconcile\TetraminatorReconcileOutcome;
use App\Payment\Application\Reconcile\TetraminatorReconciler;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\UI\TetraminatorNotifyController;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TetraminatorNotifyFixtures;
use App\Wallet\Application\WalletSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Tetraminator's notify endpoint (design.md decision 2): every request gets the same bytes; only a valid token of a
 * Tetraminator payment, inside both limits, causes one inquiry, and only settle() acts on its answer.
 */
final class TetraminatorNotifyTest extends IntegrationTestCase
{
    use TetraminatorNotifyFixtures;

    private KernelBrowser $client;
    private string $clientIp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTetraminator();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        // Every test from its own address: limiter state kept in the cache between runs cannot leak in.
        $this->clientIp = '10.78.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->client->setServerParameter('REMOTE_ADDR', $this->clientIp);
    }

    /**
     * The whole answer as a caller sees it: status, every header but Date, and the body.
     */
    private function notify(string $path): string
    {
        $this->client->request('GET', $path);
        $response = $this->client->getResponse();
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        unset($headers['Date']);
        ksort($headers);

        return $response->getStatusCode()."\n".json_encode($headers)."\n".json_encode($response->headers->getCookies())."\n".$response->getContent();
    }

    private function paymentStatus(Payment $payment): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]);
    }

    private function customerMessages(Order $order): int
    {
        $chatId = (string) $order->getUser()->getTelegramAccount()?->getTelegramId();
        $count = 0;
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true);
            $count += is_array($body) && $chatId === (string) ($body['chat_id'] ?? '') ? 1 : 0;
        }

        return $count;
    }

    public function testTheNeutralPageAndItsHeaders(): void
    {
        $this->client->request('GET', self::notifyPath(1, 'wrong'));
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(TetraminatorNotifyController::PAGE, $response->getContent());
        self::assertStringContainsString('Received. You can go back to the bot.', TetraminatorNotifyController::PAGE);
        self::assertStringContainsString('دریافت شد', TetraminatorNotifyController::PAGE);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame([], $response->headers->getCookies(), 'no session, no cookie');
    }

    public function testAValidTokenAsksOnceAndSettles(): void
    {
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([self::tetraPaid()]);

        $answer = $this->notify(self::notifyPath((int) $payment->getId()));

        self::assertSame($this->notify(self::notifyPath(999999999, 'wrong')), $answer, 'a settling call answers the same bytes as a rejected one');
        $calls = $this->tetraminatorCalls();
        self::assertCount(1, $calls);
        self::assertSame('GET', $calls[0]['method']);
        self::assertStringEndsWith('/payment/inquiry/'.self::TETRA_PAY_ID, $calls[0]['url'], 'the stored pay_id, never anything from the request');
        self::assertSame(PaymentStatus::CONFIRMED, $this->paymentStatus($payment));
        self::assertSame(TetraminatorReconciler::SOURCE_WEBHOOK, $this->em->getConnection()->fetchOne('SELECT confirmation_source FROM payment WHERE id = ?', [$payment->getId()]));
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedRequests(): iterable
    {
        yield 'wrong token' => ['wrong_token'];
        yield 'token of 42 characters' => ['short_token'];
        yield 'token of 44 characters' => ['long_token'];
        yield 'token with a character outside base64url' => ['bad_char_token'];
        yield 'token with an extra path segment' => ['extra_segment'];
        yield 'id not digits' => ['letters_id'];
        yield 'id of 19 digits' => ['long_id'];
        yield 'negative id' => ['negative_id'];
        yield 'unknown id' => ['unknown_id'];
        yield 'another gateway\'s payment with the same token hash' => ['other_gateway'];
        yield 'over the per-IP limit' => ['ip_limit'];
        yield 'over the per-payment limit' => ['payment_limit'];
    }

    #[DataProvider('rejectedRequests')]
    public function testEveryRejectedRequestAnswersTheSameBytesAndAsksNothing(string $case): void
    {
        $payment = $this->tetraminatorPayment();
        $id = (int) $payment->getId();
        $this->tetraminatorAnswers([self::tetraPaid()]);
        $reference = $this->notify(self::notifyPath(999999999, 'wrong'));
        $token = self::TETRA_TOKEN;

        $path = match ($case) {
            'wrong_token' => self::notifyPath($id, 'Zb3dEf6hIj9lMn0pQr1tUv2xYz4_-5bC7dE8fG9hI0j'),
            'short_token' => self::notifyPath($id, substr($token, 0, 42)),
            'long_token' => self::notifyPath($id, $token.'A'),
            'bad_char_token' => self::notifyPath($id, substr($token, 0, 42).'.'),
            'extra_segment' => self::notifyPath($id, $token.'/x'),
            'letters_id' => self::notifyPath('12a', $token),
            'long_id' => self::notifyPath('1234567890123456789', $token),
            'negative_id' => self::notifyPath('-'.$id, $token),
            'unknown_id' => self::notifyPath($id + 1000, $token),
            'other_gateway' => self::notifyPath($this->otherGatewayPayment(), $token),
            // The reference request above took one of this address's 60.
            'ip_limit' => $this->exhaust('limiter.payment_notify_ip', $this->clientIp, 59, self::notifyPath($id, $token)),
            'payment_limit' => $this->exhaust('limiter.payment_notify_payment', 'payment_'.$id, 6, self::notifyPath($id, $token)),
            default => throw new \LogicException($case),
        };

        self::assertSame($reference, $this->notify($path));
        self::assertSame([], $this->tetraminatorCalls(), 'no inquiry');
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
    }

    /** A Zibal payment whose authority happens to equal the token's hash: the gateway check alone keeps it out. */
    private function otherGatewayPayment(): int
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(self::TETRA_AMOUNT, $this->createTestInbound()));
        $payment = $this->createPayment($order, $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm']), PaymentStatus::PENDING)
            ->setAuthority(hash('sha256', self::TETRA_TOKEN))
            ->setGatewayTransactionId(self::TETRA_PAY_ID);
        $this->em->flush();

        return (int) $payment->getId();
    }

    private function exhaust(string $limiter, string $key, int $tokens, string $path): string
    {
        /** @var RateLimiterFactory $factory */
        $factory = self::getContainer()->get($limiter);
        self::assertTrue($factory->create($key)->consume($tokens)->isAccepted());

        return $path;
    }

    public function testThePerPaymentLimitStopsTheSeventhInquiryInTenMinutes(): void
    {
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers(array_fill(0, 7, self::tetraUnpaid()));

        $answers = [];
        for ($i = 0; $i < 8; ++$i) {
            $answers[] = $this->notify(self::notifyPath((int) $payment->getId()));
        }

        self::assertCount(1, array_unique($answers));
        self::assertCount(6, $this->tetraminatorCalls());
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testRepeatedValidCallsOnAConfirmedPaymentAskNothingAndProvisionOnce(): void
    {
        $payment = $this->tetraminatorPayment();
        $order = $payment->getOrder();
        $this->tetraminatorAnswers([self::tetraPaid(), self::tetraPaid(), self::tetraPaid()]);

        $first = $this->notify(self::notifyPath((int) $payment->getId()));
        $messages = $this->customerMessages($order);
        $this->notify(self::notifyPath((int) $payment->getId()));
        $last = $this->notify(self::notifyPath((int) $payment->getId()));

        self::assertSame($first, $last);
        self::assertCount(1, $this->tetraminatorCalls(), 'a confirmed payment is skipped before the inquiry');
        self::assertSame(PaymentStatus::CONFIRMED, $this->paymentStatus($payment));
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(1, $this->driver()->createCalls);
        self::assertGreaterThan(0, $messages, 'the delivery message went out');
        self::assertSame($messages, $this->customerMessages($order), 'and was not sent again');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rejectedUnpaid(): iterable
    {
        yield 'invoice expired' => [Payment::SOURCE_GATEWAY_INVOICE_EXPIRY, false];
        yield 'invoice superseded by a gateway switch' => [Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED, false];
        yield 'cancelled by the customer' => [Payment::SOURCE_CUSTOMER_CANCEL, true];
        yield 'order expired' => [Payment::SOURCE_ORDER_EXPIRY, true];
    }

    #[DataProvider('rejectedUnpaid')]
    public function testLateMoneyOnARejectedUnpaidPaymentGoesToReview(string $source, bool $orderClosed): void
    {
        $payment = $this->tetraminatorPayment();
        $payment->setStatus(PaymentStatus::REJECTED)->setConfirmationSource($source);
        if ($orderClosed) {
            $payment->getOrder()->setStatus(Payment::SOURCE_CUSTOMER_CANCEL === $source ? OrderStatus::CANCELLED : OrderStatus::EXPIRED);
        }
        $this->em->flush();
        $this->tetraminatorAnswers([self::tetraPaid()]);

        $this->notify(self::notifyPath((int) $payment->getId()));

        self::assertCount(1, $this->tetraminatorCalls(), 'a rejected-unpaid payment is still asked about');
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->paymentStatus($payment), 'never ignored');
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
    }

    public function testAnAdminRejectionIsNotAskedAbout(): void
    {
        $payment = $this->tetraminatorPayment();
        $payment->setStatus(PaymentStatus::REJECTED)->setConfirmationSource('admin_panel');
        $this->em->flush();
        $this->tetraminatorAnswers([self::tetraPaid()]);

        $this->notify(self::notifyPath((int) $payment->getId()));

        self::assertSame([], $this->tetraminatorCalls());
        self::assertSame(PaymentStatus::REJECTED, $this->paymentStatus($payment));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function inquiryAnswers(): iterable
    {
        yield 'not paid yet' => [['status' => 200, 'body' => ['status' => true, 'payment_status' => 'pending', 'pay_id' => self::TETRA_PAY_ID]], PaymentStatus::PENDING];
        yield 'status false' => [['status' => 200, 'body' => ['status' => false]], PaymentStatus::PENDING];
        yield 'HTTP 500' => [['status' => 500, 'body' => ['message' => 'boom']], PaymentStatus::PENDING];
        yield '401' => [['status' => 401, 'body' => []], PaymentStatus::PENDING];
        yield 'network error' => [['transport' => 'Connection reset'], PaymentStatus::PENDING];
        yield 'paid, another amount' => [self::tetraPaid(160000), PaymentStatus::NEEDS_REVIEW];
        yield 'paid, another pay_id' => [self::tetraPaid(self::TETRA_AMOUNT, 'TPAY-OTHER'), PaymentStatus::NEEDS_REVIEW];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('inquiryAnswers')]
    public function testOnlyAStrictPaidAnswerConfirmsAndNothingExpires(array $answer, string $expected): void
    {
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([$answer]);

        $page = $this->notify(self::notifyPath((int) $payment->getId()));

        self::assertSame($this->notify(self::notifyPath(999999999, 'wrong')), $page);
        self::assertCount(1, $this->tetraminatorCalls());
        self::assertSame($expected, $this->paymentStatus($payment));
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
    }

    public function testAWalletTopUpIsCreditedOnceUnderRepeatedNotifies(): void
    {
        $this->em->persist((new Setting())->setKeyName(WalletSettings::ENABLED)->setValue('true')->setType('boolean'));
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount(self::TETRA_AMOUNT)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();
        $payment = $this->tetraminatorPayment($order);
        $this->tetraminatorAnswers([self::tetraPaid(), self::tetraPaid()]);

        $this->notify(self::notifyPath((int) $payment->getId()));
        $this->notify(self::notifyPath((int) $payment->getId()));

        self::assertSame(PaymentStatus::CONFIRMED, $this->paymentStatus($payment));
        self::assertCount(1, $this->tetraminatorCalls());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
        self::assertSame(self::TETRA_AMOUNT, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$order->getUser()->getId()]));
    }

    public function testTheReconcilerSkipsWhatIsNotATetraminatorPaymentBeforeAnyInquiry(): void
    {
        $reconciler = self::getContainer()->get(TetraminatorReconciler::class);
        $withoutPayId = $this->tetraminatorPayment()->setGatewayTransactionId(null);
        $this->em->flush();

        self::assertSame(TetraminatorReconcileOutcome::SKIPPED, $reconciler->reconcile($this->otherGatewayPayment(), TetraminatorReconciler::SOURCE_WEBHOOK));
        self::assertSame(TetraminatorReconcileOutcome::SKIPPED, $reconciler->reconcile((int) $withoutPayId->getId(), TetraminatorReconciler::SOURCE_WEBHOOK));
        self::assertSame(TetraminatorReconcileOutcome::SKIPPED, $reconciler->reconcile(987654321, TetraminatorReconciler::SOURCE_WEBHOOK));
        self::assertSame([], $this->tetraminatorCalls());
    }

    public function testTheReconcilerReportsAnAccountRefusalSoABatchCanStop(): void
    {
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([['status' => 403, 'body' => []]]);

        $outcome = self::getContainer()->get(TetraminatorReconciler::class)->reconcile((int) $payment->getId(), 'test');

        self::assertSame(TetraminatorReconcileOutcome::ACCOUNT_REFUSED, $outcome);
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testOnlyGetIsRouted(): void
    {
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([self::tetraPaid()]);

        $this->client->request('POST', self::notifyPath((int) $payment->getId()));

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->tetraminatorCalls());
    }
}
