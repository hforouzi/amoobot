<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Paying in the Mini App with each gateway type, through the same PaymentStarter the bot uses: card to card (card
 * details, exact amount, receipt photo validated and sent to Telegram, admins alerted as in the bot), online gateways
 * (opened with openLink, status polled), crypto. The app only ever reads a payment's status; settlement is untouched.
 */
final class MiniAppPaymentTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testCardToCardShowsTheCardDetailsAndTheExactAmount(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $payment = $this->pay($session, 'manual_card');

        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);
        $page = new Crawler($html);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('pending', $page->filter('[data-payment-state]')->attr('data-payment-state'));
        self::assertStringContainsString(self::CARD_NUMBER, $html);
        self::assertSame(self::CARD_NUMBER, $page->filter('button[data-copy]')->first()->attr('data-copy'));
        self::assertStringContainsString('Shop Owner', $html);
        self::assertStringContainsString('150,000', $html, 'the exact amount to transfer');
        self::assertSame('/app/api/payments/'.$payment->getId().'/receipt', $page->filter('form[data-upload]')->attr('data-upload'));
        self::assertSame('image/jpeg,image/png,image/webp', $page->filter('form[data-upload] input[type="file"]')->attr('accept'));
        self::assertSame('/app/api/payments/'.$payment->getId().'/status', $page->filter('.view-root')->attr('data-poll-url'));
    }

    public function testAReceiptPhotoIsCheckedAndReachesTheAdminsAsInTheBot(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $payment = $this->pay($session, 'manual_card');
        $url = '/app/api/payments/'.$payment->getId().'/receipt';

        $answer = $this->upload($url, $session, $this->file('receipt.txt', 'not an image at all'), 'text/plain');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('receipt_invalid', $answer['error']);

        $answer = $this->upload($url, $session, $this->file('fake.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)), 'image/png');
        self::assertSame(422, $this->httpStatus(), 'a PNG signature alone is not a picture');
        self::assertSame('receipt_invalid', $answer['error']);

        $answer = $this->upload($url, $session, $this->file('huge.png', str_repeat('a', 5 * 1024 * 1024 + 1)), 'image/png');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('receipt_too_large', $answer['error']);
        self::assertSame([], $this->http->requests('/sendPhoto'), 'nothing reached Telegram');

        $answer = $this->upload($url, $session, $this->png(), 'image/png');
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');

        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();
        self::assertSame(PaymentStatus::SUBMITTED, $payment->getStatus());
        self::assertSame('AgAD-receipt', $payment->getReceiptFileId(), 'stored as a Telegram file id, like a receipt sent to the bot');
        $photos = $this->http->requests('/sendPhoto');
        self::assertCount(2, $photos, 'uploaded into the customer\'s chat, then forwarded to the admin');
        $adminAlert = $this->requestBody($photos[1]);
        self::assertSame(self::ENV_ADMIN, (string) $adminAlert['chat_id']);
        self::assertSame('AgAD-receipt', $adminAlert['photo']);
        self::assertStringContainsString('Payment ID: '.$payment->getId(), (string) $adminAlert['caption']);

        $this->appGet('/app/api/payments/'.$payment->getId().'/status', $session);
        self::assertSame(['state' => 'review', 'final' => false, 'serviceId' => null], $this->json());

        $answer = $this->upload($url, $session, $this->png(), 'image/png');
        self::assertSame(409, $this->httpStatus());
        self::assertSame('receipt_already_submitted', $answer['error']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function onlineGateways(): iterable
    {
        yield 'Zibal' => ['zibal', 'https://gateway.zibal.ir/start/ZTRACK7', 'gateway.zibal.ir/v1/verify'];
        yield 'custom API' => ['custom_api', 'https://psp.test/pay/CX1', 'psp.test/verify'];
        yield 'NOWPayments (crypto)' => ['nowpayments', 'https://nowpayments.test/inv-77', 'api.nowpayments.io'];
    }

    #[DataProvider('onlineGateways')]
    public function testAnOnlinePaymentOpensTheGatewayAndOnlyReadsTheStatus(string $type, string $gatewayUrl, string $verifyNeedle): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $payment = $this->pay($session, $type);

        $page = new Crawler($this->appGet('/app/view/payments/'.$payment->getId(), $session));
        self::assertSame($gatewayUrl, $page->filter('a[data-open-link]')->attr('href'), 'opened with Telegram.WebApp.openLink');
        self::assertSame('/app/api/payments/'.$payment->getId().'/status', $page->filter('.view-root')->attr('data-poll-url'));

        $before = count($this->http->requests());
        for ($i = 0; $i < 3; ++$i) {
            $this->appGet('/app/api/payments/'.$payment->getId().'/status', $session);
            self::assertSame(['state' => 'pending', 'final' => false, 'serviceId' => null], $this->json());
        }
        self::assertCount($before, $this->http->requests(), 'polling never asks the gateway');
        self::assertSame([], $this->http->requests($verifyNeedle));

        // The gateway's webhook / callback / return page settles it (here: the settlement service, as they do).
        $this->settle($payment);

        $this->appGet('/app/api/payments/'.$payment->getId().'/status', $session);
        $status = $this->json();
        self::assertSame('paid', $status['state']);
        self::assertTrue($status['final']);
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $payment->getOrder()->getId()]);
        self::assertInstanceOf(VpnService::class, $service);
        self::assertSame($service->getId(), $status['serviceId']);

        $page = new Crawler($this->appGet('/app/view/payments/'.$payment->getId(), $session));
        self::assertSame('paid', $page->filter('[data-payment-state]')->attr('data-payment-state'));
        self::assertSame('/app/view/services/'.$service->getId(), $page->filter('a[data-nav][data-go-service]')->attr('href'));
        self::assertNull($page->filter('.view-root')->attr('data-poll-url'), 'no polling on a final state');
    }

    public function testAGatewayThatRefusesThePaymentIsReported(): void
    {
        $this->http->reset();
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 102, 'message' => 'merchant not found']);
        $session = $this->authenticate(self::CUSTOMER);
        $order = $this->checkout($session);

        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods['zibal']->getId()], self::newKey());

        self::assertSame(502, $this->httpStatus());
        self::assertSame('payment_start_failed', $answer['error']);
        self::assertNotSame('', (string) $answer['message']);
    }

    /**
     * @return iterable<string, array{string, ?string, string, bool}>
     */
    public static function finalStates(): iterable
    {
        yield 'needs review' => [PaymentStatus::NEEDS_REVIEW, null, 'review', false];
        yield 'provisioning failed' => [PaymentStatus::PROVISIONING_FAILED, null, 'provision_failed', true];
        yield 'rejected by an admin' => [PaymentStatus::REJECTED, null, 'failed', true];
        yield 'cancelled by the customer' => [PaymentStatus::REJECTED, Payment::SOURCE_CUSTOMER_CANCEL, 'cancelled', true];
        yield 'order expired' => [PaymentStatus::REJECTED, Payment::SOURCE_ORDER_EXPIRY, 'expired', true];
    }

    #[DataProvider('finalStates')]
    public function testEveryOutcomeIsShownClearly(string $status, ?string $source, string $state, bool $final): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $payment = $this->pay($session, 'zibal');
        $this->em->getConnection()->executeStatement('UPDATE payment SET status = ?, confirmation_source = ? WHERE id = ?', [$status, $source, $payment->getId()]);

        $this->appGet('/app/api/payments/'.$payment->getId().'/status', $session);
        self::assertSame(['state' => $state, 'final' => $final, 'serviceId' => null], $this->json());

        $page = new Crawler($this->appGet('/app/view/payments/'.$payment->getId(), $session));
        self::assertSame($state, $page->filter('[data-payment-state]')->attr('data-payment-state'));
        self::assertGreaterThan(0, $page->filter('[data-state-text]')->count());
    }

    public function testComingBackThroughTheGatewayReturnPageIsSeenByTheApp(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $payment = $this->pay($session, 'zibal');
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'REF9']);

        // The external browser the gateway sent back (no Mini App session), the existing return page.
        $browser = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(self::$kernel);
        $browser->request('GET', '/payment/return/'.$payment->getReturnToken());
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        $this->appGet('/app/api/payments/'.$payment->getId().'/status', $session);
        self::assertSame('paid', $this->json()['state']);
    }

    public function testSomeoneElsesPaymentIsInvisible(): void
    {
        $theirs = $this->createPayment($this->createOrder($this->createUser('8603'), $this->plan), $this->methods['manual_card']->getGateway(), PaymentStatus::PENDING);
        $session = $this->authenticate(self::CUSTOMER);

        $this->appGet('/app/view/payments/'.$theirs->getId(), $session);
        self::assertSame(404, $this->httpStatus());
        $this->appGet('/app/api/payments/'.$theirs->getId().'/status', $session);
        self::assertSame(404, $this->httpStatus());
        $this->upload('/app/api/payments/'.$theirs->getId().'/receipt', $session, $this->png(), 'image/png');
        self::assertSame(404, $this->httpStatus());
        self::assertSame([], $this->http->requests('/sendPhoto'));
    }

    public function testTheOrderPageListsTheMethodsTheOrderCanUse(): void
    {
        $this->methods['custom_api']->setIsActive(false);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);
        $order = $this->checkout($session);

        $page = new Crawler($this->appGet('/app/view/orders/'.$order->getId().'/pay', $session));

        $ids = $page->filter('[data-method-id]')->each(static fn (Crawler $c): int => (int) $c->attr('data-method-id'));
        self::assertSame([(int) $this->methods['manual_card']->getId(), (int) $this->methods['zibal']->getId(), (int) $this->methods['nowpayments']->getId()], $ids);
        self::assertStringContainsString('150,000', $page->html());
        self::assertSame((string) $order->getTrackingCode(), $page->filter('[data-tracking-code]')->text());
    }

    /**
     * @param array{token: string, csrf: string} $session
     */
    private function checkout(array $session): Order
    {
        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $this->em->clear();

        return $this->em->getRepository(Order::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException();
    }

    /**
     * @param array{token: string, csrf: string} $session
     */
    private function pay(array $session, string $type): Payment
    {
        $order = $this->checkout($session);
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods[$type]->getId()], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $this->em->clear();
        $payment = $this->em->getRepository(Payment::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException();
        self::assertSame('/app/view/payments/'.$payment->getId(), $answer['redirect']);
        $this->http->reset();
        $this->answerGateways();

        return $payment;
    }

    private function settle(Payment $payment): void
    {
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();
        // What the gateway reports: the expected amount it was asked for (USD for NOWPayments), else Rial.
        [$amount, $currency] = null !== $payment->getExpectedAmount() ? [$payment->getExpectedAmount(), (string) $payment->getExpectedCurrency()] : ['1500000', 'IRR'];
        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-ok', amount: $amount, currency: $currency)), 'test_webhook');
        self::assertTrue($result->isConfirmed(), $result->message);
        $this->em->clear();
    }

    /**
     * @param array{token: string, csrf: string} $session
     *
     * @return array<string, mixed>
     */
    private function upload(string $url, array $session, string $path, string $mime): array
    {
        $this->client->request('POST', $url, [], ['receipt' => new UploadedFile($path, basename($path), $mime, null, true)], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$session['token'], 'HTTP_X_CSRF_TOKEN' => $session['csrf'],
        ]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function file(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'-'.$name;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30) ?: throw new \LogicException('GD is needed');
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);

        return $this->file('receipt.png', (string) ob_get_clean());
    }
}
