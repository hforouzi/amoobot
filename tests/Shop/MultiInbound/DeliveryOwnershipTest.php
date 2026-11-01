<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\MissingDeliveryResender;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Payment\Application\PaymentDeliveryLog;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * M2e E1 review R1 (D5): a delivery message that is sent again (scheduled job, retried settlement) goes to the order's
 * user only while that user still owns an active service and the delivery is at most PaymentDeliveryLog::MAX_AGE old;
 * otherwise nothing is sent to anyone and the delivery is abandoned with its reason.
 */
final class DeliveryOwnershipTest extends IntegrationTestCase
{
    use ClaimFixtures;

    private const DELIVERY = '✅ سرویس شما آماده شد';
    private const CLAIMER = '8701';

    /** Chats whose sendMessage Telegram refuses (429). */
    private array $refused = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->setting(\App\Reseller\Application\ResellerSettings::CLAIMS_ENABLED, 'true');
        $this->setting(\App\MiniApp\Application\MiniAppLinks::ENABLED_SETTING, 'false');
        $this->fund(1_000_000);
        $this->refuseTelegram();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testAResendAfterAClaimReachesNeitherTheResellerNorTheClaimer(): void
    {
        $service = $this->resellerSaleWithFailedDelivery();
        $link = $this->makeLink((int) $service->getId());
        [$outcome] = $this->claimWith($link->token, self::CLAIMER);
        self::assertSame('claimed', $outcome);
        self::assertNotSame((int) $this->user->getId(), $this->ownerOf($service));
        $this->resetHttp();
        $this->refuseTelegram();

        $summary = $this->job();

        self::assertSame(0, $this->deliveries(self::CUSTOMER), 'the reseller gets nothing');
        self::assertSame(0, $this->deliveries(self::CLAIMER), 'the claimer gets nothing from the job');
        self::assertSame([PaymentDeliveryLog::ABANDONED, 'owner_changed'], $this->state());
        self::assertSame(0, $summary['resent']);

        // A retried settlement of that payment does not send it either.
        $this->retrySettlement();
        self::assertSame(0, $this->deliveries(self::CUSTOMER));
        self::assertSame(0, $this->deliveries(self::CLAIMER));
    }

    public function testAResendAfterAnAdminTransferSendsNothing(): void
    {
        $service = $this->resellerSaleWithFailedDelivery();
        $other = $this->createUser('8801');
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ? WHERE id = ?', [$other->getId(), $service->getId()]);
        $this->em->clear();

        $this->job();
        $this->retrySettlement();

        self::assertSame(0, $this->deliveries(self::CUSTOMER));
        self::assertSame(0, $this->deliveries('8801'));
        self::assertSame([PaymentDeliveryLog::ABANDONED, 'owner_changed'], $this->state());
    }

    public function testASuspendedServiceIsNotResent(): void
    {
        $service = $this->resellerSaleWithFailedDelivery();
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET status = 'suspended' WHERE id = ?", [$service->getId()]);
        $this->em->clear();

        $this->job();

        self::assertSame(0, $this->deliveries(self::CUSTOMER));
        self::assertSame([PaymentDeliveryLog::ABANDONED, 'service_not_active'], $this->state());
    }

    public function testADeletedServiceIsNotResent(): void
    {
        $service = $this->resellerSaleWithFailedDelivery();
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET status = 'deleted' WHERE id = ?", [$service->getId()]);
        $this->em->clear();

        $this->job();
        $this->retrySettlement();

        self::assertSame(0, $this->deliveries(self::CUSTOMER));
        self::assertSame(PaymentDeliveryLog::ABANDONED, $this->state()[0]);
    }

    public function testADeliveryOlderThanTheMaximumAgeIsNotResent(): void
    {
        $this->resellerSaleWithFailedDelivery();
        Clock::set(new MockClock((new \DateTimeImmutable())->modify('+61 minutes')));

        $this->job();

        self::assertSame(0, $this->deliveries(self::CUSTOMER));
        self::assertSame([PaymentDeliveryLog::ABANDONED, 'too_old'], $this->state());
    }

    public function testTheNormalResendStillReachesTheOwnerExactlyOnce(): void
    {
        $this->resellerSaleWithFailedDelivery();
        Clock::set(new MockClock((new \DateTimeImmutable())->modify('+59 minutes')));

        $first = $this->job();
        $this->job();
        $this->retrySettlement();

        self::assertSame(1, $first['resent']);
        self::assertSame(1, $this->deliveries(self::CUSTOMER));
        self::assertSame(PaymentDeliveryLog::SENT, $this->state()[0]);
    }

    public function testTheFollowUpForExtraServersIsNotSentToAPreviousOwner(): void
    {
        $this->refused = [];
        $this->buy();
        $service = $this->sold();
        self::assertSame(PaymentDeliveryLog::SENT, $this->state()[0]);
        $other = $this->createUser('8802');
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ? WHERE id = ?', [$other->getId(), $service->getId()]);
        $this->resetHttp();

        self::getContainer()->get(PaymentCustomerNotifier::class)->inboundsAttached($this->payment(), $service, 2);

        self::assertSame([], $this->http->requests('/sendMessage'), 'neither the payer nor the new owner is told about servers of a service he no longer / never bought');
    }

    private function resellerSaleWithFailedDelivery(): VpnService
    {
        $this->refused = [self::CUSTOMER];
        $this->buy();
        self::assertSame(PaymentDeliveryLog::PENDING, $this->state()[0], 'Telegram refused the delivery');
        self::assertSame(0, $this->deliveries(self::CUSTOMER));
        $this->refused = [];
        $this->resetHttp();
        $this->refuseTelegram();

        return $this->sold();
    }

    private function sold(): VpnService
    {
        $this->em->clear();

        return $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->reseller]) ?? self::fail('no service was sold');
    }

    private function payment(): Payment
    {
        $id = $this->em->getConnection()->fetchOne("SELECT p.id FROM payment p JOIN `order` o ON o.id = p.order_id WHERE o.type = 'new_service' AND p.status = ? ORDER BY p.id DESC LIMIT 1", [PaymentStatus::CONFIRMED]);

        return $this->em->find(Payment::class, (int) $id) ?? self::fail('no payment');
    }

    private function refuseTelegram(): void
    {
        $this->http->on('/sendMessage', function (string $m, string $u, array $options): MockResponse {
            $chat = (string) (json_decode((string) ($options['body'] ?? ''), true)['chat_id'] ?? '');

            return in_array($chat, $this->refused, true)
                ? new MockResponse('{"ok":false,"error_code":429,"description":"Too Many Requests: retry after 5"}', ['response_headers' => ['content-type' => 'application/json']])
                : new MockResponse('{"ok":true,"result":{"message_id":1}}', ['response_headers' => ['content-type' => 'application/json']]);
        });
    }

    /** @return array{due: int, resent: int} */
    private function job(): array
    {
        $this->em->clear();

        return self::getContainer()->get(MissingDeliveryResender::class)->run(50);
    }

    private function retrySettlement(): void
    {
        $this->em->clear();
        $outcome = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->payment(), 1, null, 'test')->outcome->value;
        self::assertSame('already_processed', $outcome);
    }

    /** Delivery messages Telegram accepted for this chat. */
    private function deliveries(string $chat): int
    {
        $count = 0;
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true) ?: [];
            if ($chat === (string) ($body['chat_id'] ?? '') && !in_array($chat, $this->refused, true) && str_starts_with((string) ($body['text'] ?? ''), self::DELIVERY)) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return array{0: string|null, 1: string|null} the delivery's state and abandon reason */
    private function state(): array
    {
        $row = $this->em->getConnection()->fetchAssociative("SELECT type, payload FROM service_notification_log WHERE type LIKE 'delivery\\_%' AND type <> ? ORDER BY id DESC LIMIT 1", [PaymentDeliveryLog::FOLLOW_UP]);
        if (false === $row) {
            return [null, null];
        }

        return [(string) $row['type'], json_decode((string) $row['payload'], true)['reason'] ?? null];
    }
}
