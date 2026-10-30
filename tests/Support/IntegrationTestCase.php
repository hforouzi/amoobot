<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests that run against the real test database (amoobot_test).
 *
 * Data is committed (no wrapping transaction) because settlement tests need row locks and a
 * second connection to observe them; tables touched by the payment flow are truncated before each test.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    private const PURGED_TABLES = [
        // A trial plan left by one test points at an inbound the next purge deletes; the admin pages listing trial
        // plans then fail depending on test order.
        'trial_claim',
        'trial_plan',
        'reseller_plan_price',
        'wallet_transaction',
        'wallet',
        'discount_usage',
        'discount_code',
        'service_notification_log',
        // A reseller's customer labels (S3) point at reseller and vpn_service.
        'reseller_customer_service',
        'reseller_customer',
        // Plans with several inbounds (M1) point at plan, vpn_service and vpn_inbound (keys are off: nothing cascades).
        'plan_additional_inbound',
        'vpn_service_inbound',
        // Claim links (S2) point at vpn_service and user.
        'service_ownership_event',
        'service_claim',
        'vpn_service',
        'payment',
        '`order`',
        'order_draft',
        'store_payment_method',
        'payment_gateway',
        'plan',
        'telegram_account',
        'bot_message_log',
        '`user`',
        'vpn_inbound',
        'vpn_panel',
        // After order / vpn_service / plan / user (they point at it); keys are off during the purge anyway.
        'reseller',
    ];

    protected EntityManagerInterface $em;
    protected MockHttpResponder $http;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->http = self::getContainer()->get(MockHttpResponder::class);
        $this->http->reset();
        $this->driver()->reset();
        $this->purge();
    }

    protected function tearDown(): void
    {
        // Never leave the wallet (or another switch) on for a test that does not purge.
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%' OR key_name LIKE 'reseller.%' OR key_name LIKE 'plans.%' OR key_name LIKE 'brand.%'");
        }
        parent::tearDown();
    }

    protected function purge(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::PURGED_TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        // The wallet, resellers, multi-inbound plans and branding are off unless a test switches them on (settings are not purged otherwise).
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%' OR key_name LIKE 'reseller.%' OR key_name LIKE 'plans.%' OR key_name LIKE 'brand.%'");
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em->clear();
    }

    protected function createUser(?string $telegramId = null): User
    {
        $telegramId ??= (string) random_int(100000, 999999999);
        $user = (new User())->setName('Test User '.$telegramId);
        $account = (new TelegramAccount())->setUser($user)->setTelegramId($telegramId);
        $user->setTelegramAccount($account);
        $this->em->persist($user);
        $this->em->persist($account);
        $this->em->flush();

        return $user;
    }

    protected function createPlan(int $price = 100000, ?VpnInbound $inbound = null): Plan
    {
        $plan = (new Plan())
            ->setTitle('Test plan')
            ->setDurationDays(30)
            ->setTrafficGb(50)
            ->setPrice($price)
            ->setInbound($inbound);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function createGateway(string $type = PaymentGatewayType::MANUAL_CARD, array $config = [], string $currency = 'IRR'): PaymentGateway
    {
        $gateway = (new PaymentGateway())
            ->setType($type)
            ->setTitle($type)
            ->setCurrency($currency)
            ->setConfig($config)
            ->setIsActive(true);
        $this->em->persist($gateway);
        $this->em->flush();

        return $gateway;
    }

    protected function createOrder(User $user, Plan $plan, string $type = OrderType::NEW_SERVICE, ?int $amount = null, ?VpnService $target = null): Order
    {
        $order = (new Order())
            ->setUser($user)
            ->setPlan($plan)
            ->setType($type)
            ->setAmount($amount ?? $plan->getPrice())
            ->setStatus(OrderStatus::WAITING_PAYMENT)
            ->setTargetService($target);
        if (OrderType::ADD_TRAFFIC === $type) {
            $order->setMetadata(['trafficGb' => 10]);
        }
        if (OrderType::RENEWAL === $type) {
            $order->setMetadata(['trafficGb' => 50, 'durationDays' => 30]);
        }
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    protected function createPayment(Order $order, ?PaymentGateway $gateway = null, string $status = PaymentStatus::SUBMITTED): Payment
    {
        $gateway ??= $this->createGateway();
        $payment = (new Payment())
            ->setOrder($order)
            ->setGateway($gateway)
            ->setMethod($gateway->getType())
            ->setAmount($order->getAmount())
            ->setPayableAmount($order->getAmount())
            ->setStatus($status);
        $this->em->persist($payment);
        $this->em->flush();

        return $payment;
    }

    /**
     * Panel + inbound served by ProgrammableVpnPanelDriver (type "test").
     */
    protected function createTestInbound(): VpnInbound
    {
        $panel = (new VpnPanel())->setType(ProgrammableVpnPanelDriver::TYPE)->setTitle('Test panel');
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('Test inbound');
        $this->em->persist($panel);
        $this->em->persist($inbound);
        $this->em->flush();

        return $inbound;
    }

    protected function driver(): ProgrammableVpnPanelDriver
    {
        return self::getContainer()->get(ProgrammableVpnPanelDriver::class);
    }

    protected function createActiveService(User $user, ?VpnInbound $inbound = null): VpnService
    {
        $service = (new VpnService())
            ->setUser($user)
            ->setPanel($inbound?->getPanel())
            ->setInbound($inbound)
            ->setRemoteId('dummy_existing')
            ->setUsername('existing_user')
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setStartsAt(new \DateTimeImmutable('-10 days'))
            ->setExpiresAt(new \DateTimeImmutable('+20 days'))
            ->setTrafficLimitGb(50)
            ->setTrafficUsedGb(0)
            ->setTrafficLimitBytes(50 * 1073741824)
            ->setTrafficUsedBytes(0);
        $this->em->persist($service);
        $this->em->flush();

        return $service;
    }

    protected function countServicesForOrder(Order $order): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE order_id = ?', [$order->getId()]);
    }

    /**
     * Re-reads a row straight from the database, bypassing the identity map.
     *
     * @return array<string, mixed>
     */
    protected function row(string $table, int $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$id]);
        self::assertIsArray($row, sprintf('Row %s#%d not found', $table, $id));

        return $row;
    }
}
