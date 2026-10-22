<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\TelegramAccount;
use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Trial plans in the Mini App admin: create, edit (the entity's rules), activate / deactivate, and the claims list
 * searchable by customer; audit (source miniapp).
 */
final class MiniAppAdminTrialsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    public function testTrialPlansAndClaims(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $answer = $this->appPostJson('/app/api/admin/trial-plans', $session, ['title' => 'Try it', 'durationDays' => '۱', 'trafficGb' => '1', 'maxClaimsPerUser' => '1', 'sortOrder' => '0', 'isActive' => '1', 'inbound' => (string) $this->service->getInbound()?->getId()], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $trial = $this->em->getRepository(TrialPlan::class)->findOneBy(['title' => 'Try it']);
        self::assertInstanceOf(TrialPlan::class, $trial);
        self::assertSame($this->service->getInbound()?->getId(), $trial->getInbound()?->getId());

        $this->appPostJson('/app/api/admin/trial-plans/'.$trial->getId(), $session, ['title' => '', 'durationDays' => '1', 'maxClaimsPerUser' => '1', 'sortOrder' => '0'], self::newKey());
        self::assertSame(422, $this->httpStatus());
        $this->confirmedPost('/app/api/admin/trial-plans/'.$trial->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['trial_plan.create', 'trial_plan.deactivate'], array_column($this->auditRows(), 'action'));

        $this->em->clear();
        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => self::BUYER]);
        $reloaded = $this->em->find(TrialPlan::class, $trial->getId());
        self::assertNotNull($account);
        self::assertNotNull($reloaded);
        $this->em->persist((new TrialClaim())->setTelegramAccount($account)->setTrialPlan($reloaded));
        $this->em->flush();
        $claims = new Crawler($this->appGet('/app/admin/trial-claims?q='.self::BUYER, $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $claims->filter('[data-claim-id]'));
        self::assertCount(0, (new Crawler($this->appGet('/app/admin/trial-claims?q=nobody-here', $session)))->filter('[data-claim-id]'));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($this->appGet('/app/admin/trial-plans/'.$trial->getId(), $session)));
    }
}
