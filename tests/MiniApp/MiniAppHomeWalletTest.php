<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\QueryCounter;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The wallet card on the customer's home: the balance (read only, a missing wallet is 0 and is not created) and the
 * way into "My wallet", only while the wallet is switched on, for one extra query at most.
 */
final class MiniAppHomeWalletTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->startMiniAppClient();
    }

    public function testTheHomeShowsTheBalanceInTomanWithALinkToTheWallet(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, 1250000, WalletTransactionType::TOPUP, 'home-'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $session = $this->authenticate(self::CUSTOMER);

        $fa = new Crawler($this->appGet('/app/view/home', $session));
        self::assertSame(200, $this->httpStatus());
        $card = $fa->filter('[data-home-wallet]');
        self::assertCount(1, $card);
        self::assertSame('1250000', $card->filter('[data-wallet-balance]')->attr('data-wallet-balance'));
        self::assertSame('1,250,000 تومان', trim($card->filter('[data-wallet-balance]')->text()), 'formatted like the other amounts');
        self::assertSame('موجودی کیف پول', trim(str_replace('👛', '', $card->filter('.muted')->first()->text())));
        $link = $card->filter('a[data-home-wallet-link]');
        self::assertSame('/app/view/wallet', $link->attr('href'));
        self::assertNotNull($link->attr('data-nav'), 'opens inside the app');
        self::assertSame('شارژ و تراکنش‌ها', trim($link->text()));
        self::assertCount(0, $card->filter('[data-wallet-frozen]'));

        $en = new Crawler($this->appGet('/app/view/home?lang=en', $session));
        self::assertSame('1,250,000 Toman', trim($en->filter('[data-home-wallet] [data-wallet-balance]')->text()));
        self::assertSame('Top up and transactions', trim($en->filter('a[data-home-wallet-link]')->text()));
        self::assertStringNotContainsString('home.wallet', $en->html().$fa->html(), 'no raw translation key');
    }

    public function testAMissingWalletShowsZeroAndIsNotCreated(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');

        $page = new Crawler($this->appGet('/app/view/home', $this->authenticate(self::CUSTOMER)));

        self::assertSame(200, $this->httpStatus());
        self::assertSame('0', $page->filter('[data-home-wallet] [data-wallet-balance]')->attr('data-wallet-balance'));
        self::assertSame('0 تومان', trim($page->filter('[data-home-wallet] [data-wallet-balance]')->text()));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet WHERE user_id = ?', [$this->user->getId()]), 'a GET never creates a wallet');
    }

    public function testAFrozenWalletSaysSoAndLinksToTheTransactionsOnly(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');
        $wallets = self::getContainer()->get(WalletService::class);
        $wallets->credit($this->user, 5000, WalletTransactionType::TOPUP, 'home-'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $wallets->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());

        $page = new Crawler($this->appGet('/app/view/home', $this->authenticate(self::CUSTOMER)));

        self::assertSame('5,000 تومان', trim($page->filter('[data-home-wallet] [data-wallet-balance]')->text()));
        self::assertCount(1, $page->filter('[data-home-wallet] [data-wallet-frozen]'));
        self::assertSame('تراکنش‌ها', trim($page->filter('a[data-home-wallet-link]')->text()));
    }

    public function testNothingOfTheWalletShowsWhileItIsSwitchedOff(): void
    {
        $this->setting(WalletSettings::ENABLED, 'false');
        self::getContainer()->get(WalletService::class)->credit($this->user, 7000, WalletTransactionType::TOPUP, 'home-'.random_int(1, PHP_INT_MAX), WalletActor::system());

        $html = $this->appGet('/app/view/home', $this->authenticate(self::CUSTOMER));

        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, (new Crawler($html))->filter('[data-home-wallet]'));
        self::assertStringNotContainsString('/app/view/wallet', $html);
        self::assertStringNotContainsString('7,000', $html);
    }

    public function testTheCardCostsAtMostOneQuery(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        self::getContainer()->get(WalletService::class)->credit($this->user, 1000, WalletTransactionType::TOPUP, 'home-'.random_int(1, PHP_INT_MAX), WalletActor::system());

        $this->setting(WalletSettings::ENABLED, 'false');
        $off = $this->homeQueries($session);
        $this->setting(WalletSettings::ENABLED, 'true');
        $on = $this->homeQueries($session);

        self::assertLessThanOrEqual($off + 1, $on, sprintf('home queries: %d with the wallet off, %d with it on', $off, $on));
    }

    /**
     * @param array{token: string, csrf: string} $session
     */
    private function homeQueries(array $session): int
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $this->em->clear();
        $counter->start();
        $this->appGet('/app/view/home', $session);
        $queries = $counter->stop();
        self::assertSame(200, $this->httpStatus());

        return $queries;
    }
}
