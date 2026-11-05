<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\Payment;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Subscription\Application\LinksUpdatingNotice;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * PR P5, T8 of docs/design/plan-inbound-change.md 8.2 (display guard 5.5): a service moved by the P4 runner whose link
 * refresh failed still stores the old inbound's single configs. While inbound_changed_at is newer than
 * last_access_info_synced_at, no surface shows or sends them: the bot (service detail, subscription, configs, QR, admin
 * detail, renewal confirmation), /s/{token}, the Mini App subscription view and the payment return page show the
 * subscription URL with the shared "being updated" text (or the text alone), without a panel request. The next
 * successful refresh brings the links back. A service without inbound_changed_at, on a legacy panel or another panel
 * type, or refreshed after the change, is never affected.
 */
final class InboundMoveDisplayGuardTest extends IntegrationTestCase
{
    use BotAdminTestTrait, InboundMoveFixtures {
        BotAdminTestTrait::requestBody insteadof InboundMoveFixtures;
    }
    use MiniAppTestTrait;

    private const CUSTOMER = '8940001';
    private const STALE = '@origin.example.com:443?type=tcp#old';
    private const SEPARATOR = "\n---\n";
    /** Marks a bot message sent without parse_mode (plain text: Telegram shows it as written, nothing to escape). */
    private const PLAIN = '[plain] ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->setUpMove();
        $this->startMiniAppClient();
    }

    public function testT8WhileTheLinksArePendingEverySurfaceShowsTheSubscriptionUrlAndTheTextButNoStoredConfig(): void
    {
        $service = $this->movedWithoutRefresh(withSubscriptionUrl: true);
        $url = (string) $service->getSubscriptionUrl();
        self::assertTrue(self::getContainer()->get(FinalConfigLinkProvider::class)->linksPending($this->fresh($service)));

        $panelRequests = count($this->http->requests('panel.example.test'));

        $surfaces = $this->everySurface($service);

        foreach (['page fa', 'page en', 'mini app fa', 'mini app en', 'payment return'] as $where) {
            self::assertStringContainsString($url, $surfaces[$where], $where.': the subscription URL (live, served by the panel) stays');
            self::assertSame(1, substr_count($surfaces[$where], 'data-links-updating'), $where.': the text, once');
            self::assertStringNotContainsString('<details', $surfaces[$where], $where.': no "individual configs" section');
        }
        self::assertStringContainsString($this->text('fa'), $surfaces['page fa']);
        self::assertStringContainsString($this->text('en'), $surfaces['page en']);
        self::assertStringContainsString($this->text('fa'), $surfaces['mini app fa']);
        self::assertStringContainsString($this->text('en'), $surfaces['mini app en']);
        self::assertStringContainsString($this->text('fa'), $surfaces['payment return'], 'the Persian return page, Persian text');
        foreach (['bot detail', 'bot subscription', 'bot configs', 'renewal confirmation', 'admin detail'] as $where) {
            self::assertStringContainsString($this->text('fa'), $surfaces[$where], $where);
        }
        self::assertStringContainsString($url, $surfaces['bot detail']);
        self::assertStringContainsString($url, $surfaces['bot configs'], 'resend config: the subscription URL with the text');
        foreach ($surfaces as $where => $content) {
            self::assertStringNotContainsString(self::STALE, $content, $where.': a stored config of the inbound the service left');
            self::assertStringNotContainsString('link.updating', $content, $where.': translated');
            self::assertStringNotContainsString('لینک اشتراک این سرویس فعلاً قابل استفاده نیست', $content, $where.': not "contact support"');
            self::assertStringNotContainsString('This service\'s subscription link cannot be used', $content, $where);
        }
        self::assertSame([], $this->fake->requests, 'rule 9.1: no panel call on view');
        self::assertCount($panelRequests, $this->http->requests('panel.example.test'), 'no request to the panel host at all');

        // The next successful refresh (here the runner's own pass) clears it: the panel's links are shown again.
        $this->fake->clearScripts();
        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);
        self::assertSame(0, $code, $output);
        $service = $this->fresh($service);
        self::assertGreaterThanOrEqual($service->getInboundChangedAt()?->getTimestamp(), $service->getLastAccessInfoSyncedAt()?->getTimestamp());
        self::assertFalse(self::getContainer()->get(FinalConfigLinkProvider::class)->linksPending($service));
        $fresh = (array) $service->getConfigLinks();
        self::assertNotSame([], $fresh);
        $this->fake->requests = [];

        $surfaces = $this->everySurface($service);

        foreach (['page fa', 'page en', 'mini app fa', 'mini app en'] as $where) {
            self::assertStringNotContainsString('data-links-updating', $surfaces[$where], $where);
            self::assertStringContainsString(htmlspecialchars($fresh[0], ENT_QUOTES), $surfaces[$where], $where.': the refreshed configs are back');
        }
        foreach (['bot detail', 'bot configs', 'renewal confirmation'] as $where) {
            self::assertStringNotContainsString($this->text('fa'), $surfaces[$where], $where);
            self::assertStringContainsString(htmlspecialchars($fresh[0], ENT_QUOTES | ENT_SUBSTITUTE), $surfaces[$where], $where.': the refreshed configs are back');
        }
        self::assertStringNotContainsString($this->text('fa'), $surfaces['admin detail']);
        self::assertSame([], $this->fake->requests, 'still no panel call on view');
    }

    public function testWithoutASubscriptionUrlOnlyTheTextIsShownNeverAnEmptyPageOrContactSupport(): void
    {
        $service = $this->movedWithoutRefresh(withSubscriptionUrl: false);

        $surfaces = $this->everySurface($service);

        foreach (['page fa', 'page en', 'mini app fa', 'mini app en', 'payment return'] as $where) {
            self::assertSame(1, substr_count($surfaces[$where], 'data-links-updating'), $where);
            self::assertStringNotContainsString('<details', $surfaces[$where], $where);
        }
        self::assertStringNotContainsString('This service has no subscription link.', $surfaces['page en'], 'not "missing" either: the text instead');
        self::assertStringNotContainsString('لینک اشتراک برای این سرویس ثبت نشده است', $surfaces['payment return'], 'not the config fallback');
        self::assertStringNotContainsString('سرویس در حال آماده‌سازی است', $surfaces['payment return']);
        foreach (['bot detail', 'bot subscription', 'bot configs', 'bot qr', 'renewal confirmation', 'admin detail'] as $where) {
            self::assertStringContainsString($this->text('fa'), $surfaces[$where], $where);
        }
        self::assertStringNotContainsString('اطلاعات اتصال کامل نیست', $surfaces['bot configs'], 'resend config: not "contact support"');
        self::assertStringNotContainsString('QR کانفیگ', $surfaces['bot qr'], 'no QR of a stored config');
        self::assertSame([], $this->http->requests('/sendPhoto'), 'no QR image at all');
        foreach ($surfaces as $where => $content) {
            self::assertStringNotContainsString(self::STALE, $content, $where);
        }
        self::assertSame([], $this->fake->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function notPending(): iterable
    {
        yield 'inbound_changed_at NULL (every service today)' => ['null'];
        yield 'refreshed after the change' => ['refreshed'];
        yield 'refreshed in the same second as the change' => ['same second'];
        yield 'legacy 3x-ui panel' => ['legacy'];
        yield 'another panel type' => ['other type'];
    }

    /**
     * A service the guard must leave alone shows exactly what it shows without inbound_changed_at: every surface is
     * byte-identical to the same service with the column NULL, and its stored configs are shown.
     */
    #[DataProvider('notPending')]
    public function testNeverWithheldWhenTheDataIsFine(string $case): void
    {
        $service = $this->planService($this->gold, $this->de, n: 1);
        // Expiry half a day away from a day boundary: "days left" cannot change between the two renders.
        $this->em->getConnection()->update('vpn_service', ['subscription_url' => 'https://sub.example-shop.com:2096/sub/'.$service->getSubId(), 'last_access_info_synced_at' => '2026-03-01 10:00:00', 'expires_at' => (new \DateTimeImmutable('+40 days 12 hours'))->format('Y-m-d H:i:s')], ['id' => $service->getId()]);
        if ('legacy' === $case || 'other type' === $case) {
            $panel = $this->managed($this->modernPanel);
            'legacy' === $case ? $panel->recordProbe(SanaeiIntegrationMode::LEGACY, 'ok', new \DateTimeImmutable()) : $panel->setType('dummy');
            $this->em->flush();
        }
        $baseline = $this->everySurface($service);

        $changedAt = match ($case) {
            'null' => null,
            'refreshed' => '2026-03-01 09:59:59',
            'same second' => '2026-03-01 10:00:00',
            default => '2026-03-01 11:00:00',
        };
        $this->em->getConnection()->update('vpn_service', ['inbound_changed_at' => $changedAt], ['id' => $service->getId()]);
        self::assertFalse(self::getContainer()->get(FinalConfigLinkProvider::class)->linksPending($this->fresh($service)));
        $this->fake->requests = [];

        $surfaces = $this->everySurface($service);

        self::assertSame(array_keys($baseline), array_keys($surfaces));
        foreach ($surfaces as $where => $content) {
            self::assertSame(self::normalized($baseline[$where]), self::normalized($content), $where.': identical to the service without inbound_changed_at');
            self::assertStringNotContainsString('data-links-updating', $content, $where);
            self::assertStringNotContainsString($this->text('fa'), $content, $where);
        }
        if ('other type' !== $case) {
            foreach (['page fa', 'mini app en', 'bot configs'] as $where) {
                self::assertStringContainsString(self::STALE, $surfaces[$where], $where.': the stored configs are shown, as today');
            }
        }
        self::assertSame([], $this->fake->requests, 'no panel call on view');
    }

    public function testTheTextIsEscapedEverywhere(): void
    {
        $service = $this->movedWithoutRefresh(withSubscriptionUrl: true);
        $translator = self::getContainer()->get('translator');
        foreach (['fa', 'en'] as $locale) {
            $translator->getCatalogue($locale)->set(LinksUpdatingNotice::KEY, '<b id="x">being & updated</b>', LinksUpdatingNotice::DOMAIN);
        }

        $surfaces = $this->everySurface($service);

        foreach ($surfaces as $where => $content) {
            foreach (explode(self::SEPARATOR, $content) as $message) {
                if (!str_starts_with($message, self::PLAIN)) {
                    self::assertStringNotContainsString('<b id="x">', $message, $where.': HTML is escaped');
                }
            }
        }
        self::assertStringContainsString('⏳ <b id="x">being & updated</b>', $surfaces['admin detail'], 'the admin detail is plain text: shown as written, not as markup');        foreach (['page fa', 'page en', 'mini app fa', 'payment return', 'bot detail', 'bot configs', 'renewal confirmation'] as $where) {
            self::assertStringContainsString('&lt;b id=&quot;x&quot;&gt;being &amp; updated&lt;/b&gt;', $content = $surfaces[$where], $where.': escaped once');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    /**
     * What is logged while the guard withholds the links (FinalConfigLinkProvider's links_pending line through
     * SafeErrorLog, and every record of the application logger) names no link, token, UUID, subId, email or owner.
     */
    #[DataProvider('ignoreArgs')]
    public function testNothingSecretIsLoggedWhileTheLinksAreWithheld(string $ignoreArgs): void
    {
        $service = $this->movedWithoutRefresh(withSubscriptionUrl: true);
        $before = ['args' => ini_get('zend.exception_ignore_args'), 'log' => ini_get('error_log')];
        $file = sys_get_temp_dir().'/amoobot-p5-log-'.bin2hex(random_bytes(4)).'.log';
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        ini_set('error_log', $file);
        $logger = self::getContainer()->get(RecordingLogger::class);
        $records = count($logger->records);
        try {
            $this->everySurface($service);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before['args']);
            ini_set('error_log', (string) $before['log']);
        }
        $errorLog = is_file($file) ? (string) file_get_contents($file) : '';
        @unlink($file);
        // Symfony's own info-level "Matched route" record carries the request URI, so /s/{token} names the page token. It is
        // the framework's, not P5's, and the default logger writes only warnings and above in production: left out here.
        $records = array_filter(array_slice($logger->records, $records), static fn (array $r): bool => 'Matched route "{route}".' !== $r['message']);
        $logged = $errorLog."\n".implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $records));

        self::assertMatchesRegularExpression('/\[FinalConfigLinkProvider\] links_pending service_id='.$service->getId().' source_flow="subscription_page" final_count=0/', $errorLog, 'the guard says so in the log, with ids only');
        foreach ([...$this->secretsOf($service), (string) $service->getSubscriptionUrl(), 'Owner Kaveh', '/secret/'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }

    public function testTheTextExistsInFaAndEnWithIdenticalKeySetsAndNamesNothingSecret(): void
    {
        $dir = dirname(__DIR__, 3).'/translations/';
        $fa = Yaml::parseFile($dir.'subscription.fa.yaml');
        $en = Yaml::parseFile($dir.'subscription.en.yaml');

        self::assertSame(array_keys($en), array_keys($fa), 'subscription.*.yaml: the same keys in the same order');
        self::assertSame('Your links are being updated; use the subscription link or try again in a few minutes.', $en[LinksUpdatingNotice::KEY]);
        self::assertNotSame('', trim((string) $fa[LinksUpdatingNotice::KEY]));
        foreach ([$fa[LinksUpdatingNotice::KEY], $en[LinksUpdatingNotice::KEY]] as $text) {
            self::assertDoesNotMatchRegularExpression('#https?://|vless://|%|\{#', (string) $text, 'no link and no placeholder for one');
        }
    }

    /** A service of Gold moved to NL-2 by the runner while the panel's link read failed: its links are pending. */
    private function movedWithoutRefresh(bool $withSubscriptionUrl): VpnService
    {
        $service = $this->movableService(1);
        $this->em->getConnection()->update('vpn_service', [
            'subscription_url' => $withSubscriptionUrl ? 'https://sub.example-shop.com:2096/sub/'.$service->getSubId() : null,
            'last_access_info_synced_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ], ['id' => $service->getId()]);
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'), times: 10);

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(0, $code, $output);
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id'], 'switched');
        self::assertNotNull($row['inbound_changed_at']);
        self::assertStringContainsString(self::STALE, (string) $row['config_text'], 'the old inbound\'s configs are still stored: the guard is what hides them');
        $this->fake->requests = [];

        return $this->fresh($service);
    }

    /**
     * Every place that shows or sends a service's links, as its owner (and the admin) sees them.
     *
     * @return array<string, string>
     */
    private function everySurface(VpnService $service): array
    {
        $service = $this->fresh($service);
        $id = (int) $service->getId();
        $token = (string) $service->getPublicToken();
        $out = [];

        $this->client->request('GET', '/s/'.$token);
        $out['page fa'] = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/s/'.$token, ['lang' => 'en']);
        $out['page en'] = (string) $this->client->getResponse()->getContent();

        $session = $this->authenticate(self::CUSTOMER);
        $out['mini app fa'] = $this->appGet('/app/view/services/'.$id.'/subscription', $session);
        self::assertSame(200, $this->httpStatus(), (string) mb_substr($out['mini app fa'], 0, 500));
        $out['mini app en'] = $this->appGet('/app/view/services/'.$id.'/subscription?lang=en', $session);

        $payment = $this->createPayment($this->fresh($service)->getOrder() ?? self::fail('no order'), null, PaymentStatus::CONFIRMED);
        $this->client->request('GET', PaymentReturnUrl::path($payment));
        $out['payment return'] = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach (['bot detail' => 'service_view:', 'bot subscription' => 'service_subscription:', 'bot configs' => 'service_resend_config:', 'bot qr' => 'service_subscription_qr:'] as $where => $callback) {
            $out[$where] = $this->botSays(fn () => $this->pressButton(self::CUSTOMER, $callback.$id));
        }
        $out['admin detail'] = $this->botSays(fn () => $this->pressButton(self::ENV_ADMIN, 'admin_service_view:'.$id));

        $notifier = self::getContainer()->get(PaymentCustomerNotifier::class);
        // The renewal confirmation's text, as the settlement builds it (private: read through reflection, nothing sent).
        $out['renewal confirmation'] = implode("\n", (new \ReflectionMethod($notifier, 'buildRenewalConfirmedMessages'))->invoke($notifier, $this->fresh($service)));
        $this->em->clear();

        return $out;
    }

    /** Everything the bot sent (texts and answered popups) while $action ran. */
    private function botSays(\Closure $action): string
    {
        $before = count($this->http->requests('api.telegram.org'));
        $action();
        $said = [];
        foreach (array_slice($this->http->requests('api.telegram.org'), $before) as $request) {
            $body = $this->requestBody($request);
            $said[] = (isset($body['parse_mode']) ? '' : self::PLAIN).(string) ($body['text'] ?? $body['caption'] ?? '');
        }

        return implode(self::SEPARATOR, $said);
    }

    private function text(string $locale): string
    {
        return htmlspecialchars(self::getContainer()->get(LinksUpdatingNotice::class)->text($locale), ENT_QUOTES);
    }

    private function fresh(VpnService $service): VpnService
    {
        $this->em->clear();

        return $this->em->find(VpnService::class, $service->getId()) ?? self::fail('service gone');
    }

    /** Without what differs between two renders of the same thing: CSRF/nonce values, payment tokens and ids. */
    private static function normalized(string $content): string
    {
        $normalized = preg_replace(
            ['~(nonce|csrf[-_a-z]*|data-session[-a-z]*)="[^"]*"~i', '~/payment/return/[A-Za-z0-9_-]+~', '~(payment|order)[ _-]?(id)?[:#\s]*\d+~i', '~"(csrfToken|token)":"[^"]*"~'],
            ['$1=""', '/payment/return/T', '$1 N', '"$1":""'],
            $content,
        );
        // Never compare two empty strings: a broken pattern must fail the test, not pass it.
        self::assertIsString($normalized, preg_last_error_msg());
        self::assertNotSame('', trim($normalized));

        return $normalized;
    }
}
