<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\OrderDraft;
use App\Payment\Application\PaymentDeliveryLog;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderDraftStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * M2e E1 review R3 (d): the "orders" job runs each of its tasks on its own, with an open entity manager. A failing
 * expire_incomplete_orders (here its flush fails, which also closes the entity manager) never skips the resend of a
 * delivery message that is due, and that resend is sent once.
 */
final class DeliveryOrdersJobTest extends IntegrationTestCase
{
    private const DELIVERY = '✅ سرویس شما آماده شد';
    private const CUSTOMER = '5550002';

    private bool $telegramDown = true;

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TRIGGER IF EXISTS test_order_draft_update_fails');
        parent::tearDown();
    }

    public function testAFailingExpiryTaskNeitherSkipsTheResendNorLeavesItAClosedEntityManager(): void
    {
        $this->http->on('/sendMessage', fn (): MockResponse => $this->telegramDown
            ? new MockResponse('{"ok":false,"error_code":429,"description":"Too Many Requests: retry after 5"}', ['response_headers' => ['content-type' => 'application/json']])
            : new MockResponse('{"ok":true,"result":{"message_id":1}}', ['response_headers' => ['content-type' => 'application/json']]));
        $user = $this->createUser(self::CUSTOMER);
        $plan = $this->createPlan(150000, $this->createTestInbound());
        $payment = $this->createPayment($this->createOrder($user, $plan), null, PaymentStatus::SUBMITTED);
        self::assertSame('confirmed', self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome->value);
        self::assertSame(PaymentDeliveryLog::PENDING, $this->state());
        $this->telegramDown = false;
        $this->http->reset();

        // A draft the expiry task will expire, whose UPDATE fails: its flush throws and closes the entity manager.
        $draft = (new OrderDraft())->setUser($user)->setPlan($plan)->setStatus(OrderDraftStatus::PENDING)->setStep('traffic');
        $this->em->persist($draft);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE order_draft SET created_at = DATE_SUB(NOW(), INTERVAL 30 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?', [$draft->getId()]);
        $this->em->getConnection()->executeStatement("CREATE TRIGGER test_order_draft_update_fails BEFORE UPDATE ON order_draft FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated'");
        $this->em->clear();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $tester->execute(['--only' => 'orders']);

        self::assertSame(1, $this->deliveries(), 'the resend task ran after the failed expiry task');
        self::assertSame(PaymentDeliveryLog::SENT, $this->state());
        self::assertStringContainsString('failed', strtolower($tester->getDisplay()));
    }

    private function deliveries(): int
    {
        $count = 0;
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true) ?: [];
            $count += self::CUSTOMER === (string) ($body['chat_id'] ?? '') && str_starts_with((string) ($body['text'] ?? ''), self::DELIVERY) ? 1 : 0;
        }

        return $count;
    }

    private function state(): ?string
    {
        $type = $this->em->getConnection()->fetchOne("SELECT type FROM service_notification_log WHERE type LIKE 'delivery\\_%' AND type <> ?", [PaymentDeliveryLog::FOLLOW_UP]);

        return false === $type ? null : (string) $type;
    }
}
