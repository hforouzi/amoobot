<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\Order;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ResellerStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;

/**
 * The reseller menu in the bot (S1): the main-menu button only for an active reseller while resellers are on; buying a
 * new service at wholesale (confirm, then paid from the wallet); renew / extra traffic of a sold service; anybody else,
 * a disabled reseller or the switch off gets "not available" and nothing is written.
 */
final class ResellerBotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ResellerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->fund(500000);
        $this->http->reset();
        $this->answerGateways();
    }

    public function testTheMenuButtonShowsOnlyForAnActiveResellerWhileResellersAreOn(): void
    {
        $button = $this->botButton('button.main.reseller');

        $this->sendText(self::CUSTOMER, '/start');
        self::assertContains($button, $this->replyButtons(), 'active reseller');

        $this->http->reset();
        $this->setting(ResellerSettings::ENABLED, 'false');
        $this->sendText(self::CUSTOMER, '/start');
        self::assertNotContains($button, $this->replyButtons(), 'resellers switched off');
        $this->setting(ResellerSettings::ENABLED, 'true');

        $this->http->reset();
        $this->reseller->setStatus(ResellerStatus::DISABLED);
        $this->em->flush();
        $this->sendText(self::CUSTOMER, '/start');
        self::assertNotContains($button, $this->replyButtons(), 'disabled reseller');

        $this->http->reset();
        $this->createUser('8602');
        $this->sendText('8602', '/start');
        self::assertNotContains($button, $this->replyButtons(), 'a plain customer');
    }

    public function testBuyingANewServiceAtWholesale(): void
    {
        $this->sendText(self::CUSTOMER, $this->botButton('button.main.reseller'));
        self::assertTrue($this->sentTextContains('500,000'), 'the balance');
        self::assertContains('rs_new', $this->sentCallbacks());

        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'rs_new');
        self::assertContains('rs_plan:'.$this->plan->getId(), $this->sentCallbacks());
        self::assertTrue($this->sentTextContains('120,000') || $this->buttonTextContains('120,000'), 'the wholesale price on the plan button');

        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());
        self::assertTrue($this->sentTextContains('120,000'));
        self::assertTrue($this->sentTextContains('380,000'), 'balance after');
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'the confirmation writes nothing');

        $pay = $this->sentCallback('rs_buy:'.$this->plan->getId().':120000:');
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, $pay);
        $order = $this->em->getRepository(Order::class)->findOneBy(['reseller' => $this->reseller]) ?? self::fail('not bought');
        self::assertSame(120000, $order->getAmount());
        self::assertSame(380000, $this->balance());
        self::assertTrue($this->sentTextContains($this->botText('reseller.paid', ['reseller' => ['amount' => '120,000', 'balance' => '380,000']])));
    }

    public function testRenewAndExtraTrafficOfASoldService(): void
    {
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
        $this->buy();
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE sold_by_reseller_id = ?', [$this->reseller->getId()]);

        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'rs_sold:1');
        self::assertContains('rs_svc:'.$serviceId, $this->sentCallbacks());
        $this->pressButton(self::CUSTOMER, 'rs_svc:'.$serviceId);
        self::assertContains('rs_renew:'.$serviceId, $this->sentCallbacks());
        $this->pressButton(self::CUSTOMER, 'rs_renew:'.$serviceId);
        $this->pressButton(self::CUSTOMER, $this->sentCallback('rs_renew_ok:'.$serviceId.':120000:'));
        $this->pressButton(self::CUSTOMER, 'rs_tr:'.$serviceId);
        self::assertContains(sprintf('rs_tr_gb:%d:10', $serviceId), $this->sentCallbacks());
        $this->pressButton(self::CUSTOMER, sprintf('rs_tr_gb:%d:10', $serviceId));
        $this->pressButton(self::CUSTOMER, $this->sentCallback(sprintf('rs_tr_ok:%d:10:24000:', $serviceId)));

        self::assertSame(['new_service', 'renewal', 'add_traffic'], array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT type FROM `order` ORDER BY id')));
        self::assertSame(500000 - 120000 - 120000 - 24000, $this->balance());
    }

    public function testNobodyElseCanUseTheResellerCallbacks(): void
    {
        $this->createUser('8603');
        foreach (['reseller', 'rs_new', 'rs_buy:'.$this->plan->getId(), 'rs_sold:1'] as $data) {
            $this->pressButton('8603', $data);
        }
        $this->reseller->setStatus(ResellerStatus::DISABLED);
        $this->em->flush();
        $this->pressButton(self::CUSTOMER, 'rs_buy:'.$this->plan->getId());

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertTrue($this->sentTextContains($this->botText('reseller.unavailable')));
        self::assertSame(500000, $this->balance());
    }

    /** The last inline button sent whose callback data starts with this (the pay button carries amount and nonce). */
    private function sentCallback(string $prefix): string
    {
        $matching = array_values(array_filter($this->sentCallbacks(), static fn (string $data): bool => str_starts_with($data, $prefix)));
        self::assertNotSame([], $matching, 'no button '.$prefix.'…');

        return $matching[count($matching) - 1];
    }

    /**
     * The reply (main menu) keyboard's button texts sent since the last reset.
     *
     * @return list<string>
     */
    private function replyButtons(): array
    {
        $texts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((is_array($markup) ? $markup['keyboard'] ?? [] : []) as $row) {
                foreach ($row as $button) {
                    $texts[] = (string) ($button['text'] ?? '');
                }
            }
        }

        return $texts;
    }

    private function buttonTextContains(string $needle): bool
    {
        foreach ($this->sentKeyboards() as $keyboard) {
            foreach ($keyboard as $row) {
                foreach ($row as $button) {
                    if (str_contains((string) ($button['text'] ?? ''), $needle)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
