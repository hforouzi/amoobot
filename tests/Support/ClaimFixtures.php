<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Entity\VpnService;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Application\ClaimService;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\CreatedClaimLink;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Claim links on top of the reseller fixtures: resellers and claim links switched on, the reseller ($this->user,
 * Telegram id CUSTOMER) funded and owning one service he bought at wholesale ($this->sold), the bot's username known
 * (getMe), rate limits reset. A claimer is any other Telegram user (claimer()).
 */
trait ClaimFixtures
{
    use ResellerFixtures;

    protected const BOT_USERNAME = 'amoo_test_bot';

    protected VpnService $sold;

    protected function setUpClaims(): void
    {
        $this->setUpReseller();
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'true');
        // Not purged between tests: the Mini App (and with it the startapp link) is off unless a test turns it on.
        $this->setting(\App\MiniApp\Application\MiniAppLinks::ENABLED_SETTING, 'false');
        $this->fund(1_000_000);
        $this->buy();
        $this->sold = $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->reseller]) ?? self::fail('no service was sold');
        $this->resetClaimState();
    }

    /** Rate limits, the cached bot username and the HTTP log start empty; getMe names the bot. */
    protected function resetClaimState(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::getContainer()->get('cache.app')->deleteItem('bot_identity_username');
        $this->resetHttp();
        $this->driver()->ownerUpdates = [];
    }

    /** Forgets the requests sent so far; the gateways and getMe keep answering. */
    protected function resetHttp(): void
    {
        $this->http->reset();
        $this->answerGateways();
        $this->http->onJson('/getMe', ['ok' => true, 'result' => ['id' => 100000001, 'is_bot' => true, 'username' => self::BOT_USERNAME]]);
    }

    protected function tearDownClaims(): void
    {
        Clock::set(new NativeClock());
    }

    protected function claimLinks(): ClaimLinks
    {
        return self::getContainer()->get(ClaimLinks::class);
    }

    protected function claims(): ClaimService
    {
        return self::getContainer()->get(ClaimService::class);
    }

    protected function makeLink(?int $serviceId = null): CreatedClaimLink
    {
        return $this->claimLinks()->createForReseller($this->user, $serviceId ?? (int) $this->sold->getId());
    }

    protected function claimer(string $telegramId = '8701'): User
    {
        return $this->em->getRepository(\App\Entity\TelegramAccount::class)->findOneBy(['telegramId' => $telegramId])?->getUser() ?? $this->createUser($telegramId);
    }

    /**
     * Preview, then claim, as one person would.
     *
     * @return array{string, VpnService}
     */
    protected function claimWith(string $token, string $telegramId = '8701'): array
    {
        $user = $this->claimer($telegramId);
        $preview = $this->claims()->preview($token, $user, $telegramId);

        return $this->claims()->claim($preview->claimId, $preview->ticket, $user, $telegramId);
    }

    protected function ownerOf(VpnService $service): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT user_id FROM vpn_service WHERE id = ?', [$service->getId()]);
    }

    protected function claimStatus(int $claimId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM service_claim WHERE id = ?', [$claimId]);
    }
}
