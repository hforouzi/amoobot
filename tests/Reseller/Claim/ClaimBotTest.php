<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\RequiredChannel;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ClaimToken;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;

/**
 * Claim links in the bot: the reseller makes, lists and revokes them; the claimer opens /start claim_<token>, sees a
 * preview and confirms with a button that carries a ticket, never the token. The token never reaches the bot message
 * log. Malformed payloads never claim anything; with claim links off /start behaves exactly as before. After a claim
 * (D5) the reseller still renews at wholesale but never sees the customer's links; the claimer may reset his links (D4).
 */
final class ClaimBotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;

    private const CLAIMER = '8701';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        $this->createUser(self::CLAIMER);
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testTheResellerMakesAListsAndRevokesLinksInTheBot(): void
    {
        $serviceId = (int) $this->sold->getId();
        $this->pressButton(self::CUSTOMER, 'reseller');
        self::assertContains('rs_links:1', $this->callbacks());

        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'rs_svc:'.$serviceId);
        self::assertContains('rs_link:'.$serviceId, $this->callbacks());

        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'rs_link:'.$serviceId);
        $token = $this->tokenSent();
        self::assertSame(hash('sha256', $token), $this->em->getConnection()->fetchOne('SELECT token_hash FROM service_claim'));
        self::assertNotSame([], [...$this->http->requests('/sendPhoto'), ...$this->http->requests('/sendDocument')], 'the QR of the link');

        // The token is in the message that was sent, never in the bot message log.
        $this->assertNoTokenInTheMessageLog($token);

        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'rs_links:1');
        $claimId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM service_claim');
        self::assertContains('rs_rv:'.$claimId, $this->callbacks());
        self::assertFalse($this->sentTextContains($token), 'the list never shows the token');

        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'rs_rv:'.$claimId);
        self::assertSame('revoked', $this->claimStatus($claimId));
        self::assertTrue($this->sentTextContains($this->botText('reseller.claim_revoked')));

        // Malformed reseller callbacks do nothing.
        foreach (['rs_link:'.$serviceId.':1', 'rs_rv:'.$claimId.':x', 'rs_link:', 'rs_link:abc'] as $data) {
            $this->pressButton(self::CUSTOMER, $data);
        }
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));
    }

    public function testTheClaimerOpensTheLinkSeesAPreviewAndConfirms(): void
    {
        $token = $this->makeLink()->token;
        $this->resetHttp();

        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        $confirm = $this->callbackStartingWith('cl_ok:');
        self::assertStringNotContainsString($token, $confirm, 'the button carries a ticket, not the token');
        self::assertContains('cl_no', $this->callbacks());
        self::assertTrue($this->sentTextContains('#'.$this->sold->getId()));
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold), 'opening the link changes nothing');

        $this->resetHttp();
        $this->pressButton(self::CLAIMER, $confirm);
        self::assertSame($this->claimer(self::CLAIMER)->getId(), $this->ownerOf($this->sold));
        self::assertTrue($this->sentToContains(self::CLAIMER, $this->botText('claim.done', ['service' => ['id' => (int) $this->sold->getId()]])));
        // The reseller is told, without a word about who took it.
        $resellerTexts = $this->textsTo(self::CUSTOMER);
        self::assertNotSame([], $resellerTexts);
        foreach ($resellerTexts as $text) {
            self::assertStringNotContainsString(self::CLAIMER, $text);
            self::assertStringNotContainsString('T'.self::CLAIMER, $text);
        }

        // A second delivery of the same tap: "already yours", nothing more written.
        $this->resetHttp();
        $this->pressButton(self::CLAIMER, $confirm);
        self::assertTrue($this->sentTextContains($this->botText('claim.already_yours', ['service' => ['id' => (int) $this->sold->getId()]])));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
        $this->assertNoTokenInTheMessageLog($token, false);
    }

    public function testTheWebhookLogsAnIncomingStartWithTheTokenMasked(): void
    {
        $token = $this->makeLink()->token;
        $client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(self::$kernel);
        $client->disableReboot();
        // Its own secrets (another test may have left these environment variables empty), read when the controller is built.
        $saved = [$_SERVER['TELEGRAM_WEBHOOK_SECRET'] ?? null, $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] ?? null];
        $_SERVER['TELEGRAM_WEBHOOK_SECRET'] = $_ENV['TELEGRAM_WEBHOOK_SECRET'] = $secret = 'claim-webhook-secret';
        $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $headerToken = 'claim-webhook-header';
        $update = ['update_id' => 777001, 'message' => ['message_id' => 9, 'from' => ['id' => (int) self::CLAIMER, 'is_bot' => false, 'first_name' => 'C'], 'chat' => ['id' => (int) self::CLAIMER, 'type' => 'private'], 'date' => time(), 'text' => '/start claim_'.$token]];

        $client->request('POST', '/telegram/webhook/'.$secret, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $headerToken], json_encode($update, JSON_THROW_ON_ERROR));

        foreach (['TELEGRAM_WEBHOOK_SECRET' => $saved[0], 'TELEGRAM_WEBHOOK_SECRET_TOKEN' => $saved[1]] as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertNoTokenInTheMessageLog($token);
        self::assertNotSame([], array_filter($this->callbacks(), static fn (string $d): bool => str_starts_with($d, 'cl_ok:')), 'it was handled as a claim link');
    }

    public function testTheSameStartUpdateDeliveredTwiceOnlyShowsThePreviewTwice(): void
    {
        $token = $this->makeLink()->token;
        $this->resetHttp();
        for ($i = 0; $i < 2; ++$i) {
            $this->sendText(self::CLAIMER, '/start claim_'.$token);
        }

        self::assertCount(2, array_filter($this->callbacks(), static fn (string $d): bool => str_starts_with($d, 'cl_ok:')));
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
    }

    /**
     * @return iterable<string, array{\Closure(string): string}>
     */
    public static function malformedStarts(): iterable
    {
        yield 'trailing newline' => [static fn (string $t): string => "/start claim_$t\n"];
        yield 'trailing space' => [static fn (string $t): string => "/start claim_$t "];
        yield 'two spaces' => [static fn (string $t): string => "/start  claim_$t"];
        yield 'second word' => [static fn (string $t): string => "/start claim_$t x"];
        yield 'upper-case prefix' => [static fn (string $t): string => "/start CLAIM_$t"];
        yield 'no underscore' => [static fn (string $t): string => "/start claim$t"];
        yield 'one character more' => [static fn (string $t): string => "/start claim_{$t}A"];
        yield 'truncated' => [static fn (string $t): string => '/start claim_'.substr($t, 0, 30)];
        yield 'too long for Telegram' => [static fn (string $t): string => '/start claim_'.str_repeat('A', 59)];
        yield 'not a command' => [static fn (string $t): string => "claim_$t"];
        yield 'other command' => [static fn (string $t): string => "/begin claim_$t"];
    }

    /**
     * @param \Closure(string): string $text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedStarts')]
    public function testAMalformedOrAlteredStartNeverClaimsAnything(\Closure $text): void
    {
        $token = $this->makeLink()->token;
        $this->resetHttp();

        $this->sendText(self::CLAIMER, $text($token));
        foreach (array_filter($this->callbacks(), static fn (string $d): bool => str_starts_with($d, 'cl_ok:')) as $confirm) {
            $this->pressButton(self::CLAIMER, $confirm);
        }

        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
        self::assertSame('open', $this->claimStatus((int) $this->em->getConnection()->fetchOne('SELECT id FROM service_claim')));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
    }

    public function testAlteredConfirmButtonsGetTheGenericAnswer(): void
    {
        $token = $this->makeLink()->token;
        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        $confirm = $this->callbackStartingWith('cl_ok:');
        $this->resetHttp();

        foreach ([substr($confirm, 0, -1), $confirm.'A', str_replace('cl_ok:', 'cl_ok:0', $confirm), 'cl_ok:'.PHP_INT_MAX.':x', 'cl_ok:', 'cl_rst:abc'] as $data) {
            $this->pressButton(self::CLAIMER, $data);
        }

        self::assertTrue($this->sentTextContains($this->botText('claim.unavailable')));
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
    }

    public function testEveryUnusableLinkGetsTheSameMessage(): void
    {
        $link = $this->makeLink();
        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start claim_'.ClaimToken::generate());
        $unknown = $this->http->telegramTexts();

        $this->resetHttp();
        $this->claimLinks()->revokeByReseller($this->user, (int) $link->claim->getId());
        $this->sendText(self::CLAIMER, '/start claim_'.$link->token);
        $revoked = $this->http->telegramTexts();

        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start claim_'.substr($link->token, 0, 40));
        $truncated = $this->http->telegramTexts();

        self::assertSame([$this->botText('claim.unavailable')], $unknown);
        self::assertSame($unknown, $revoked);
        self::assertSame($unknown, $truncated);
    }

    public function testTheRequiredChannelsComeFirst(): void
    {
        $token = $this->makeLink()->token;
        $this->em->persist((new RequiredChannel())->setTitle('Our channel')->setChatId('@shop_channel')->setInviteUrl('https://t.me/shop_channel')->setIsActive(true)->setRequireForPurchase(true));
        $this->em->flush();
        $this->http->onJson('/getChatMember', ['ok' => true, 'result' => ['status' => 'left']]);
        $this->resetHttp();
        $this->http->onJson('/getChatMember', ['ok' => true, 'result' => ['status' => 'left']]);

        $this->sendText(self::CLAIMER, '/start claim_'.$token);

        self::assertTrue($this->sentTextContains('@shop_channel'));
        self::assertSame([], array_filter($this->callbacks(), static fn (string $d): bool => str_starts_with($d, 'cl_ok:')), 'no preview before joining');

        $this->http->onJson('/getChatMember', ['ok' => true, 'result' => ['status' => 'member']]);
        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        $this->pressButton(self::CLAIMER, $this->callbackStartingWith('cl_ok:'));
        self::assertSame($this->claimer(self::CLAIMER)->getId(), $this->ownerOf($this->sold));
    }

    public function testWithClaimLinksOffNothingChanges(): void
    {
        $token = $this->makeLink()->token;
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');

        // A plain /start is the welcome, the same with claim links on or off.
        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start');
        $off = $this->http->telegramTexts();
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'true');
        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start');
        self::assertSame($off, $this->http->telegramTexts());
        self::assertSame([$this->botText('bot.welcome')], array_slice($off, 0, 1));
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');

        // /start claim_... is not routed: what any other unknown text gets, and the link does nothing.
        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        self::assertSame([$this->botText('bot.unknown_message')], $this->http->telegramTexts());
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));

        // No claim-link buttons for the reseller, and the claim callbacks do nothing.
        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'reseller');
        $this->pressButton(self::CUSTOMER, 'rs_svc:'.$this->sold->getId());
        self::assertNotContains('rs_links:1', $this->callbacks());
        self::assertNotContains('rs_link:'.$this->sold->getId(), $this->callbacks());
        $this->pressButton(self::CUSTOMER, 'rs_link:'.$this->sold->getId());
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));
    }

    public function testAfterAClaimTheResellerRenewsAtWholesaleButNeverSeesTheCustomersLinks(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $serviceId = (int) $this->sold->getId();
        $service = $this->row('vpn_service', $serviceId);
        $links = json_decode((string) $service['config_links'], true);
        self::assertIsArray($links);

        // His view: plan, status, expiry, traffic, "claimed on"; no new link, nothing of the customer.
        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'rs_svc:'.$serviceId);
        $claimedAt = new \DateTimeImmutable((string) $this->em->getConnection()->fetchOne('SELECT claimed_at FROM service_claim'));
        self::assertTrue($this->sentTextContains($this->botText('reseller.claimed_on', ['claim' => ['claimedAt' => $claimedAt->format('Y-m-d H:i')]])));
        self::assertNotContains('rs_link:'.$serviceId, $this->callbacks());
        self::assertContains('rs_renew:'.$serviceId, $this->callbacks());
        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsString(self::CLAIMER, $text);
        }
        $this->pressButton(self::CUSTOMER, 'rs_link:'.$serviceId);
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'open'"), 'no new link for a claimed service');

        // He renews at wholesale: he is told it is done, the customer gets the links.
        $this->resetHttp();
        $this->renewSold($serviceId);
        $service = $this->row('vpn_service', $serviceId);
        foreach ($this->textsTo(self::CUSTOMER) as $text) {
            foreach ($links as $link) {
                self::assertStringNotContainsString((string) $link, $text);
            }
            self::assertStringNotContainsString('panel.test/sub', $text);
            self::assertStringNotContainsString((string) $service['public_token'], $text);
        }
        self::assertTrue($this->sentToContains(self::CUSTOMER, '#'.$serviceId));
        $customerTexts = implode("\n", $this->textsTo(self::CLAIMER));
        self::assertStringContainsString('panel.test/sub', $customerTexts, 'the owner gets his subscription link');

        // Add traffic too.
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.price_per_gb', '3000');
        $this->resetHttp();
        $this->addTrafficSold($serviceId, 10);
        foreach ($this->textsTo(self::CUSTOMER) as $text) {
            self::assertStringNotContainsString('panel.test/sub', $text);
        }
        self::assertSame(['new_service', 'renewal', 'add_traffic'], array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT type FROM `order` ORDER BY id')));
        self::assertSame($this->claimer(self::CLAIMER)->getId(), $this->ownerOf($this->sold), 'still the customer\'s');
    }

    public function testTheClaimerCanResetHisConnectionLinks(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $serviceId = (int) $this->sold->getId();
        $before = $this->row('vpn_service', $serviceId);

        $this->resetHttp();
        $this->pressButton(self::CLAIMER, 'service_view:'.$serviceId);
        self::assertContains('cl_rst:'.$serviceId, $this->callbacks());

        $this->resetHttp();
        $this->pressButton(self::CLAIMER, 'cl_rst:'.$serviceId);
        self::assertTrue($this->sentTextContains($this->botText('claim.reset_warning', ['service' => ['id' => $serviceId]])));
        self::assertSame($before['client_uuid'], $this->row('vpn_service', $serviceId)['client_uuid'], 'the warning changes nothing');
        $confirm = $this->callbackStartingWith('cl_rst_ok:');
        self::assertMatchesRegularExpression('/^cl_rst_ok:'.$serviceId.':[0-9a-f]{12}$/D', $confirm);

        $this->resetHttp();
        $this->pressButton(self::CLAIMER, $confirm);
        $after = $this->row('vpn_service', $serviceId);
        self::assertNotSame($before['client_uuid'], $after['client_uuid']);
        self::assertNotSame($before['sub_id'], $after['sub_id']);
        self::assertNotSame($before['remote_id'], $after['remote_id']);
        self::assertNotSame($before['config_links'], $after['config_links']);
        self::assertSame(1, $this->driver()->rotations);
        self::assertTrue($this->sentTextContains($this->botText('claim.reset_done', ['service' => ['id' => $serviceId]])));

        // The same button again (a double tap, Telegram's redelivery): "already done", nothing rotated.
        $this->resetHttp();
        $this->pressButton(self::CLAIMER, $confirm);
        self::assertSame(1, $this->driver()->rotations);
        self::assertSame($after['client_uuid'], $this->row('vpn_service', $serviceId)['client_uuid']);
        self::assertTrue($this->sentTextContains($this->botText('claim.reset_already_done', ['service' => ['id' => $serviceId]])));

        // A button from before the nonce existed only shows the warning again.
        $this->resetHttp();
        $this->pressButton(self::CLAIMER, 'cl_rst_ok:'.$serviceId);
        self::assertSame(1, $this->driver()->rotations);
        self::assertTrue($this->sentTextContains($this->botText('claim.reset_warning', ['service' => ['id' => $serviceId]])));

        // Not for the reseller (not the owner), nor for an owner who did not get it by a link.
        $this->pressButton(self::CUSTOMER, $confirm);
        self::assertSame(1, $this->driver()->rotations);
        $this->buy();
        $own = (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM vpn_service');
        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'service_view:'.$own);
        self::assertNotContains('cl_rst:'.$own, $this->callbacks());
        $this->pressButton(self::CUSTOMER, 'cl_rst_ok:'.$own.':0123456789ab');
        self::assertSame(1, $this->driver()->rotations);

        // A panel failure before anything changed: nothing changed, and the customer is told so.
        $this->driver()->rotationFails = true;
        $uuid = $this->row('vpn_service', $serviceId)['client_uuid'];
        $this->resetHttp();
        $this->pressButton(self::CLAIMER, 'cl_rst:'.$serviceId);
        $this->pressButton(self::CLAIMER, $this->callbackStartingWith('cl_rst_ok:'));
        self::assertSame($uuid, $this->row('vpn_service', $serviceId)['client_uuid']);
        self::assertTrue($this->sentTextContains($this->botText('claim.reset_failed')));
    }

    private function tokenSent(): string
    {
        foreach ($this->http->telegramTexts() as $text) {
            if (1 === preg_match('/claim_([A-Za-z0-9_-]{43})(?![A-Za-z0-9_-])/', $text, $m)) {
                return $m[1];
            }
        }
        self::fail('no claim link was sent');
    }

    private function assertNoTokenInTheMessageLog(string $token, bool $wasLogged = true): void
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT payload FROM bot_message_log');
        self::assertNotSame([], $rows);
        foreach ($rows as $payload) {
            self::assertStringNotContainsString($token, (string) $payload);
        }
        if ($wasLogged) {
            self::assertNotSame([], array_filter($rows, static fn (mixed $p): bool => str_contains((string) $p, 'claim_[redacted]')), 'it was there, masked');
        }
    }

    /**
     * Callback data of every inline button sent since the last reset (link buttons allowed).
     *
     * @return list<string>
     */
    private function callbacks(): array
    {
        $callbacks = [];
        foreach ([...$this->http->requests('/sendMessage'), ...$this->http->requests('/sendPhoto')] as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((is_array($markup) ? $markup['inline_keyboard'] ?? [] : []) as $row) {
                foreach ($row as $button) {
                    if (isset($button['callback_data'])) {
                        self::assertLessThanOrEqual(64, strlen((string) $button['callback_data']));
                        $callbacks[] = (string) $button['callback_data'];
                    }
                }
            }
        }

        return $callbacks;
    }

    private function callbackStartingWith(string $prefix): string
    {
        $matching = array_values(array_filter($this->callbacks(), static fn (string $d): bool => str_starts_with($d, $prefix)));
        self::assertNotSame([], $matching, 'no button '.$prefix);

        return $matching[count($matching) - 1];
    }

    /**
     * @return list<string>
     */
    private function textsTo(string $chatId): array
    {
        $texts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = $this->requestBody($request);
            if ($chatId === (string) ($body['chat_id'] ?? '')) {
                $texts[] = (string) ($body['text'] ?? '');
            }
        }

        return $texts;
    }

    private function sentToContains(string $chatId, string $needle): bool
    {
        foreach ($this->textsTo($chatId) as $text) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
