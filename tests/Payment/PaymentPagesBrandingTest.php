<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Branding\BrandingSettings;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * The payment return and cancel pages on the shared customer layout (templates/public/layout.html.twig): every state
 * with and without a brand, escaping, what each state kept from before (redirect, auto refresh, withheld access,
 * owned-elsewhere privacy, the config <pre>), and that the pages stay script-free and self-contained.
 */
final class PaymentPagesBrandingTest extends IntegrationTestCase
{
    private const LOGO = '0123456789abcdef0123456789abcdef.png';
    private const HOSTILE = '"><script>alert(1)</script><img src=x onerror=alert(2)>&amp;';

    /** state => the heading the page shows (unchanged from before the layout). */
    private const STATES = [
        'paid' => 'پرداخت با موفقیت تایید شد',
        'pending' => 'در انتظار تایید پرداخت',
        'failed' => 'پرداخت ناموفق یا منقضی شد',
        'provision_failed' => 'پرداخت تایید شد',
        'review' => 'پرداخت در حال بررسی توسط پشتیبانی است',
        'cancelled' => 'این سفارش لغو شده یا مهلت آن تمام شده است',
        'not_found' => 'لینک پرداخت معتبر نیست',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statesAndBrands(): iterable
    {
        foreach (array_keys(self::STATES) as $state) {
            foreach (['none', 'name', 'logo'] as $brand) {
                yield $state.', brand: '.$brand => [$state, $brand];
            }
        }
    }

    #[DataProvider('statesAndBrands')]
    public function testEveryStateRendersOnTheLayout(string $state, string $brand): void
    {
        $this->brand($brand);
        $html = $this->renderReturn($state);

        self::assertStringContainsString('<h1>'.self::STATES[$state].'</h1>', $html);
        self::assertStringContainsString('<html lang="fa" dir="rtl">', $html);
        self::assertStringContainsString('<title>نتیجه پرداخت'.('none' === $brand ? '' : ' · Amoo VPN').'</title>', $html);
        self::assertSame(1, substr_count($html, '<svg class="status-icon"'), 'one status icon');
        $this->assertBrandHeader($html, $brand);
        $this->assertSelfContained($html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brands(): iterable
    {
        yield 'no brand' => ['none'];
        yield 'brand name' => ['name'];
        yield 'logo' => ['logo'];
    }

    #[DataProvider('brands')]
    public function testTheCancelPageRendersOnTheLayout(string $brand): void
    {
        $this->brand($brand);

        $withOrder = $this->render('payment/cancel.html.twig', ['payment' => null, 'order' => ['trackingCode' => 'AB12CD', 'id' => 7]]);
        self::assertStringContainsString('<h1>پرداخت لغو شد یا کامل نشد</h1>', $withOrder);
        self::assertStringContainsString('AB12CD', $withOrder);
        self::assertStringContainsString('<title>پرداخت لغو شد'.('none' === $brand ? '' : ' · Amoo VPN').'</title>', $withOrder);
        $this->assertBrandHeader($withOrder, $brand);
        $this->assertSelfContained($withOrder);

        $withoutOrder = $this->render('payment/cancel.html.twig', ['payment' => null, 'order' => null]);
        self::assertStringContainsString('از همانجا پرداخت را دوباره بررسی یا تکرار کنید', $withoutOrder);
        self::assertStringNotContainsString('class="summary"', $withoutOrder);
    }

    public function testAHostileBrandNameIsEscapedEverywhere(): void
    {
        $this->setting(BrandingSettings::NAME, self::HOSTILE);
        foreach ([$this->renderReturn('paid'), $this->render('payment/cancel.html.twig', ['payment' => null, 'order' => null])] as $html) {
            self::assertStringNotContainsString('<script>alert(1)', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;&amp;amp;', $html);
            $this->assertSelfContained($html);
        }

        // With a logo, the name is its alt text: escaped in the attribute.
        $this->setting(BrandingSettings::LOGO, self::LOGO);
        $html = $this->renderReturn('paid');
        self::assertStringContainsString('alt="&quot;&gt;&lt;script&gt;', $html);
        self::assertStringNotContainsString('alt=""><script>', $html);
    }

    public function testTheAccentColourAndItsReadableTextReachThePage(): void
    {
        self::assertStringContainsString('--accent: #166534;', $this->renderReturn('paid'), 'today\'s green without branding');
        self::assertStringContainsString('--on-accent: #ffffff;', $this->renderReturn('paid'));

        $this->setting(BrandingSettings::ACCENT, '#FACC15');
        $html = $this->renderReturn('paid');
        self::assertStringContainsString('--accent: #facc15;', $html);
        self::assertStringContainsString('--on-accent: #111827;', $html, 'dark text on a light accent');

        $this->setting(BrandingSettings::ACCENT, '#000;}</style><script>alert(1)</script>');
        $html = $this->renderReturn('paid');
        self::assertStringContainsString('--accent: #166534;', $html, 'an invalid row is ignored');
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function testPaidStateKeepsTheRedirectTheLinkAndTheSummary(): void
    {
        $html = $this->renderReturn('paid', [
            'subscriptionUrl' => 'https://sub.example.test/s/abc?x=1&y=2',
            'redirectEnabled' => true,
            'service' => ['clientEmail' => 'tg_42_1', 'username' => null],
        ]);

        self::assertStringContainsString('<meta http-equiv="refresh" content="5;url=https://sub.example.test/s/abc?x=1&amp;y=2">', $html);
        self::assertStringContainsString('<a class="button" href="https://sub.example.test/s/abc?x=1&amp;y=2">مشاهده لینک اشتراک</a>', $html);
        self::assertStringContainsString('تا چند ثانیه دیگر به لینک اشتراک منتقل می‌شوید.', $html);
        foreach (['کد پیگیری سفارش', 'AB12CD', 'وضعیت سفارش', 'paid', 'نام کاربری سرویس', 'tg_42_1'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertSame(1, substr_count($html, 'class="button"'), 'one primary button');
        self::assertStringNotContainsString('PAYMENT-ID-99', $html, 'the payment id is never shown');
    }

    public function testPaidStateKeepsWithheldAccessConfigTextAndOwnedElsewhereRules(): void
    {
        $withheld = $this->renderReturn('paid', ['accessWithheld' => true, 'service' => ['clientEmail' => 'tg_1', 'username' => null]]);
        self::assertStringContainsString('لینک اشتراک این سرویس فعلاً قابل استفاده نیست. لطفاً با پشتیبانی تماس بگیرید.', $withheld);
        self::assertStringNotContainsString('در حال آماده‌سازی است', $withheld);
        self::assertStringNotContainsString('http-equiv="refresh"', $withheld);

        $config = $this->renderReturn('paid', ['configText' => "vless://id@host:443?security=reality#<b>me</b>\ntrojan://p@h:443"]);
        self::assertStringContainsString("<pre tabindex=\"0\">vless://id@host:443?security=reality#&lt;b&gt;me&lt;/b&gt;\ntrojan://p@h:443</pre>", $config);

        $elsewhere = $this->renderReturn('paid', ['ownedElsewhere' => true, 'service' => null, 'subscriptionUrl' => null, 'configText' => null]);
        self::assertStringContainsString('پرداخت انجام شد و سفارش تکمیل شده است.', $elsewhere);
        self::assertStringNotContainsString('نام کاربری سرویس', $elsewhere);
        self::assertStringNotContainsString('class="button"', $elsewhere);
        self::assertStringNotContainsString('<pre', $elsewhere);

        $preparing = $this->renderReturn('paid');
        self::assertStringContainsString('سرویس در حال آماده‌سازی است', $preparing);
    }

    public function testPendingStateRefreshesShowsProgressAndChecksAgainWithAPlainLink(): void
    {
        $html = $this->renderReturn('pending', ['refreshSeconds' => 10, 'statusText' => 'waiting'], '/payment/return/'.str_repeat('A', 43).'?NP_id=55&x="y"');

        self::assertStringContainsString('<meta http-equiv="refresh" content="10">', $html);
        self::assertStringContainsString('role="status" aria-live="polite"', $html);
        self::assertStringContainsString('<div class="progress" aria-hidden="true"><span></span></div>', $html);
        self::assertStringContainsString('هر 10 ثانیه خودکار به‌روزرسانی می‌شود', $html);
        self::assertStringContainsString('وضعیت فعلی: <span dir="auto">waiting</span>', $html);
        // A GET of the same address, query included (the gateway's hint stays); nothing is posted.
        self::assertMatchesRegularExpression('#<a class="button" href="/payment/return/A{43}\?NP_id=55&amp;x=&quot;y&quot;">همین حالا دوباره بررسی کن</a>#', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
    }

    public function testReviewStateKeepsTheLatePaymentWording(): void
    {
        self::assertStringContainsString('پس از لغو یا پایان مهلت سفارش دریافت شد', $this->renderReturn('review', ['payment' => ['latePaymentInReview' => true]]));
        self::assertStringContainsString('مبلغ یا ارز دریافت‌شده با سفارش مطابقت نداشت', $this->renderReturn('review', ['payment' => ['latePaymentInReview' => false]]));
    }

    public function testThePagesCostOneQueryForTheBrand(): void
    {
        $this->brand('logo');
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $counter = self::getContainer()->get(QueryCounter::class);

        foreach (['/payment/return/'.str_repeat('Z', 43), '/payment/return/'.str_repeat('Z', 43).'/cancel'] as $url) {
            $counter->start();
            $client->request('GET', $url);
            $queries = $counter->stop();
            self::assertSame(2, $queries, $url.': the payment lookup and the brand settings');
            self::assertStringContainsString('/brand/logo/'.self::LOGO, (string) $client->getResponse()->getContent());
            self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        }
        $counter->start();
        $client->request('GET', '/payment/cancel');
        self::assertSame(1, $counter->stop(), 'the legacy cancel page: the brand settings only');
    }

    public function testWithoutBrandingThePageHasNoHeaderAndTheDefaultLook(): void
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->request('GET', '/payment/return/'.str_repeat('Z', 43));

        self::assertSame(404, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<h1>لینک پرداخت معتبر نیست</h1>', $html);
        self::assertStringNotContainsString('class="brand"', $html);
        self::assertStringContainsString('<title>نتیجه پرداخت</title>', $html);
        self::assertStringContainsString('--accent: #166534;', $html);
        $this->assertSelfContained($html);
    }

    private function assertBrandHeader(string $html, string $brand): void
    {
        match ($brand) {
            'none' => self::assertStringNotContainsString('<header class="brand">', $html),
            'name' => self::assertStringContainsString('<span class="brand-name">Amoo VPN</span>', $html),
            default => self::assertStringContainsString('<img src="/brand/logo/'.self::LOGO.'" alt="Amoo VPN" height="56">', $html),
        };
        if ('logo' === $brand) {
            self::assertStringNotContainsString('brand-name', substr($html, (int) strpos($html, '<body>')));
        }
    }

    /** No script, no inline handler, no style attribute, nothing loaded from another site (a strict CSP holds). */
    private function assertSelfContained(string $html): void
    {
        self::assertStringNotContainsString('<script', $html);
        self::assertDoesNotMatchRegularExpression('/<[^>]+\son[a-z]+\s*=/i', $html);
        self::assertDoesNotMatchRegularExpression('/<[^>]+\sstyle\s*=/i', $html);
        self::assertDoesNotMatchRegularExpression('/\b(?:src|href)="(?!\/|https:\/\/sub\.example\.test)/', $html);
        self::assertDoesNotMatchRegularExpression('/url\((?!["\']?\/)/', $html);
        self::assertStringContainsString('url("/miniapp/fonts/Vazirmatn-wght.woff2?v=33.003")', $html, 'the font the Mini App ships');
    }

    private function brand(string $brand): void
    {
        if ('none' === $brand) {
            return;
        }
        $this->setting(BrandingSettings::NAME, 'Amoo VPN');
        if ('logo' === $brand) {
            $this->setting(BrandingSettings::LOGO, self::LOGO);
        }
    }

    private function setting(string $key, string $value): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $connection->executeStatement('INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, ?, NOW())', [$key, $value, 'text']);
        self::getContainer()->get(BrandingSettings::class)->reset();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function renderReturn(string $state, array $parameters = [], string $uri = '/payment/return/token'): string
    {
        return $this->render('payment/success.html.twig', $parameters + [
            'state' => $state,
            'payment' => ['id' => 'PAYMENT-ID-99', 'latePaymentInReview' => false],
            'order' => ['trackingCode' => 'AB12CD', 'id' => 7, 'status' => 'paid'],
            'service' => null,
            'subscriptionUrl' => null,
            'configText' => null,
            'accessWithheld' => false,
            'ownedElsewhere' => false,
            'redirectEnabled' => false,
            'refreshSeconds' => 'pending' === $state ? 10 : null,
            'statusText' => null,
        ], $uri);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $template, array $parameters, string $uri = '/payment/return/token'): string
    {
        $requests = self::getContainer()->get(RequestStack::class);
        $requests->push(Request::create($uri));
        try {
            return self::getContainer()->get(Environment::class)->render($template, $parameters);
        } finally {
            $requests->pop();
        }
    }
}
