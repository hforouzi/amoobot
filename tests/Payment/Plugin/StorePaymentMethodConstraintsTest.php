<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\ConstrainedGatewayPlugin;
use Amoobot\Plugin\ConstrainedGateway\UnconstrainedGatewayPlugin;
use App\Bot\Application\StorePaymentMethodResolver;
use App\Entity\Order;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A plugin's constraints in StorePaymentMethodResolver: the method is hidden when the plugin says so, or cannot say.
 */
final class StorePaymentMethodConstraintsTest extends IntegrationTestCase
{
    use ConstrainedGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installConstrainedPlugin();
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    private function resolver(): StorePaymentMethodResolver
    {
        return self::getContainer()->get(StorePaymentMethodResolver::class);
    }

    private function logger(): RecordingLogger
    {
        return self::getContainer()->get(RecordingLogger::class);
    }

    private function order(int $amount): Order
    {
        return $this->createOrder($this->createUser(), $this->createPlan(150000), amount: $amount);
    }

    /**
     * @return list<string>
     */
    private function skipReasons(Order $order, StorePaymentMethod $method): array
    {
        foreach ($this->resolver()->getDiagnostics($order)['methods'] as $evaluation) {
            if ($evaluation['methodId'] === $method->getId()) {
                return $evaluation['skipReasons'];
            }
        }

        self::fail('The method was not evaluated.');
    }

    private static function offered(StorePaymentMethod $method, StorePaymentMethodResolver $resolver, Order $order): bool
    {
        return in_array($method, $resolver->getAvailableMethods($order), true);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function amounts(): iterable
    {
        yield 'one below the minimum' => [49999, false];
        yield 'the minimum' => [50000, true];
        yield 'the maximum' => [10000000, true];
        yield 'one above the maximum' => [10000001, false];
    }

    #[DataProvider('amounts')]
    public function testTheAmountRangeOfThePlugin(int $amount, bool $accepted): void
    {
        $method = $this->constrainedMethod();
        $order = $this->order($amount);

        self::assertSame($accepted, self::offered($method, $this->resolver(), $order));
        self::assertSame($accepted ? [] : ['amount_out_of_range'], $this->skipReasons($order, $method));
    }

    public function testTheMethodsOwnRangeStillAppliesOnTop(): void
    {
        $method = $this->constrainedMethod()->setMinAmount(100000)->setMaxAmount(200000);
        $this->em->flush();

        self::assertSame(['amount_out_of_range'], $this->skipReasons($this->order(99999), $method), 'inside the plugin range, below the method');
        self::assertSame(['amount_out_of_range'], $this->skipReasons($this->order(200001), $method));
        self::assertSame([], $this->skipReasons($this->order(150000), $method));
    }

    public function testBothRangesRefusingAddTheReasonOnce(): void
    {
        $method = $this->constrainedMethod()->setMinAmount(100000);
        $this->em->flush();

        self::assertSame(['amount_out_of_range'], $this->skipReasons($this->order(49999), $method));
    }

    public function testAnUnusablePluginHidesTheMethodEverywhere(): void
    {
        $method = $this->constrainedMethod(['usable' => false]);
        $order = $this->order(150000);

        self::assertSame(['gateway_not_usable'], $this->skipReasons($order, $method));
        self::assertFalse(self::offered($method, $this->resolver(), $order));
        self::assertNotContains($method, $this->resolver()->getOfferableMethods());
    }

    public function testAUsablePluginIsOfferableWhateverTheAmount(): void
    {
        $method = $this->constrainedMethod();

        self::assertContains($method, $this->resolver()->getOfferableMethods());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function throwingChecks(): iterable
    {
        yield 'isUsable' => ['isUsable'];
        yield 'acceptsAmount' => ['acceptsAmount'];
    }

    #[DataProvider('throwingChecks')]
    public function testAThrowingCheckSkipsTheMethodAndLogsNoSecret(string $check): void
    {
        $method = $this->constrainedMethod();
        $order = $this->order(150000);
        ConstrainedGatewayPlugin::$throwIn = $check;
        $this->logger()->records = [];

        self::assertSame(['gateway_not_usable'], $this->skipReasons($order, $method));
        self::assertFalse(self::offered($method, $this->resolver(), $order));

        $log = $this->logger()->text();
        self::assertStringContainsString('A payment plugin constraint check failed', $log);
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString(self::PLUGIN_CODE, $log);
        self::assertStringNotContainsString(self::API_KEY, $log);
    }

    public function testAThrowingUsabilityCheckIsNotOfferable(): void
    {
        $method = $this->constrainedMethod();
        ConstrainedGatewayPlugin::$throwIn = 'isUsable';

        self::assertNotContains($method, $this->resolver()->getOfferableMethods());
        self::assertStringNotContainsString(self::API_KEY, $this->logger()->text());
    }

    public function testAPluginThatCannotLoadSkipsTheMethodAndLogsNoSecret(): void
    {
        $method = $this->constrainedMethod();
        $order = $this->order(150000);
        ConstrainedGatewayPlugin::$throwIn = 'construct';
        $this->logger()->records = [];

        self::assertSame(['plugin_runtime_error'], $this->skipReasons($order, $method));
        self::assertNotContains($method, $this->resolver()->getOfferableMethods());

        $log = $this->logger()->text();
        self::assertStringContainsString('The payment plugin could not be loaded', $log);
        self::assertStringNotContainsString(self::API_KEY, $log);
    }

    public function testAPluginWithoutTheInterfaceIsOfferedForAnyAmount(): void
    {
        $this->installConstrainedPlugin(UnconstrainedGatewayPlugin::class);
        $method = $this->constrainedMethod(['usable' => false]);

        foreach ([1, 49999, 10000001] as $amount) {
            self::assertSame([], $this->skipReasons($this->order($amount), $method));
        }
        self::assertContains($method, $this->resolver()->getOfferableMethods());
    }

    public function testCoreGatewaysIgnoreTheConstraints(): void
    {
        $card = $this->createGateway(PaymentGatewayType::MANUAL_CARD, [], 'IRT')->setManualCardNumber('6037-0000-1111-2222')->setManualCardHolder('Shop Owner');
        $method = (new StorePaymentMethod())->setGateway($card)->setTitle('card')->setIsActive(true);
        $this->em->persist($method);
        $this->em->flush();

        self::assertSame([], $this->skipReasons($this->order(1000), $method));
        self::assertSame([], $this->skipReasons($this->order(20000000), $method));
        self::assertContains($method, $this->resolver()->getOfferableMethods());
    }
}
