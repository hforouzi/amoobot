<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\VpnPanel;
use App\Module\Domain\CapabilityNotSupported;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\ExistingClientAwareDriverInterface;
use App\Provisioning\Domain\ServiceActivationDriverInterface;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;
use PHPUnit\Framework\TestCase;

/**
 * The shared VPN driver contract (docs/design/product-modules.md 8.2), first part (PM1a): only what today's drivers
 * can be held to. A driver's test extends this and provides a backend (Fake3xuiPanel, or none for dummy).
 *
 * Not here yet, because no driver can pass it today: a typed RemoteNotFound for an unknown user, lost-response
 * adoption, health-check classification, the panel_sub_links snapshot rules and the no-secret-leak checks.
 */
abstract class VpnDriverContractTestCase extends TestCase
{
    abstract protected function driver(): VpnDriverInterface;

    /** The connection the driver works on (PM1a: the VpnPanel; null for a driver that needs none). */
    abstract protected function panel(): ?VpnPanel;

    /** A create request for one order; the same $order gives the same request. */
    abstract protected function createRequest(int $order = 42): CreateVpnServiceRequest;

    /**
     * The driver's capabilities as the design's 6.3 table states them for today's code, written out by hand: the
     * independent check on what the driver derives.
     *
     * @return list<string>
     */
    abstract protected function expectedCapabilities(): array;

    /** Whether getUsage() reflects what the driver wrote (false for a driver without a backend). */
    protected function readsBackState(): bool
    {
        return true;
    }

    public function testIdentity(): void
    {
        $driver = $this->driver();

        self::assertSame('vpn', $driver->module());
        self::assertSame(VpnDriverInterface::VERSION, $driver->contractVersion());
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $driver->code());
        self::assertTrue($driver->supports($this->panel()), 'selected for its own connection');
        self::assertTrue($driver->supportsProvisioning($this->panel()));
    }

    public function testCapabilitiesAreTheTableAndTheImplementedInterfaces(): void
    {
        $driver = $this->driver();
        $declared = $driver->capabilities()->names();
        $expected = $this->expectedCapabilities();
        sort($expected);

        self::assertSame($expected, $declared, 'as section 6.3 states for this driver');
        self::assertSame(VpnCapability::derive($driver)->names(), $declared, 'exactly its instanceof set');
        foreach ($declared as $capability) {
            self::assertContains($capability, VpnCapability::ALL);
            self::assertInstanceOf(VpnCapability::INTERFACES[$capability], $driver);
        }
    }

    public function testAnUndeclaredCapabilityThrowsCapabilityNotSupported(): void
    {
        $driver = $this->driver();
        $undeclared = array_values(array_diff(VpnCapability::ALL, $driver->capabilities()->names()));

        foreach ($undeclared as $capability) {
            try {
                VpnCapability::require($driver, $capability);
                self::fail($capability.' is not declared, so its use must throw');
            } catch (CapabilityNotSupported $e) {
                self::assertSame($capability, $e->capability);
                self::assertSame($driver->code(), $e->driverCode);
            }
        }
        foreach ($driver->capabilities()->names() as $capability) {
            VpnCapability::require($driver, $capability);
        }
        $this->addToAssertionCount(1);
    }

    public function testCreateReturnsARemoteIdAndSomethingToConnectWith(): void
    {
        $created = $this->create();

        self::assertNotSame('', trim($created->remoteId));
        self::assertTrue('' !== trim((string) $created->subscriptionUrl) || [] !== $created->configLinks, 'a subscription URL or links');
    }

    public function testCreatingAgainForTheSameOrderAdoptsTheFirstUser(): void
    {
        $driver = $this->driver();
        if (!$driver->capabilities()->has(VpnCapability::ADOPT_EXISTING)) {
            self::markTestSkipped('No adopt_existing: the driver cannot find the order\'s user again.');
        }
        self::assertInstanceOf(ExistingClientAwareDriverInterface::class, $driver);
        $created = $this->create();

        $adopted = $driver->findExistingService($this->createRequest(), $this->panel());

        self::assertNotNull($adopted, 'the retry finds the first user instead of creating a second one');
        self::assertSame($created->clientUuid, $adopted->service->clientUuid);
        self::assertNull($driver->findExistingService($this->createRequest(43), $this->panel()), 'another order finds nothing');
    }

    public function testRenewSetsTheLimitAndTheExpiryExactly(): void
    {
        $created = $this->create();
        $expires = new \DateTimeImmutable('2031-02-03 04:05:06 UTC');

        $this->driver()->renewService($created->remoteId, new RenewVpnServiceRequest(durationDays: 30, trafficLimitGb: 80, expiresAt: $expires), $this->panel());

        if (!$this->readsBackState()) {
            $this->addToAssertionCount(1);

            return;
        }
        $usage = $this->driver()->getUsage($created->remoteId, $this->panel());
        self::assertSame(80, $usage->trafficLimitGb);
        self::assertSame($expires->getTimestamp(), $usage->expiresAt?->getTimestamp(), 'timestamps round-trip');
    }

    public function testSuspendThenResumeEnablesTheUserAgain(): void
    {
        $driver = $this->driver();
        $created = $this->create();

        $driver->suspendService($created->remoteId, $this->panel());
        if ($this->readsBackState()) {
            self::assertFalse($driver->getUsage($created->remoteId, $this->panel())->isEnabled);
        }
        if ($driver->capabilities()->has(VpnCapability::RESUME)) {
            self::assertInstanceOf(ServiceActivationDriverInterface::class, $driver);
            $driver->activateService($created->remoteId, $this->panel());
        } else {
            // Without resume, today's fallback (ServiceAdministration::activate()): renew with 0 days.
            $driver->renewService($created->remoteId, new RenewVpnServiceRequest(durationDays: 0, trafficLimitGb: 50), $this->panel());
        }

        if ($this->readsBackState()) {
            self::assertTrue($driver->getUsage($created->remoteId, $this->panel())->isEnabled);
        } else {
            $this->addToAssertionCount(1);
        }
    }

    public function testResetUsageZeroesTheCounter(): void
    {
        $created = $this->create();

        $this->driver()->resetUsage($created->remoteId, $this->panel());

        self::assertSame(0, $this->driver()->getUsage($created->remoteId, $this->panel())->usedBytes);
    }

    public function testDeletingTwiceSucceeds(): void
    {
        $created = $this->create();

        $this->driver()->deleteService($created->remoteId, $this->panel());
        $this->driver()->deleteService($created->remoteId, $this->panel());

        $this->addToAssertionCount(1);
    }

    protected function create(int $order = 42): CreatedVpnService
    {
        return $this->driver()->createService($this->createRequest($order), $this->panel());
    }
}
