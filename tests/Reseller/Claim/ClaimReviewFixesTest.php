<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\Payment;
use App\Entity\ServiceClaim;
use App\Entity\VpnService;
use App\MiniApp\Checkout\PaymentProgress;
use App\Payment\Domain\PaymentStatus;
use App\Reseller\Application\ClaimMaintenance;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Security\AdminActor;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The fixes of the independent S2 review, one process (the races are in ClaimReviewParallelTest):
 *
 * - B1: a payment's return page, the Mini App payment / order screens and the bot's order detail show the service's
 *   access only while the service still belongs to the order's customer; after a claim (and a reset of links) the old
 *   payer sees that the payment went through, no link, no redirect. The owner still sees his.
 * - B3: a reset that fails after the panel applied it is saved as done; a database write that fails after the panel
 *   changed is written again; a panel that cannot be read back is "unconfirmed" with an admin alert; a concurrent write
 *   that put the old credentials back is undone; at most 3 resets a day.
 * - M3: a client that cannot take an owner is "not applicable" after one attempt, never retried.
 * - Minors: an old claimer reopening a returned service's link is not told "already yours"; a crash in the panel-sync
 *   bookkeeping after the commit still tells the claimer "done"; with claim links off the service screens do not query
 *   the settings again and again.
 */
final class ClaimReviewFixesTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;

    private const CLAIMER = '8701';

    private KernelBrowser $client;
    /** @var list<object> */
    private array $flushTraps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        $this->createUser(self::CLAIMER);
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        foreach ($this->flushTraps as $trap) {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $trap);
        }
        $this->tearDownClaims();
        parent::tearDown();
    }

    // ---- B1 ----

    public function testTheOldPayersReturnPageShowsNoLinkAfterAClaimAndAReset(): void
    {
        $returnToken = $this->returnTokenOfTheSoldService();
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
        $row = $this->row('vpn_service', (int) $this->sold->getId());

        $html = $this->returnPage($returnToken);
        self::assertStringContainsString('پرداخت انجام شد و سفارش تکمیل شده است.', $html);
        foreach ([(string) $row['sub_id'], (string) $row['client_uuid'], (string) $row['username'], 'http-equiv="refresh"', 'لینک اشتراک'] as $needle) {
            self::assertStringNotContainsString($needle, $html);
        }

        // The owner of a service still sees his: the reseller's own (unclaimed) purchase.
        $this->buy();
        $own = $this->em->getRepository(VpnService::class)->findOneBy([], ['id' => 'DESC']) ?? self::fail('no second service');
        $ownHtml = $this->returnPage($this->returnTokenOf((int) $own->getOrder()?->getId()));
        self::assertStringContainsString((string) $own->getSubId(), $ownHtml);
        self::assertStringContainsString('http-equiv="refresh"', $ownHtml);
    }

    /**
     * A customer starts a gateway renewal (waiting for payment: not "in settlement", so a link can still be made and
     * used), an admin hands the service over, the customer pays afterwards: his return page, Mini App and bot order
     * screens show the payment, never the new owner's service.
     */
    public function testAGatewayRenewalPaidAfterTheHandOverShowsNoLinkToThePayer(): void
    {
        $payer = $this->createUser('8801');
        $service = $this->createActiveService($payer, $this->sold->getInbound());
        $service->setSubscriptionUrl('https://panel.test/sub/payer-sub-0001')->setConfigText('vless://payer-config@edge.panel.test:443#x')->setSubId('payer-sub-0001');
        $this->em->flush();
        $plan = $this->sold->getOrder()?->getPlan() ?? self::fail('no plan');
        $order = $this->createOrder($payer, $plan, OrderType::RENEWAL, 50000, $service);
        $order->setStatus(OrderStatus::WAITING_PAYMENT);
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->em->flush();

        $link = $this->claimLinks()->createForAdmin($service, AdminActor::environmentBotAdmin('990001'));
        $this->claimWith($link->token, self::CLAIMER);
        self::assertSame($this->claimer(self::CLAIMER)->getId(), $this->ownerOf($service));

        // The renewal is paid now (as the gateway / an admin would have settled it).
        $this->em->getConnection()->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::CONFIRMED, $payment->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::PROVISIONED, $order->getId()]);
        $this->em->clear();

        $html = $this->returnPage((string) $payment->getReturnToken());
        self::assertStringContainsString('پرداخت انجام شد و سفارش تکمیل شده است.', $html);
        foreach (['payer-sub-0001', 'payer-config', 'existing_user', 'http-equiv="refresh"'] as $needle) {
            self::assertStringNotContainsString($needle, $html);
        }

        // Mini App: the payment screen and its status poll name no service.
        $reloaded = $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment gone');
        $progress = self::getContainer()->get(PaymentProgress::class);
        self::assertNull($progress->service($reloaded));
        self::assertNull($progress->snapshot($reloaded)['serviceId']);

        // The bot's order detail: no "View service" button, and it does not point to one.
        $this->resetHttp();
        $this->pressButton('8801', 'track_order:'.$order->getId());
        self::assertNotContains('service_view:'.$service->getId(), $this->callbackData());
        self::assertTrue($this->sentTextContains('پرداخت انجام شد و سفارش تکمیل شده است.'));
    }

    // ---- B3 ----

    public function testAResetWhoseAnswerWasLostIsSavedAsDone(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $this->driver()->rotationFailsAfterApply = true;

        [$outcome, $service] = $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());

        self::assertSame(ServiceOwnership::RESET_DONE, $outcome);
        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame($this->driver()->uuidOnPanel((string) $row['remote_id']), $row['client_uuid'], 'the database follows the panel');
        self::assertSame((string) $service->getClientUuid(), $row['client_uuid']);
    }

    public function testAPanelThatCannotBeReadBackIsUnconfirmedAndAlertsTheAdmins(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $before = $this->row('vpn_service', (int) $this->sold->getId());
        $this->driver()->rotationFailsAfterApply = true;
        $this->driver()->currentCredentialsFails = true;
        $this->resetHttp();

        try {
            $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
            self::fail('a reset whose outcome is unknown was reported as done');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::RESET_UNCONFIRMED, $e->error);
        }
        self::assertSame($before['client_uuid'], $this->row('vpn_service', (int) $this->sold->getId())['client_uuid'], 'nothing unconfirmed is written');
        self::assertTrue($this->sentTextContains('#'.$this->sold->getId()), 'the admins were told');
        self::assertNotSame([], array_filter($this->http->telegramTexts(), fn (string $t): bool => str_contains($t, '⚠️') && str_contains($t, '#'.$this->sold->getId())));
    }

    public function testAPanelFailureBeforeAnythingChangedSaysNothingChanged(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $before = $this->row('vpn_service', (int) $this->sold->getId());
        $this->driver()->rotationFails = true;

        try {
            $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
            self::fail('reset reported done');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::RESET_FAILED, $e->error);
        }
        self::assertSame($before['client_uuid'], $this->row('vpn_service', (int) $this->sold->getId())['client_uuid']);
        self::assertSame($before['client_uuid'], $this->driver()->uuidOnPanel((string) $before['remote_id']));
    }

    public function testADatabaseWriteThatFailsAfterThePanelChangedIsWrittenAgain(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $this->trapFlushOnce(static fn (object $entity, array $changes): bool => $entity instanceof VpnService && isset($changes['clientUuid']));

        [$outcome] = $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());

        self::assertSame(ServiceOwnership::RESET_DONE, $outcome);
        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame($this->driver()->uuidOnPanel((string) $row['remote_id']), $row['client_uuid'], 'panel and database agree');
    }

    public function testAConcurrentWriteThatPutTheOldCredentialsBackIsUndone(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $this->driver()->revertNextRotationOnce = true;

        $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());

        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame($row['client_uuid'], $this->driver()->uuidOnPanel((string) $row['remote_id']), 'applied again after the revert');
        self::assertSame(2, $this->driver()->rotationsOnPanel((string) $row['remote_id']));
    }

    public function testAtMostThreeResetsADay(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        for ($i = 1; $i <= 3; ++$i) {
            [$outcome] = $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
            self::assertSame(ServiceOwnership::RESET_DONE, $outcome);
        }
        try {
            $this->ownership()->resetConnectionLinks($this->claimer(self::CLAIMER), (int) $this->sold->getId(), $this->nonce());
            self::fail('a fourth reset');
        } catch (ClaimException $e) {
            self::assertSame(ClaimException::RATE_LIMITED, $e->error);
        }
        self::assertSame(3, $this->driver()->rotations);
    }

    // ---- M3 ----

    public function testAClientThatCannotTakeAnOwnerIsNotApplicableAfterOneAttempt(): void
    {
        $this->driver()->ownerNotApplicable = true;
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $claimId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM service_claim');

        $row = $this->row('service_claim', $claimId);
        self::assertSame(ServiceClaim::SYNC_NOT_APPLICABLE, $row['panel_sync_status']);
        self::assertSame(1, (int) $row['panel_sync_attempts']);
        self::assertNull($row['panel_sync_next_at']);

        $summary = self::getContainer()->get(ClaimMaintenance::class)->retryPanelSync(100);
        self::assertSame(0, $summary['tried'], 'never retried');
    }

    // ---- minors ----

    public function testAnOldClaimerReopeningAReturnedServicesLinkIsNotToldItIsHis(): void
    {
        $token = $this->makeLink()->token;
        $preview = $this->claims()->preview($token, $this->claimer(self::CLAIMER), self::CLAIMER);
        $this->claims()->claim($preview->claimId, $preview->ticket, $this->claimer(self::CLAIMER), self::CLAIMER);
        $this->em->clear();
        $service = $this->em->find(VpnService::class, $this->sold->getId()) ?? self::fail('service gone');
        $this->ownership()->returnToReseller($service, 'wrong person', AdminActor::environmentBotAdmin('990001'));
        $this->resetClaimState();

        foreach ([
            fn () => $this->claims()->preview($token, $this->claimer(self::CLAIMER), self::CLAIMER),
            fn () => $this->claims()->claim($preview->claimId, $preview->ticket, $this->claimer(self::CLAIMER), self::CLAIMER),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('the old link still answered');
            } catch (ClaimException $e) {
                self::assertSame(ClaimException::UNAVAILABLE, $e->error);
            }
        }
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
    }

    public function testACrashInThePanelSyncBookkeepingStillTellsTheClaimerItIsDone(): void
    {
        $token = $this->makeLink()->token;
        $this->driver()->ownerUpdateFailures = 1;
        // Saving the failed attempt fails too (the database went away just then).
        $this->trapFlushOnce(static fn (object $entity, array $changes): bool => $entity instanceof ServiceClaim && isset($changes['panelSyncAttempts']));
        $this->resetHttp();

        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        $confirm = $this->callbackStartingWith('cl_ok:');
        $this->resetHttp();
        $this->pressButton(self::CLAIMER, $confirm);

        self::assertSame($this->claimer(self::CLAIMER)->getId(), $this->ownerOf($this->sold));
        self::assertTrue($this->sentTextContains($this->botText('claim.done', ['service' => ['id' => (int) $this->sold->getId()]])));
        self::assertFalse($this->sentTextContains($this->botText('claim.failed')));
    }

    public function testWithClaimLinksOffTheServiceScreenDoesNotQueryTheSettingsEachTime(): void
    {
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');
        $ownership = $this->ownership();
        $counter = self::getContainer()->get(QueryCounter::class);

        $counter->start();
        self::assertFalse($ownership->canResetLinks($this->user, $this->sold));
        $first = $counter->stop();
        $counter->start();
        for ($i = 0; $i < 5; ++$i) {
            self::assertFalse($ownership->canResetLinks($this->user, $this->sold));
        }
        $again = $counter->stop();

        self::assertLessThanOrEqual(2, $first);
        self::assertSame(0, $again, 'remembered for the request');

        // Switching it on through Doctrine counts at once.
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'true');
        self::assertTrue(self::getContainer()->get(ResellerSettings::class)->claimsEnabled());
    }

    // ---- helpers ----

    private function ownership(): ServiceOwnership
    {
        return self::getContainer()->get(ServiceOwnership::class);
    }

    private function nonce(): string
    {
        $this->em->clear();
        $service = $this->em->find(VpnService::class, $this->sold->getId()) ?? self::fail('service gone');

        return $this->ownership()->resetNonce($service);
    }

    private function returnTokenOfTheSoldService(): string
    {
        return $this->returnTokenOf((int) $this->sold->getOrder()?->getId());
    }

    private function returnTokenOf(int $orderId): string
    {
        $token = $this->em->getConnection()->fetchOne('SELECT return_token FROM payment WHERE order_id = ? ORDER BY id LIMIT 1', [$orderId]);
        self::assertIsString($token);

        return $token;
    }

    private function returnPage(string $token): string
    {
        $this->client->request('GET', '/payment/return/'.$token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * The next flush that changes such an entity fails (as a lost database connection would); later ones work.
     *
     * @param \Closure(object, array<string, mixed>): bool $matches
     */
    private function trapFlushOnce(\Closure $matches): void
    {
        $trap = new class($matches) {
            private bool $sprung = false;

            public function __construct(private readonly \Closure $matches)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->sprung) {
                    return;
                }
                $uow = $args->getObjectManager()->getUnitOfWork();
                foreach ($uow->getScheduledEntityUpdates() as $entity) {
                    if (($this->matches)($entity, $uow->getEntityChangeSet($entity))) {
                        $this->sprung = true;

                        throw new \RuntimeException('Simulated database failure on flush');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $trap);
        $this->flushTraps[] = $trap;
    }

    /** @return list<string> callback data of every inline button sent since the last reset */
    private function callbackData(): array
    {
        $data = [];
        foreach ([...$this->http->requests('/sendMessage'), ...$this->http->requests('/sendPhoto')] as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((is_array($markup) ? $markup['inline_keyboard'] ?? [] : []) as $row) {
                foreach ($row as $button) {
                    if (isset($button['callback_data'])) {
                        $data[] = (string) $button['callback_data'];
                    }
                }
            }
        }

        return $data;
    }

    private function callbackStartingWith(string $prefix): string
    {
        foreach ($this->callbackData() as $data) {
            if (str_starts_with($data, $prefix)) {
                return $data;
            }
        }
        self::fail('no button '.$prefix);
    }
}
