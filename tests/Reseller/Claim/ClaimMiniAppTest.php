<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ClaimToken;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\TelegramInitData;

/**
 * The Mini App side of claim links: opened with startapp=claim_<token>, /app/auth (the same deep-link router as the
 * bot's /start) answers with the claim screen to open, whose URL carries a ticket, never the token. "Add to my account"
 * POSTs the ticket with the CSRF token. Without the CSRF token, with someone else's ticket, or with claim links off
 * (404, no "start" in the answer) nothing changes.
 */
final class ClaimMiniAppTest extends IntegrationTestCase
{
    use ClaimFixtures;
    use MiniAppTestTrait;

    private const CLAIMER = '8701';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        $this->startMiniAppClient();
        $this->createUser(self::CLAIMER);
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testOpeningTheAppWithAClaimLinkShowsTheClaimScreenAndTheButtonClaims(): void
    {
        $token = $this->makeLink()->token;

        $session = $this->authWithStart(self::CLAIMER, 'claim_'.$token);
        $start = $session['start'] ?? self::fail('no start target');
        self::assertMatchesRegularExpression('#^/app/view/claim/\d+/[0-9a-z]{7}[A-Za-z0-9_-]{16}$#D', $start);
        self::assertStringNotContainsString($token, $start);

        $html = $this->appGet($start, $session);
        self::assertSame(200, $this->lastStatus(), $html);
        self::assertStringContainsString('data-claim-accept', $html);
        self::assertStringNotContainsString($token, $html);
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold), 'opening changes nothing');

        [$claimId, $ticket] = array_slice(explode('/', $start), -2);
        // Without the CSRF token: refused by the firewall, nothing claimed.
        $this->postJson('/app/api/claim/'.$claimId, $session, ['ticket' => $ticket], 'wrong');
        self::assertSame(403, $this->lastStatus());
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));

        $answer = $this->postJson('/app/api/claim/'.$claimId, $session, ['ticket' => $ticket]);
        self::assertSame(200, $this->lastStatus(), (string) json_encode($answer));
        self::assertSame('/app/view/services/'.$this->sold->getId(), $answer['redirect'] ?? null);
        self::assertSame($this->claimer(self::CLAIMER)->getId(), $this->ownerOf($this->sold));

        // The service is now his in the app; the same tap again is "already yours".
        $this->appGet('/app/view/services/'.$this->sold->getId(), $session);
        self::assertSame(200, $this->lastStatus());
        $again = $this->postJson('/app/api/claim/'.$claimId, $session, ['ticket' => $ticket]);
        self::assertSame(200, $this->lastStatus(), (string) json_encode($again));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
    }

    public function testTheResellersOrderOfAClaimedServiceDoesNotLeadToIt(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $this->renewSold((int) $this->sold->getId());
        $renewal = (int) $this->em->getConnection()->fetchOne("SELECT id FROM `order` WHERE type = 'renewal'");

        $reseller = $this->authWithStart(self::CUSTOMER, null);
        $html = $this->appGet('/app/view/orders/'.$renewal, $reseller);
        self::assertSame(200, $this->lastStatus());
        self::assertStringNotContainsString('data-service-link', $html);
        $this->appGet('/app/view/services/'.$this->sold->getId(), $reseller);
        self::assertSame(404, $this->lastStatus());
    }

    public function testAnUnusableLinkOpensTheSameFailureScreen(): void
    {
        foreach (['claim_'.ClaimToken::generate(), 'claim_short', 'claim_'.str_repeat('A', 43).'B'] as $param) {
            $session = $this->authWithStart(self::CLAIMER, $param);
            $start = $session['start'] ?? self::fail('no start for '.$param);
            self::assertSame('/app/view/claim/failed/claim_unavailable', $start);
            $html = $this->appGet($start, $session);
            self::assertStringContainsString('data-claim-failed', $html);
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
    }

    public function testATicketOfSomeoneElseOrAnAlteredOneClaimsNothing(): void
    {
        $token = $this->makeLink()->token;
        $start = $this->authWithStart(self::CLAIMER, 'claim_'.$token)['start'] ?? self::fail('no start');
        [$claimId, $ticket] = array_slice(explode('/', $start), -2);

        $other = $this->authWithStart('8702', null);
        $this->postJson('/app/api/claim/'.$claimId, $other, ['ticket' => $ticket]);
        self::assertSame(422, $this->lastStatus());
        $mine = $this->authWithStart(self::CLAIMER, null);
        foreach ([$ticket."\n", substr($ticket, 0, -1), $ticket.'x', ''] as $bad) {
            $this->postJson('/app/api/claim/'.$claimId, $mine, ['ticket' => $bad]);
            self::assertSame(422, $this->lastStatus());
        }
        $this->appGet('/app/view/claim/'.$claimId.'/'.substr($ticket, 0, -1).'Z', $other);
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
    }

    public function testWithClaimLinksOffTheAppIsExactlyAsBefore(): void
    {
        $token = $this->makeLink()->token;
        $start = $this->authWithStart(self::CLAIMER, 'claim_'.$token)['start'] ?? self::fail('no start');
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');

        $session = $this->authWithStart(self::CLAIMER, 'claim_'.$token);
        self::assertArrayNotHasKey('start', $session);
        $this->appGet($start, $session);
        self::assertSame(404, $this->lastStatus());
        [$claimId, $ticket] = array_slice(explode('/', $start), -2);
        $this->postJson('/app/api/claim/'.$claimId, $session, ['ticket' => $ticket]);
        self::assertSame(404, $this->lastStatus());
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
    }

    public function testTheClaimRoutesAreRateLimitedPerAddress(): void
    {
        $session = $this->authWithStart(self::CLAIMER, null);
        for ($i = 0; $i < 31; ++$i) {
            $this->appGet('/app/view/claim/1/0000000AAAAAAAAAAAAAAAA', $session);
        }
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-claim-failed', $html);
        self::assertStringContainsString(self::getContainer()->get('translator')->trans('claim.error.rate_limited', [], 'miniapp', 'fa'), $html);
    }

    /** @phpstan-impure */
    private function lastStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Signs in with initData carrying this start_param (or none).
     *
     * @return array{token: string, csrf: string, start?: string}
     */
    private function authWithStart(string $telegramId, ?string $startParam): array
    {
        $extra = null === $startParam ? [] : ['start_param' => $startParam];
        $this->postAuth(TelegramInitData::signed($this->botToken(), TelegramInitData::user($telegramId), null, $extra));
        self::assertSame(200, $this->lastStatus(), (string) $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $session = ['token' => (string) $body['token'], 'csrf' => (string) $body['csrfToken']];
        if (isset($body['start'])) {
            $session['start'] = (string) $body['start'];
        }

        return $session;
    }

    /**
     * @param array{token: string, csrf: string} $session
     * @param array<string, mixed>               $body
     *
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $session, array $body, ?string $csrf = null): array
    {
        $this->client->request('POST', $path, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$session['token'],
            'HTTP_X_CSRF_TOKEN' => $csrf ?? $session['csrf'],
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
