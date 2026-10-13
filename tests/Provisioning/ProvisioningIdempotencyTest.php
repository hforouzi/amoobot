<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\VpnProvisioningService;
use App\Shop\Domain\OrderStatus;
use App\Payment\Domain\PaymentGatewayType;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A retried provisioning must adopt the client an earlier attempt already created on the panel,
 * never create a second one for the same order.
 */
final class ProvisioningIdempotencyTest extends IntegrationTestCase
{
    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private function generatedName(Order $order): string
    {
        $telegramId = $order->getUser()->getTelegramAccount()?->getTelegramId();

        return sprintf('tg_%s_order_%d', $telegramId, $order->getId());
    }

    /**
     * @return array{Order, Payment}
     */
    private function failedFirstAttempt(): array
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNextAfterCreate(); // panel creates the client, the response is lost

        $first = $this->settlement()->approveByAdmin($payment, 1, null, 'test');
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $first->outcome);
        self::assertCount(1, $this->driver()->clients, 'the client exists on the panel');
        self::assertSame(0, $this->countServicesForOrder($order), 'but not in the database');

        return [$order, $payment];
    }

    private function retry(Payment $payment): SettlementOutcome
    {
        $this->em->clear();
        $reloaded = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException('payment missing');

        return $this->settlement()->retryProvisioning($reloaded, 1, null, 'test')->outcome;
    }

    public function testRetryAdoptsTheClientTheFailedAttemptCreated(): void
    {
        [$order, $payment] = $this->failedFirstAttempt();
        $panelClient = array_values($this->driver()->clients)[0];

        self::assertSame(SettlementOutcome::CONFIRMED, $this->retry($payment));

        self::assertSame(1, $this->driver()->createCalls, 'no second client was created');
        self::assertCount(1, $this->driver()->clients);
        self::assertSame(1, $this->countServicesForOrder($order));
        $service = $this->em->getConnection()->fetchAssociative('SELECT * FROM vpn_service WHERE order_id = ?', [$order->getId()]);
        self::assertIsArray($service);
        self::assertSame($panelClient['uuid'], $service['client_uuid'], 'database row rebuilt from the panel client');
        self::assertSame($panelClient['subId'], $service['sub_id']);
        self::assertSame(40, (int) $service['traffic_limit_gb'], 'limits taken from the panel');
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testClientsGetTheOrderDerivedUuid(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $other = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $provisioning = self::getContainer()->get(VpnProvisioningService::class);

        $uuid = $provisioning->orderClientUuid($order);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        self::assertSame($uuid, $provisioning->orderClientUuid($order), 'deterministic');
        self::assertNotSame($uuid, $provisioning->orderClientUuid($other));

        $this->settlement()->approveByAdmin($this->createPayment($order, null, PaymentStatus::SUBMITTED), 1, null, 'test');
        self::assertSame($uuid, array_values($this->driver()->clients)[0]['uuid']);
        self::assertSame(1, $this->driver()->lookupCalls, 'every attempt looks the client up first');
    }

    public function testRepairStuckWithServiceMissingAdoptsTheExistingClient(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::CONFIRMED);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ?, provisioned_at = NOW() WHERE id = ?', [OrderStatus::PROVISIONED, $order->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE payment SET confirmed_at = ? WHERE id = ?', [(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $payment->getId()]);
        $this->em->clear();
        // Created by the old code (random UUID), database row lost.
        $name = $this->generatedName($order);
        $this->driver()->clients[$name] = ['uuid' => 'legacy-random-uuid', 'email' => $name, 'subId' => 'legacysub'];

        $tester = new CommandTester((new Application(self::$kernel))->find('app:payments:repair-stuck'));
        $tester->execute(['--apply' => true, '--include-service-missing' => true]);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $payment->getId())['status'], $tester->getDisplay());

        self::assertSame(SettlementOutcome::CONFIRMED, $this->retry($payment));
        self::assertSame(0, $this->driver()->createCalls);
        self::assertSame('legacy-random-uuid', $this->em->getConnection()->fetchOne('SELECT client_uuid FROM vpn_service WHERE order_id = ?', [$order->getId()]));
    }

    public function testCustomUsernameOwnedByAnotherClientIsNeverAdopted(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $order->setMetadata(['custom' => true, 'finalUsername' => 'ali']);
        $this->em->flush();
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext();
        $this->settlement()->approveByAdmin($payment, 1, null, 'test');
        // Someone else's client with the same name, different UUID.
        $this->driver()->clients['ali'] = ['uuid' => 'someone-elses-uuid', 'email' => 'ali', 'subId' => 'x'];

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $this->retry($payment));

        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertStringContainsString('does not belong to order', (string) $this->row('payment', (int) $payment->getId())['provisioning_error']);
    }

    public function testUnreadablePanelBlocksTheRetryInsteadOfRiskingADuplicate(): void
    {
        [$order, $payment] = $this->failedFirstAttempt();
        $this->driver()->lookupFails = true;

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $this->retry($payment));

        self::assertSame(1, $this->driver()->createCalls);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testRetryCreatesTheClientWhenThePanelDoesNotHaveIt(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext(); // fails before the panel did anything
        $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $this->retry($payment));

        self::assertSame(2, $this->driver()->lookupCalls, 'first attempt and retry');
        self::assertSame(2, $this->driver()->createCalls);
        self::assertCount(1, $this->driver()->clients);
        self::assertSame(1, $this->countServicesForOrder($order));
    }
    /**
     * A settlement process is killed after the panel created the client but before the database
     * commit. The next ordinary webhook/callback for the payment must adopt that client.
     */
    public function testKilledBetweenCreateServiceAndCommitThenTheNextWebhookAdopts(): void
    {
        $stateFile = sys_get_temp_dir().'/amoobot-test-panel-'.bin2hex(random_bytes(4)).'.json';
        $this->driver()->stateFile = $stateFile;
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('931')->setAuthority('931');
        $this->em->flush();
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());

        try {
            $php = (new PhpExecutableFinder())->find(false) ?: 'php';
            $settle = new Process([$php, 'tests/Support/bin/settle-payment.php', (string) $payment->getId(), '150000'], dirname(__DIR__, 2), [
                'APP_ENV' => 'test',
                'TEST_PANEL_STATE_FILE' => $stateFile,
                'TEST_PANEL_SLEEP_AFTER_CREATE_MS' => '30000',
            ], null, 60);
            $settle->start();
            $deadline = microtime(true) + 30;
            while (!is_file($stateFile) && microtime(true) < $deadline && $settle->isRunning()) {
                usleep(100_000);
            }
            self::assertFileExists($stateFile, 'the panel client was created: '.$settle->getErrorOutput().$settle->getOutput());
            $settle->stop(0); // hard kill: no commit, no cleanup
            self::assertFalse($settle->isSuccessful());

            self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status'], 'rolled back');
            self::assertSame(0, $this->countServicesForOrder($order));
            $panelClient = array_values(json_decode((string) file_get_contents($stateFile), true))[0];

            // An ordinary gateway callback for the same payment.
            $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);
            $client = new KernelBrowser(self::$kernel);
            $client->disableReboot();
            $client->request('GET', '/payment/callback/zibal', ['trackId' => '931', 'success' => '1', 'status' => '2']);

            self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
            self::assertSame(0, $this->driver()->createCalls, 'adopted, no second client');
            self::assertCount(1, json_decode((string) file_get_contents($stateFile), true));
            self::assertSame(1, $this->countServicesForOrder($order));
            self::assertSame($panelClient['uuid'], $this->em->getConnection()->fetchOne('SELECT client_uuid FROM vpn_service WHERE order_id = ?', [$order->getId()]));
        } finally {
            @unlink($stateFile);
        }
    }
}
