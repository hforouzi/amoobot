<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\User;
use App\MiniApp\Checkout\PaymentTelegramLink;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\TetraminatorNotifyFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A Tetraminator payment in the Mini App opens the t.me link the plugin stored (mini_app_link) with openTelegramLink
 * (tetraminator-gateway design, decision 6), checked again on the server; anything else falls back to the payment URL
 * and openLink as for every gateway. The bot keeps the payment URL. Other gateways' pages are unchanged.
 * The plugin stores mini_app_link only when the admin turns use_mini_app_link on, so this gateway has it on; with the
 * defaults the page opens the provider's website page (testWithTheDefaultsThePageOpensTheWebsiteLink).
 */
final class MiniAppTelegramLinkTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;
    use TetraminatorNotifyFixtures;

    private const LINK = 'https://t.me/TetraminatorBot/pay?startapp=TPAY0000000000000001';
    private const PAY_URL = 'https://pay.tetraminator.test/invoice/TPAY0000000000000001';
    private const WEBSITE_URL = 'https://gw.tetraminator.test/pay/TPAY0000000000000001';

    private StorePaymentMethod $tetraMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->installTetraminator();
        $this->tetraMethod = (new StorePaymentMethod())->setGateway($this->tetraminatorGateway(['use_mini_app_link' => true]))->setTitle('پرداخت آنلاین')->setIsActive(true)->setSortOrder(50);
        $this->em->persist($this->tetraMethod);
        $this->em->flush();
        $this->startMiniAppClient();
    }

    /** @return array<string, mixed> Tetraminator's create answer */
    private static function created(mixed $miniAppLink = self::LINK): array
    {
        return ['status' => 201, 'body' => ['status' => true, 'message' => 'ok', 'pay_id' => self::TETRA_PAY_ID, 'url' => self::PAY_URL, 'mini_app_link' => $miniAppLink]];
    }

    public function testAPendingPaymentOpensItsStoredTelegramLinkWithTheGenericText(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->tetraminatorAnswers([self::created()]);
        $payment = $this->payTetraminator($session, $this->checkout($session));

        self::assertSame(self::LINK, $payment->getRequestPayload()['mini_app_link'] ?? null, 'stored by the plugin');
        self::assertSame(self::PAY_URL, $payment->getPaymentUrl());

        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);
        $page = new Crawler($html);
        $root = $page->filter('.view-root');
        self::assertSame(self::LINK, $root->attr('data-main-button-href'));
        self::assertNotNull($root->attr('data-main-button-telegram'), 'opened with openTelegramLink');
        self::assertNull($root->attr('data-main-button-external'));
        self::assertSame($this->openText(), $root->attr('data-main-button-text'), 'the generic "open payment" text');
        self::assertSame(self::LINK, $page->filter('a[data-telegram-link]')->attr('href'));
        self::assertSame($this->openText(), trim($page->filter('a[data-telegram-link]')->text()));
        self::assertCount(0, $page->filter('a[data-open-link]'));
        self::assertStringNotContainsString(self::PAY_URL, $html, 'the bot\'s link is not handed out in the Mini App');
        self::assertStringNotContainsStringIgnoringCase('tetraminator', $page->filter('.view-root')->text(), 'no provider name in the text');
    }

    public function testWithTheDefaultsThePageOpensTheWebsiteLink(): void
    {
        // A config saved before the link settings existed: prefer_website_link on, use_mini_app_link off.
        $gateway = $this->tetraMethod->getGateway();
        $config = $gateway->getConfig() ?? [];
        unset($config['use_mini_app_link'], $config['prefer_website_link']);
        $gateway->setConfig($config);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);
        $this->tetraminatorAnswers([['status' => 201, 'body' => self::created()['body'] + ['gateway_link' => self::WEBSITE_URL]]]);

        $payment = $this->payTetraminator($session, $this->checkout($session));

        self::assertSame(self::WEBSITE_URL, $payment->getPaymentUrl(), 'the website page');
        self::assertArrayNotHasKey('mini_app_link', $payment->getRequestPayload() ?? [], 'not stored');
        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);
        self::assertSame($this->onlineMarkup($payment, self::WEBSITE_URL), $this->markup($html), 'openLink, as for every other gateway');
        $root = (new Crawler($html))->filter('.view-root');
        self::assertNotNull($root->attr('data-main-button-external'));
        self::assertNull($root->attr('data-main-button-telegram'));
        self::assertStringNotContainsString('t.me/TetraminatorBot', $html);
    }

    public function testASecondTapOnTheLiveInvoiceGetsTheSameLinkAndNoNewInvoice(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $order = $this->checkout($session);
        $this->tetraminatorAnswers([self::created(), self::created('https://t.me/TetraminatorBot/pay?startapp=SECOND')]);
        $first = $this->payTetraminator($session, $order);

        $second = $this->payTetraminator($session, $order);

        self::assertSame($first->getId(), $second->getId(), 'the same row');
        self::assertCount(1, $this->tetraminatorCalls(), 'no second invoice');
        self::assertSame(self::LINK, $second->getRequestPayload()['mini_app_link'] ?? null);
        $page = new Crawler($this->appGet('/app/view/payments/'.$second->getId(), $session));
        self::assertSame(self::LINK, $page->filter('.view-root')->attr('data-main-button-href'));
        self::assertSame(self::LINK, $page->filter('a[data-telegram-link]')->attr('href'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLinks(): iterable
    {
        yield 'missing' => [null];
        yield 'http' => ['http://t.me/TetraminatorBot/pay'];
        yield 'another host' => ['https://pay.tetraminator.test/miniapp'];
        yield 'a host ending in t.me' => ['https://evil-t.me/TetraminatorBot'];
        yield 'a subdomain of t.me' => ['https://x.t.me/TetraminatorBot'];
        yield 't.me as a subdomain' => ['https://t.me.evil.test/TetraminatorBot'];
        yield 'upper case host' => ['https://T.ME/TetraminatorBot'];
        yield 'user info' => ['https://user@t.me/TetraminatorBot'];
        yield 'user info hiding a host' => ['https://t.me@evil.test/TetraminatorBot'];
        yield 'port' => ['https://t.me:443/TetraminatorBot'];
        yield 'fragment' => ['https://t.me/TetraminatorBot#x'];
        yield 'no path' => ['https://t.me/'];
        yield 'double slash' => ['https://t.me//evil.test/x'];
        yield 'space' => ['https://t.me/Tetraminator Bot'];
        yield 'quote' => ['https://t.me/x"onclick="alert(1)'];
        yield 'new line' => ["https://t.me/x\n"];
        yield 'javascript' => ['javascript:alert(1)//https://t.me/x'];
        yield 'overlong' => ['https://t.me/TetraminatorBot/pay?startapp='.str_repeat('a', PaymentTelegramLink::MAX_LENGTH)];
        yield 'an array' => [[self::LINK]];
        yield 'a number' => [42];
        yield 'true' => [true];
    }

    #[DataProvider('invalidLinks')]
    public function testAMissingOrInvalidStoredLinkFallsBackToThePaymentUrl(mixed $link): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->tetraminatorAnswers([self::created()]);
        $payment = $this->payTetraminator($session, $this->checkout($session));
        // Whatever the plugin let through (it checks less), or a payload from before: the page checks it again.
        $payload = $payment->getRequestPayload() ?? [];
        unset($payload['mini_app_link']);
        if (null !== $link) {
            $payload['mini_app_link'] = $link;
        }
        $payment->setRequestPayload($payload);
        $this->em->flush();

        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);

        self::assertSame($this->onlineMarkup($payment, self::PAY_URL), $this->markup($html), 'today\'s openLink behaviour');
        self::assertStringNotContainsString('data-telegram-link', $html);
        self::assertStringNotContainsString('data-main-button-telegram', $html);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function finalStates(): iterable
    {
        yield 'confirmed' => [PaymentStatus::CONFIRMED, null];
        yield 'invoice expired' => [PaymentStatus::REJECTED, Payment::SOURCE_GATEWAY_INVOICE_EXPIRY];
        yield 'order expired' => [PaymentStatus::REJECTED, Payment::SOURCE_ORDER_EXPIRY];
        yield 'cancelled' => [PaymentStatus::REJECTED, Payment::SOURCE_CUSTOMER_CANCEL];
        yield 'needs review' => [PaymentStatus::NEEDS_REVIEW, null];
    }

    #[DataProvider('finalStates')]
    public function testAPaymentThatIsNoLongerPendingShowsNoPayButton(string $status, ?string $source): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->tetraminatorAnswers([self::created()]);
        $payment = $this->payTetraminator($session, $this->checkout($session));
        $this->em->getConnection()->executeStatement('UPDATE payment SET status = ?, confirmation_source = ? WHERE id = ?', [$status, $source, $payment->getId()]);

        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);
        $page = new Crawler($html);

        self::assertNull($page->filter('.view-root')->attr('data-main-button-href'));
        self::assertNull($page->filter('.view-root')->attr('data-main-button-text'));
        self::assertCount(0, $page->filter('a[data-telegram-link], a[data-open-link]'));
        self::assertStringNotContainsString('t.me/TetraminatorBot', $html);
        self::assertStringNotContainsString(self::PAY_URL, $html);
    }

    public function testTheBotStillSendsThePaymentUrl(): void
    {
        $this->tetraminatorAnswers([self::created()]);
        $order = $this->freshOrder();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->tetraMethod->getId());

        $this->em->clear();
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['order' => $order->getId()]) ?? throw new \LogicException('no payment');
        self::assertSame(self::LINK, $payment->getRequestPayload()['mini_app_link'] ?? null);
        $sent = (string) json_encode(array_map(fn (array $request): array => $this->requestBody($request), $this->http->requests('sendMessage')), JSON_UNESCAPED_SLASHES);
        $urls = [];
        $markups = $this->replyMarkups();
        array_walk_recursive($markups, static function (mixed $value, int|string $key) use (&$urls): void {
            if ('url' === $key) {
                $urls[] = $value;
            }
        });
        self::assertContains(self::PAY_URL, $urls, 'the pay button opens url');
        self::assertStringNotContainsString('t.me/TetraminatorBot', $sent, 'never the Mini App link');
    }

    /**
     * Today's attributes and link, byte for byte, for every other gateway: even a stored "mini_app_link" is ignored by a
     * built-in gateway (its payload is its own request, not a provider's answer).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function otherOnlineGateways(): iterable
    {
        yield 'Zibal' => ['zibal', 'https://gateway.zibal.ir/start/ZTRACK7'];
        yield 'custom API' => ['custom_api', 'https://psp.test/pay/CX1'];
        yield 'NOWPayments' => ['nowpayments', 'https://nowpayments.test/inv-77'];
    }

    #[DataProvider('otherOnlineGateways')]
    public function testOtherGatewaysAreUnchanged(string $type, string $gatewayUrl): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $payment = $this->payWith($session, $this->checkout($session), $this->methods[$type]);
        $before = $this->markup($this->appGet('/app/view/payments/'.$payment->getId(), $session));
        $payment->setRequestPayload(['mini_app_link' => self::LINK] + ($payment->getRequestPayload() ?? []));
        $this->em->flush();

        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);

        self::assertSame($this->onlineMarkup($payment, $gatewayUrl, 'crypto' === $this->kind($type) ? 'crypto' : 'online'), $this->markup($html));
        self::assertSame($before, $this->markup($html));
        self::assertStringNotContainsString('t.me/TetraminatorBot', $html);
    }

    public function testWinapayIsUnchanged(): void
    {
        $gateway = $this->createGateway('plugin', ['enabled' => true], 'IRT')->setPluginCode('winapay');
        $payment = $this->createPayment($this->freshOrder(), $gateway, PaymentStatus::PENDING)
            ->setPaymentUrl('https://winapay.test/pay/W1')
            ->setRequestPayload(['status' => 'ok', 'token' => 'W1']);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);

        $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);

        self::assertSame($this->onlineMarkup($payment, 'https://winapay.test/pay/W1'), $this->markup($html));
    }

    public function testCardToCardAndWalletPagesHaveNoPayLink(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $card = $this->payWith($session, $this->checkout($session), $this->methods['manual_card']);
        $wallet = (new Payment())->setOrder($this->freshOrder())->setMethod(Payment::METHOD_WALLET)
            ->setAmount(150000)->setPayableAmount(150000)->setStatus(PaymentStatus::PENDING)->setRequestPayload(['mini_app_link' => self::LINK]);
        $this->em->persist($wallet);
        $this->em->flush();

        foreach ([$card, $wallet] as $payment) {
            $html = $this->appGet('/app/view/payments/'.$payment->getId(), $session);
            self::assertSame(200, $this->httpStatus());
            self::assertSame(['root' => sprintf('<div class="view-root" data-view="services" data-title="%s" data-back data-poll-url="/app/api/payments/%d/status" data-poll-state="pending">', $this->trans('payment.title'), $payment->getId()), 'link' => null], $this->markup($html));
            self::assertStringNotContainsString('data-telegram-link', $html);
        }
    }

    /** An order waiting for payment, with the fixtures' customer and plan as they are now (after a clear). */
    private function freshOrder(): Order
    {
        $user = $this->em->find(User::class, $this->user->getId()) ?? throw new \LogicException();
        $plan = $this->em->find(Plan::class, $this->plan->getId()) ?? throw new \LogicException();
        $order = $this->createOrder($user, $plan)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->flush();

        return $order;
    }

    /**
     * The view root's opening tag and the open-payment link, as rendered.
     *
     * @return array{root: ?string, link: ?string}
     */
    private function markup(string $html): array
    {
        return [
            'root' => 1 === preg_match('~<div class="view-root"[^>]*>~', $html, $m) ? $m[0] : null,
            'link' => 1 === preg_match('~<a class="btn" href="[^"]*" data-(?:open|telegram)-link>[^<]*</a>~', $html, $l) ? $l[0] : null,
        ];
    }

    /**
     * What the page rendered before this change for a pending online payment.
     *
     * @return array{root: string, link: string}
     */
    private function onlineMarkup(Payment $payment, string $url, string $kind = 'online'): array
    {
        $text = $this->trans('payment.open_'.$kind);

        return [
            'root' => sprintf('<div class="view-root" data-view="services" data-title="%s" data-back data-poll-url="/app/api/payments/%d/status" data-poll-state="pending" data-main-button-text="%s" data-main-button-href="%s" data-main-button-external>', $this->trans('payment.title'), $payment->getId(), $text, $url),
            'link' => sprintf('<a class="btn" href="%s" data-open-link>%s</a>', $url, $text),
        ];
    }

    private function openText(): string
    {
        return $this->trans('payment.open_online');
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, [], 'miniapp', 'fa');
    }

    private function kind(string $type): string
    {
        return 'nowpayments' === $type ? 'crypto' : 'online';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function replyMarkups(): array
    {
        $markups = [];
        foreach ($this->http->requests('sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            if (is_array($markup)) {
                $markups[] = $markup;
            }
        }

        return $markups;
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
    private function payTetraminator(array $session, Order $order): Payment
    {
        return $this->payWith($session, $order, $this->tetraMethod);
    }

    /**
     * @param array{token: string, csrf: string} $session
     */
    private function payWith(array $session, Order $order, StorePaymentMethod $method): Payment
    {
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $method->getId()], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $this->em->clear();
        $payment = $this->em->getRepository(Payment::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException();
        self::assertSame('/app/view/payments/'.$payment->getId(), $answer['redirect'], 'the answer only names the payment page');
        self::assertSame(['redirect', 'kind'], array_keys($answer), 'no link the client would open');
        $this->http->reset();
        $this->answerGateways();

        return $payment;
    }
}
