<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\ClaimToken;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;

/**
 * Claim links from the reseller Mini App, through ClaimLinks (the bot's service): the new link is in the answer once
 * (never stored, not even in checkout_request), the list shows status and dates only, revoke works on his own open
 * links only, and the S2 rate limit applies.
 */
final class ResellerMiniAppClaimLinksTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testMakeShowOnceListAndRevoke(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $answer = $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/claim-link', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), (string) json_encode($answer));
        self::assertMatchesRegularExpression('#https://t\.me/'.self::BOT_USERNAME.'\?start=claim_[A-Za-z0-9_-]{43}#', (string) $answer['html']);
        self::assertStringContainsString('data-copy=', (string) $answer['html']);
        self::assertStringContainsString('data:image/svg+xml;base64,', (string) $answer['html'], 'a QR');
        preg_match('/claim_([A-Za-z0-9_-]{43})/', (string) $answer['html'], $m);
        $token = $m[1] ?? self::fail('no token');

        // Stored nowhere: only its hash, and no idempotency answer keeps it.
        self::assertSame(ClaimToken::hash($token), $this->em->getConnection()->fetchOne('SELECT token_hash FROM service_claim'));
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM checkout_request WHERE result LIKE '%claim_%'"));
        $list = $this->appGet('/app/view/reseller/links', $session);
        self::assertStringNotContainsString($token, $list);
        self::assertStringContainsString('data-claim-status="open"', $list);
        self::assertStringNotContainsString($token, $this->appGet('/app/view/reseller/services/'.$this->sold->getId(), $session));
        self::assertStringContainsString('data-open-link-note', $this->appGet('/app/view/reseller/services/'.$this->sold->getId(), $session));

        // A second link replaces the first.
        $second = $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/claim-link', $session, [], self::newKey());
        self::assertStringContainsString('data-replaced', (string) $second['html']);
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'open'"));

        $openId = (int) $this->em->getConnection()->fetchOne("SELECT id FROM service_claim WHERE status = 'open'");
        $revoked = $this->appPostJson('/app/api/reseller/links/'.$openId.'/revoke', $session);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($revoked));
        self::assertSame(ClaimStatus::REVOKED, $this->claimStatus($openId));
        // Again: idempotent, nothing changes.
        $this->appPostJson('/app/api/reseller/links/'.$openId.'/revoke', $session);
        self::assertSame(200, $this->httpStatus());
    }

    public function testAnotherResellersLinkCannotBeRevokedOrSeen(): void
    {
        $theirs = $this->claimLinks()->createForReseller($this->otherUser, (int) $this->othersService->getId());
        $session = $this->authenticate(self::CUSTOMER);
        self::assertStringNotContainsString('data-claim-id="'.$theirs->claim->getId().'"', $this->appGet('/app/view/reseller/links', $session));
        $this->appPostJson('/app/api/reseller/links/'.$theirs->claim->getId().'/revoke', $session);
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus((int) $theirs->claim->getId()));
        $this->appPostJson('/app/api/reseller/services/'.$this->othersService->getId().'/claim-link', $session);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_claim'));
    }

    public function testTheS2RateLimitApplies(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        for ($i = 0; $i < 30; ++$i) {
            $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/claim-link', $session);
            self::assertSame(200, $this->httpStatus());
        }
        $answer = $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/claim-link', $session);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('rate_limited', $answer['error']);
    }
}
