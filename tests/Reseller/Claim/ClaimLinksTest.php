<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\ServiceClaim;
use App\Entity\ServiceOwnershipEvent;
use App\Entity\VpnService;
use App\MiniApp\Application\MiniAppLinks;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Application\ClaimTickets;
use App\Reseller\Application\ResellerAdministration;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\ClaimToken;
use App\Reseller\Domain\ServiceClaimed;
use App\Security\AdminActor;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Claim links from both sides (docs/design/reseller-and-claim.md, S2): a 256-bit token stored only as its hash, single
 * use, expiring, revocable; the claim moves the owner, rotates the subscription page token and keeps the panel
 * credentials (D4), writes one ownership event, tells the panel (best effort) and both people. Every unusable link
 * gets the same answer. Trials, deleted services, the creator himself and a disabled reseller's links are refused.
 */
final class ClaimLinksTest extends IntegrationTestCase
{
    use ClaimFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testTheTokenIsRandom256BitsAndOnlyItsHashIsStored(): void
    {
        $link = $this->makeLink();
        $other = $this->makeLink();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $link->token);
        self::assertNotSame($link->token, $other->token);
        self::assertSame('https://t.me/'.self::BOT_USERNAME.'?start=claim_'.$link->token, $link->botLink);
        self::assertLessThanOrEqual(64, strlen('claim_'.$link->token), 'Telegram start parameter limit');
        self::assertNull($link->appLink, 'the Mini App is off');
        $row = $this->row('service_claim', (int) $link->claim->getId());
        self::assertSame(hash('sha256', $link->token), $row['token_hash']);
        self::assertTrue($other->replacedOpenLink, 'one open link per service: the second replaced the first');
        self::assertSame(ClaimStatus::REVOKED, $this->claimStatus((int) $link->claim->getId()));
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus((int) $other->claim->getId()));
        $this->assertTokenNowhereInTheDatabase($link->token);
        $this->assertTokenNowhereInTheDatabase($other->token);

        $this->setting(MiniAppLinks::ENABLED_SETTING, 'true');
        self::assertSame('https://t.me/'.self::BOT_USERNAME.'?startapp=claim_'.($third = $this->makeLink())->token, $third->appLink);
    }

    public function testTheDefaultLifetimeIsSevenDaysAndTheSettingOnlyChangesNewLinks(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        Clock::set(new MockClock($now));
        $week = $this->makeLink();
        self::assertEquals($now->modify('+168 hours'), $week->claim->getExpiresAt());

        $this->setting(ResellerSettings::CLAIM_TTL_HOURS, '2');
        $short = $this->makeLink();
        self::assertEquals($now->modify('+2 hours'), $short->claim->getExpiresAt());
        $this->setting(ResellerSettings::CLAIM_TTL_HOURS, '99999');
        self::assertSame(ResellerSettings::MAX_CLAIM_TTL_HOURS, self::getContainer()->get(ResellerSettings::class)->claimTtlHours(), 'capped at 30 days');
        $this->setting(ResellerSettings::CLAIM_TTL_HOURS, '12abc');
        self::assertSame(ResellerSettings::DEFAULT_CLAIM_TTL_HOURS, self::getContainer()->get(ResellerSettings::class)->claimTtlHours(), 'unreadable: the default');
    }

    public function testAClaimMovesTheServiceRotatesThePageTokenAndKeepsThePanelCredentials(): void
    {
        $before = $this->row('vpn_service', (int) $this->sold->getId());
        $events = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ServiceClaimed::class, static function (ServiceClaimed $e) use (&$events): void {
            $events[] = $e;
        });
        $link = $this->makeLink();
        $this->http->reset();

        [$outcome, $service] = $this->claimWith($link->token, '8701');

        $claimer = $this->claimer('8701');
        self::assertSame('claimed', $outcome);
        $after = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame($claimer->getId(), (int) $after['user_id']);
        self::assertNotSame($before['public_token'], $after['public_token'], 'the /s/ page link the reseller had stops working');
        foreach (['client_uuid', 'sub_id', 'remote_id', 'config_links', 'subscription_url', 'sold_by_reseller_id', 'expires_at', 'traffic_limit_bytes'] as $kept) {
            self::assertSame($before[$kept], $after[$kept], $kept.' is kept (D4)');
        }
        $claim = $this->row('service_claim', (int) $link->claim->getId());
        self::assertSame(ClaimStatus::CLAIMED, $claim['status']);
        self::assertNull($claim['open_service_id']);
        self::assertSame($claimer->getId(), (int) $claim['claimed_by_user_id']);
        self::assertSame('8701', $claim['claimed_by_telegram_id']);
        self::assertNotNull($claim['claimed_at']);
        $event = $this->em->getRepository(ServiceOwnershipEvent::class)->findOneBy(['service' => $service]) ?? self::fail('no ownership event');
        self::assertSame([$this->user->getId(), $claimer->getId(), (int) $link->claim->getId(), ServiceOwnershipEvent::ACTOR_CUSTOMER], [$event->getFromUser()->getId(), $event->getToUser()->getId(), $event->getClaim()?->getId(), $event->getActorType()]);
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
        self::assertCount(1, $events);
        self::assertSame([(int) $link->claim->getId(), (int) $service->getId(), (int) $this->user->getId(), (int) $claimer->getId(), (int) $this->reseller->getId()], [$events[0]->claimId, $events[0]->serviceId, $events[0]->fromUserId, $events[0]->toUserId, $events[0]->resellerId]);

        // The panel learns the new owner (best effort, after the commit).
        self::assertSame([['remoteId' => $after['remote_id'], 'telegramId' => 8701, 'comment' => sprintf('amoobot order #%d, claimed', (int) $after['order_id'])]], $this->driver()->ownerUpdates);
        self::assertSame(ServiceClaim::SYNC_SYNCED, $claim['panel_sync_status']);

        // No money row was touched: the ledger and payments reconcile, and no order was made.
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());

        // The new owner sees it; the reseller does not any more.
        $finder = self::getContainer()->get(CustomerServiceFinder::class);
        self::assertNotNull($finder->ownedBy($claimer, (int) $service->getId()));
        self::assertNull($finder->ownedBy($this->user, (int) $service->getId()));
    }

    public function testALinkWorksOnce(): void
    {
        $link = $this->makeLink();
        $this->claimWith($link->token, '8701');

        $this->assertUnavailable(fn () => $this->claimWith($link->token, '8702'));
        try {
            $this->claimWith($link->token, '8701');
            self::fail('the claimer opened his used link again');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::ALREADY_YOURS, $e->error);
        }
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
    }

    public function testASecondDeliveryOfTheSameConfirmationIsAlreadyYoursAndWritesNothing(): void
    {
        $link = $this->makeLink();
        $user = $this->claimer('8701');
        $preview = $this->claims()->preview($link->token, $user, '8701');

        self::assertSame('claimed', $this->claims()->claim($preview->claimId, $preview->ticket, $user, '8701')[0]);
        self::assertSame('already_yours', $this->claims()->claim($preview->claimId, $preview->ticket, $user, '8701')[0]);
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
    }

    /**
     * @return iterable<string, array{\Closure(self, string): string}>
     */
    public static function unusableLinks(): iterable
    {
        yield 'unknown token' => [static fn (self $t, string $token): string => ClaimToken::generate()];
        yield 'truncated by one' => [static fn (self $t, string $token): string => substr($token, 0, 42)];
        yield 'truncated a lot' => [static fn (self $t, string $token): string => substr($token, 0, 10)];
        yield 'one character more' => [static fn (self $t, string $token): string => $token.'A'];
        yield 'trailing newline' => [static fn (self $t, string $token): string => $token."\n"];
        yield 'trailing space' => [static fn (self $t, string $token): string => $token.' '];
        yield 'leading space' => [static fn (self $t, string $token): string => ' '.$token];
        yield 'base64 padding' => [static fn (self $t, string $token): string => substr($token, 0, 42).'='];
        yield 'standard base64 alphabet' => [static fn (self $t, string $token): string => substr($token, 0, 42).'+'];
        yield 'one character changed' => [static fn (self $t, string $token): string => substr($token, 0, 42).('A' === $token[42] ? 'B' : 'A')];
        yield 'upper-cased' => [static fn (self $t, string $token): string => strtoupper($token) === $token ? strtolower($token) : strtoupper($token)];
        yield 'NUL byte' => [static fn (self $t, string $token): string => $token."\0"];
        yield 'expired' => [static function (self $t, string $token): string {
            $t->em->getConnection()->executeStatement('UPDATE service_claim SET expires_at = ?', [(new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s')]);

            return $token;
        }];
        yield 'revoked' => [static function (self $t, string $token): string {
            $t->claimLinks()->revokeByReseller($t->user, (int) $t->em->getConnection()->fetchOne('SELECT id FROM service_claim'));

            return $token;
        }];
        yield 'owner changed' => [static function (self $t, string $token): string {
            $t->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ?', [$t->claimer('8799')->getId()]);

            return $token;
        }];
        yield 'service deleted' => [static function (self $t, string $token): string {
            $t->em->getConnection()->executeStatement('UPDATE vpn_service SET status = ?', [VpnServiceStatus::DELETED]);

            return $token;
        }];
        yield 'claim links switched off' => [static function (self $t, string $token): string {
            $t->setting(ResellerSettings::CLAIMS_ENABLED, 'false');

            return $token;
        }];
        yield 'resellers switched off' => [static function (self $t, string $token): string {
            $t->setting(ResellerSettings::ENABLED, 'false');

            return $token;
        }];
    }

    /**
     * @param \Closure(self, string): string $spoil
     */
    #[DataProvider('unusableLinks')]
    public function testEveryUnusableLinkGetsTheSameAnswerAndChangesNothing(\Closure $spoil): void
    {
        $link = $this->makeLink();
        $token = $spoil($this, $link->token);
        $this->em->clear();
        $owner = $this->ownerOf($this->sold);

        $this->assertUnavailable(fn () => $this->claimWith($token, '8701'));
        self::assertSame($owner, $this->ownerOf($this->sold));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'claimed'"));
    }

    public function testATicketIsBoundToItsClaimAndItsUser(): void
    {
        $link = $this->makeLink();
        $preview = $this->claims()->preview($link->token, $this->claimer('8701'), '8701');

        // Another Telegram user, another claim, a forged or altered ticket: the generic answer, nothing claimed.
        $this->assertUnavailable(fn () => $this->claims()->claim($preview->claimId, $preview->ticket, $this->claimer('8702'), '8702'));
        $this->assertUnavailable(fn () => $this->claims()->claim($preview->claimId + 1, $preview->ticket, $this->claimer('8701'), '8701'));
        $this->assertUnavailable(fn () => $this->claims()->claim($preview->claimId, substr($preview->ticket, 0, -1).('A' === substr($preview->ticket, -1) ? 'B' : 'A'), $this->claimer('8701'), '8701'));
        $this->assertUnavailable(fn () => $this->claims()->claim($preview->claimId, $preview->ticket."\n", $this->claimer('8701'), '8701'));
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus($preview->claimId));

        // An old ticket expires.
        Clock::set(new MockClock((new \DateTimeImmutable())->modify('+'.(ClaimTickets::TTL_SECONDS + 5).' seconds')));
        $this->assertUnavailable(fn () => $this->claims()->claim($preview->claimId, $preview->ticket, $this->claimer('8701'), '8701'));
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus($preview->claimId));
    }

    public function testTheCreatorCannotClaimHisOwnLink(): void
    {
        $link = $this->makeLink();
        try {
            $this->claims()->preview($link->token, $this->user, self::CUSTOMER);
            self::fail('own link previewed');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::OWN_SERVICE, $e->error);
        }
        // Even with a ticket made for him.
        $ticket = self::getContainer()->get(ClaimTickets::class)->issue((int) $link->claim->getId(), self::CUSTOMER, ClaimToken::hash($link->token));
        try {
            $this->claims()->claim((int) $link->claim->getId(), $ticket, $this->user, self::CUSTOMER);
            self::fail('own link claimed');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::OWN_SERVICE, $e->error);
        }
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus((int) $link->claim->getId()));
    }

    public function testExpiryEdges(): void
    {
        $start = new \DateTimeImmutable('2026-11-01 10:00:00');
        Clock::set(new MockClock($start));
        $this->setting(ResellerSettings::CLAIM_TTL_HOURS, '1');
        $link = $this->makeLink();
        $id = (int) $link->claim->getId();
        $maintenance = self::getContainer()->get(\App\Reseller\Application\ClaimMaintenance::class);

        // One second before the end: still usable, and the sweep leaves it.
        Clock::set(new MockClock($start->modify('+3599 seconds')));
        self::assertSame(0, $maintenance->expire(100));
        $preview = $this->claims()->preview($link->token, $this->claimer('8701'), '8701');

        // At the very second it expires: refused (expires_at <= now), closed as expired by the claim itself.
        Clock::set(new MockClock($start->modify('+3600 seconds')));
        $this->assertUnavailable(fn () => $this->claims()->claim($preview->claimId, $preview->ticket, $this->claimer('8701'), '8701'));
        self::assertSame(ClaimStatus::EXPIRED, $this->claimStatus($id));
        self::assertNull($this->row('service_claim', $id)['open_service_id']);

        // The sweep closes another one at its time, and a new link can be made at once.
        $second = $this->makeLink();
        Clock::set(new MockClock($start->modify('+7200 seconds')));
        self::assertSame(0, $maintenance->expire(100, true) - 1, 'dry run counts it');
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus((int) $second->claim->getId()), 'dry run changes nothing');
        self::assertSame(1, $maintenance->expire(100));
        self::assertSame(ClaimStatus::EXPIRED, $this->claimStatus((int) $second->claim->getId()));
        self::assertSame(0, $maintenance->expire(100));
        self::assertFalse($this->makeLink()->replacedOpenLink, 'nothing open was left to replace');
    }

    public function testRevoking(): void
    {
        $link = $this->makeLink();
        $other = $this->createUser('8650');
        self::assertFalse($this->claimLinks()->revokeByReseller($other, (int) $link->claim->getId()), 'not a reseller');

        self::assertTrue($this->claimLinks()->revokeByReseller($this->user, (int) $link->claim->getId()));
        self::assertFalse($this->claimLinks()->revokeByReseller($this->user, (int) $link->claim->getId()), 'idempotent');
        $row = $this->row('service_claim', (int) $link->claim->getId());
        self::assertSame([ClaimStatus::REVOKED, null, ServiceClaim::REVOKED_BY_RESELLER, $this->user->getId()], [$row['status'], $row['open_service_id'], $row['revoke_reason'], (int) $row['revoked_by_user_id']]);
        $this->assertUnavailable(fn () => $this->claimWith($link->token, '8701'));

        $admin = $this->makeLink();
        self::assertTrue($this->claimLinks()->revokeByAdmin((int) $admin->claim->getId(), AdminActor::environmentBotAdmin('990001')));
        self::assertSame(ClaimStatus::REVOKED, $this->claimStatus((int) $admin->claim->getId()));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'claim.revoke_admin'"));
    }

    public function testNoLinkForADeletedOrTrialServiceOrAServiceInSettlementOrNotHis(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET status = ? WHERE id = ?', [VpnServiceStatus::DELETED, $this->sold->getId()]);
        $this->assertNotClaimable(fn () => $this->makeLink(), 'deleted');
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET status = ? WHERE id = ?', [VpnServiceStatus::ACTIVE, $this->sold->getId()]);

        $soldOrder = $this->sold->getOrder() ?? self::fail('no order');
        $this->em->getConnection()->executeStatement("UPDATE `order` SET type = 'trial' WHERE id = ?", [$soldOrder->getId()]);
        $this->em->refresh($soldOrder);
        $this->assertNotClaimable(fn () => $this->makeLink(), 'trial');
        $this->em->getConnection()->executeStatement("UPDATE `order` SET type = 'new_service' WHERE id = ?", [$soldOrder->getId()]);
        $this->em->refresh($soldOrder);

        // A renewal of it whose payment is held (between WalletPayment::open() and pay()).
        $order = $this->createOrder($this->user, $this->plan, 'renewal', 120000, $this->sold);
        $payment = $this->createPayment($order, $this->createGateway(), 'pending');
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'wallet' WHERE id = ?", [$payment->getId()]);
        $this->assertNotClaimable(fn () => $this->makeLink(), 'order_in_settlement');
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'cancelled' WHERE id = ?", [$order->getId()]);
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'rejected' WHERE id = ?", [$payment->getId()]);

        // Somebody else's service.
        $mine = $this->createActiveService($this->createUser('8660'), $this->inbound);
        $this->assertNotClaimable(fn () => $this->makeLink((int) $mine->getId()), 'not_sold_by_reseller');

        self::assertNotNull($this->makeLink()->claim->getId(), 'fine again');
    }

    public function testDisablingTheResellerRevokesHisOpenLinksAndReEnablingDoesNotRestoreThem(): void
    {
        $link = $this->makeLink();
        $administration = self::getContainer()->get(ResellerAdministration::class);
        $admin = AdminActor::environmentBotAdmin('990001');

        $administration->disable($this->reseller, $admin);

        $row = $this->row('service_claim', (int) $link->claim->getId());
        self::assertSame([ClaimStatus::REVOKED, ServiceClaim::REVOKED_RESELLER_DISABLED, null], [$row['status'], $row['revoke_reason'], $row['open_service_id']]);
        self::assertStringContainsString('"claim_links_revoked":1', (string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'reseller.disable'"));
        $this->assertUnavailable(fn () => $this->claimWith($link->token, '8701'));

        $administration->enable($this->reseller, $admin);
        $this->assertUnavailable(fn () => $this->claimWith($link->token, '8701'));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testMakingLinksIsRateLimitedPerReseller(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->makeLink();
        }
        try {
            $this->makeLink();
            self::fail('the 31st link of the hour was made');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::RATE_LIMITED, $e->error);
        }
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'open'"));
    }

    public function testBruteForceIsRateLimitedAndReportedToTheAdmins(): void
    {
        $link = $this->makeLink();
        $this->http->reset();
        for ($i = 1; $i <= 10; ++$i) {
            $this->assertUnavailable(fn () => $this->claims()->preview(ClaimToken::generate(), $this->claimer('8701'), '8701'));
        }
        // The limit counts invalid tries: now even the real token is refused for this user...
        try {
            $this->claims()->preview($link->token, $this->claimer('8701'), '8701');
            self::fail('not rate limited');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::RATE_LIMITED, $e->error);
        }
        // ...but not for anybody else.
        self::assertSame((int) $link->claim->getId(), $this->claims()->preview($link->token, $this->claimer('8702'), '8702')->claimId);

        // The fifth invalid one in an hour told the admins once (the env admin chat, LOGS_VIEW).
        $alerts = array_filter($this->http->requests('/sendMessage'), fn (array $r): bool => '999000' === (string) ($this->requestBody($r)['chat_id'] ?? '') && str_contains((string) ($this->requestBody($r)['text'] ?? ''), '8701'));
        self::assertCount(1, $alerts);
    }

    public function testAdminMadeLinkHandsOverAnyLiveServiceAndIsAudited(): void
    {
        $customer = $this->createUser('8670');
        $service = $this->createActiveService($customer, $this->inbound);
        $link = $this->claimLinks()->createForAdmin($service, AdminActor::environmentBotAdmin('990001'));

        self::assertNull($link->claim->getReseller());
        self::assertSame($customer->getId(), $link->claim->getFromUser()->getId());
        $audit = (string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'claim.create_admin'");
        self::assertStringNotContainsString($link->token, $audit);
        self::assertStringNotContainsString(ClaimToken::hash($link->token), $audit);

        $this->claimWith($link->token, '8701');
        self::assertSame($this->claimer('8701')->getId(), $this->ownerOf($service));
        self::assertTrue($this->sentToContains('8670', 'Service #'.$service->getId()) || $this->sentToContains('8670', 'سرویس #'.$service->getId()), 'the previous owner is told');
    }

    private function sentToContains(string $chatId, string $needle): bool
    {
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = $this->requestBody($request);
            if ($chatId === (string) ($body['chat_id'] ?? '') && str_contains((string) ($body['text'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{options: array<string, mixed>} $request
     *
     * @return array<string, mixed>
     */
    private function requestBody(array $request): array
    {
        $body = $request['options']['json'] ?? $request['options']['body'] ?? [];
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return is_array($body) ? $body : [];
    }

    private function assertUnavailable(\Closure $action): void
    {
        try {
            $action();
            self::fail('the link was usable');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::UNAVAILABLE, $e->error, $e->getMessage());
        }
    }

    private function assertNotClaimable(\Closure $action, string $reason): void
    {
        try {
            $action();
            self::fail('a link was made: '.$reason);
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::NOT_CLAIMABLE, $e->error);
            self::assertSame($reason, $e->reason);
        }
    }

    /** The token in no table of the database (claims, logs, audit, bot messages). */
    private function assertTokenNowhereInTheDatabase(string $token): void
    {
        foreach (['service_claim', 'admin_audit_log', 'bot_message_log', 'service_ownership_event'] as $table) {
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table) as $row) {
                self::assertStringNotContainsString($token, (string) json_encode($row), $table);
            }
        }
    }
}
