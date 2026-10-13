<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use Amoobot\Plugin\DemoPaymentGateway\DemoPaymentGatewayPlugin;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Webhook\GatewayNotificationProcessor;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginAutoloader;
use App\Plugin\PluginRegistry;
use App\Tests\Support\IntegrationTestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * The demo plugin trusts ?demo_paid=true. In production it must not report anything as paid (so its
 * public callback cannot create NEEDS_REVIEW payments / admin alerts), auto_confirm_for_testing is
 * ignored, and a disabled plugin or inactive gateway is never reached at all.
 */
final class DemoPluginProductionGuardTest extends IntegrationTestCase
{
    private const CODE = 'demo_payment_gateway';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        parent::tearDown();
    }

    /**
     * The sample plugin from docs/plugins, registered as installed.
     */
    private function installDemoPlugin(string $status = Plugin::STATUS_ENABLED): void
    {
        $path = dirname(__DIR__, 2).'/docs/plugins/demo-payment-gateway';
        $manifest = json_decode((string) file_get_contents($path.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $plugin = (new Plugin())
            ->setCode(self::CODE)
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'Demo'])
            ->setVersion((string) ($manifest['version'] ?? '1.0.0'))
            ->setPath($path)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus($status);
        $this->em->persist($plugin);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function demoPayment(array $config = [], bool $gatewayActive = true): Payment
    {
        $gateway = $this->createGateway('plugin', $config)->setPluginCode(self::CODE)->setIsActive($gatewayActive);
        $this->em->flush();
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound())), $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('demo_'.$payment->getId())->setAuthority('demo_'.$payment->getId());
        $this->em->flush();

        return $payment;
    }

    private function forgedCallback(Payment $payment): void
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->request('GET', '/payment/callback/plugin/'.self::CODE, ['transaction_id' => 'demo_'.$payment->getId(), 'demo_paid' => 'true']);
    }

    private function assertNothingHappened(Payment $payment): void
    {
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame([], $this->http->requests('/sendMessage'), 'no admin alert, no customer message');
    }

    public function testOnlyTestOrDebugKernelsAllowTestingShortcuts(): void
    {
        self::assertFalse(PaymentGatewayRegistry::testingShortcutsAllowed('prod', false));
        self::assertTrue(PaymentGatewayRegistry::testingShortcutsAllowed('prod', true));
        self::assertTrue(PaymentGatewayRegistry::testingShortcutsAllowed('test', false));
        self::assertTrue(PaymentGatewayRegistry::testingShortcutsAllowed('dev', false));
    }

    public function testInProductionTheDemoNeverReportsPaidAndAutoConfirmIsIgnored(): void
    {
        require_once dirname(__DIR__, 2).'/docs/plugins/demo-payment-gateway/src/DemoPaymentGatewayPlugin.php';
        $payment = $this->demoPayment(['auto_confirm_for_testing' => true]);
        $production = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode(self::CODE), new DemoPaymentGatewayPlugin());

        $verification = $production->verifyPayment($payment, ['demo_paid' => 'true']);

        self::assertFalse($verification->paid);
        self::assertNull($verification->amount);
    }

    public function testProductionCallbackOfAnEnabledDemoCreatesNoReviewAndNoAlert(): void
    {
        $this->installDemoPlugin();
        $payment = $this->demoPayment(['auto_confirm_for_testing' => true]);
        $container = self::getContainer();
        $productionRegistry = new PaymentGatewayRegistry([], $container->get(PluginRegistry::class), $container->get(PluginAutoloader::class), $container->get(PaymentPluginDoctor::class), new NullLogger(), 'prod', false);
        $processor = new GatewayNotificationProcessor($this->em, $productionRegistry, $container->get(PaymentSettlementService::class), new NullLogger());
        $gateway = $payment->getGateway() ?? throw new \LogicException();

        $outcome = $processor->process([$gateway], Request::create('/payment/callback/plugin/'.self::CODE, 'GET', ['transaction_id' => 'demo_'.$payment->getId(), 'demo_paid' => 'true']), NotificationAwareGatewayInterface::CHANNEL_CALLBACK, 'test');

        self::assertSame('pending', $outcome->code);
        $this->assertNothingHappened($payment);
    }

    public function testInTheTestEnvironmentTheDemoStillWorks(): void
    {
        $this->installDemoPlugin();
        $payment = $this->demoPayment();

        $this->forgedCallback($payment);

        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status'], 'test env: demo_paid is honoured (no amount -> review)');
    }

    public function testCallbackOfADisabledPluginDoesNothing(): void
    {
        $this->installDemoPlugin(Plugin::STATUS_DISABLED);
        $payment = $this->demoPayment();

        $this->forgedCallback($payment);

        $this->assertNothingHappened($payment);
    }

    public function testCallbackOfAnInactiveGatewayDoesNothing(): void
    {
        $this->installDemoPlugin();
        $payment = $this->demoPayment([], gatewayActive: false);

        $this->forgedCallback($payment);

        $this->assertNothingHappened($payment);
    }

    public function testCallbackOfAnUninstalledPluginDoesNothing(): void
    {
        $payment = $this->demoPayment();

        $this->forgedCallback($payment);

        $this->assertNothingHappened($payment);
        self::assertInstanceOf(PaymentGateway::class, $payment->getGateway());
    }
}
