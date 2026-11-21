<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\CustomerViewSnapshot;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;

/**
 * Golden files of a reseller-sold service in the Mini App, recorded before PM1d: the reseller's services list and
 * service page while it is unclaimed, and the claimer's after the claim, in Persian and English.
 */
final class MiniAppClaimViewSnapshotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;
    use CustomerViewFixtures;
    use MiniAppTestTrait;

    private const CLAIMER = '8701';
    private const LOCALES = ['fa' => '', 'en' => '?lang=en'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCustomerView();
        $this->setUpClaims();
        $this->createUser(self::CLAIMER);
        $this->em->refresh($this->sold);
        // Bought "now" for 30 days: whole days left would depend on the seconds the test takes, so a fixed offset.
        $this->sold->setExpiresAt($this->now->modify('+20 days 12 hours'));
        $this->em->flush();
        $this->serviceNames[(int) $this->sold->getId()] = 'SERVICE_SOLD';
        $this->opaque[(string) $this->sold->getPublicToken()] = 'TOKEN_SOLD';
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    public function testTheResellersViewOfAnUnclaimedService(): void
    {
        $this->snapshotPages('miniapp-reseller-sold-unclaimed', $this->authenticate(self::CUSTOMER));
    }

    public function testTheClaimersViewAfterAClaim(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        // A claim gives the service a new public page token.
        $this->em->refresh($this->sold);
        $this->opaque[(string) $this->sold->getPublicToken()] = 'TOKEN_SOLD_CLAIMED';

        $this->snapshotPages('miniapp-reseller-sold-claimed', $this->authenticate(self::CLAIMER));
    }

    /**
     * @param array{token: string, csrf: string} $session
     */
    private function snapshotPages(string $name, array $session): void
    {
        foreach (['services' => '/app/view/services', 'service' => '/app/view/services/'.$this->sold->getId()] as $page => $path) {
            foreach (self::LOCALES as $locale => $query) {
                $html = $this->appGet($path.$query, $session);
                self::assertSame(200, $this->httpStatus(), $path);
                CustomerViewSnapshot::assertMatches('MiniApp', $name.'-'.$page.'.'.$locale.'.html', CustomerViewSnapshot::normalize($html, $this->now, $this->opaque, $this->serviceNames, $this->expiries()));
            }
        }
    }
}
