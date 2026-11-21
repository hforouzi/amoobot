<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Reseller\Application\ServiceOwnership;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\CustomerViewSnapshot;
use App\Tests\Support\IntegrationTestCase;

/**
 * Golden files of a reseller-sold service in the bot, recorded before PM1d: the reseller's view while it is unclaimed
 * and after a claim, the claimer's view (with "reset links", D4), and the reset's warning and answer.
 */
final class CustomerViewClaimSnapshotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;
    use CustomerViewFixtures;

    private const CLAIMER = '8701';

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
        $this->registerSold('SOLD');
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    public function testTheResellersViewOfAnUnclaimedService(): void
    {
        $this->resetHttp();

        $this->pressButton(self::CUSTOMER, 'service_view:'.$this->sold->getId());

        CustomerViewSnapshot::assertTranscript('bot-detail-reseller-sold-unclaimed', $this->chat(self::CUSTOMER), $this->now, $this->serviceNames);
    }

    public function testTheClaimersAndTheResellersViewAfterAClaim(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $this->resetHttp();

        $this->pressButton(self::CLAIMER, 'service_view:'.$this->sold->getId());
        $claimer = $this->chat(self::CLAIMER);
        $this->resetHttp();
        $this->pressButton(self::CUSTOMER, 'service_view:'.$this->sold->getId());

        CustomerViewSnapshot::assertTranscript('bot-detail-reseller-sold-claimed', $claimer, $this->now, $this->serviceNames);
        CustomerViewSnapshot::assertTranscript('bot-detail-reseller-after-claim', $this->chat(self::CUSTOMER), $this->now, $this->serviceNames);
    }

    public function testResetLinksWarningAndAnswer(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $serviceId = (int) $this->sold->getId();
        $this->em->refresh($this->sold);
        $this->opaque[self::getContainer()->get(ServiceOwnership::class)->resetNonce($this->sold)] = 'NONCE';
        $this->resetHttp();

        $this->pressButton(self::CLAIMER, 'cl_rst:'.$serviceId);
        $this->pressButton(self::CLAIMER, 'cl_rst_ok:'.$serviceId.':'.self::getContainer()->get(ServiceOwnership::class)->resetNonce($this->sold));

        $this->em->clear();
        $this->sold = $this->em->find(VpnService::class, $serviceId) ?? self::fail('gone');
        $this->registerSold('RESET');
        CustomerViewSnapshot::assertTranscript('bot-reset-links', $this->chat(self::CLAIMER), $this->now, $this->serviceNames);
    }

    /** The sold service's random values (the test panel makes them) under one name. */
    private function registerSold(string $name): void
    {
        $this->serviceNames[(int) $this->sold->getId()] = 'SERVICE_SOLD';
        foreach (['TOKEN' => $this->sold->getPublicToken(), 'USERNAME' => $this->sold->getUsername(), 'EMAIL' => $this->sold->getClientEmail(), 'UUID' => $this->sold->getClientUuid(), 'SUB_ID' => $this->sold->getSubId(), 'REMOTE_ID' => $this->sold->getRemoteId()] as $kind => $value) {
            // A value the reset did not change keeps its first name.
            if (null !== $value && '' !== $value && !isset($this->opaque[$value])) {
                $this->opaque[$value] = $kind.'_'.$name;
            }
        }
        $order = $this->sold->getOrder();
        if ($order instanceof Order) {
            $this->opaque[(string) $order->getTrackingCode()] = 'TRACKING_SOLD';
        }
    }

    /**
     * @return list<string>
     */
    private function chat(string $telegramId): array
    {
        return array_values(array_filter($this->transcript(true), static fn (string $l): bool => str_contains(strtok($l, "\n") ?: '', 'chat='.$telegramId) || str_starts_with($l, 'answerCallbackQuery')));
    }
}
