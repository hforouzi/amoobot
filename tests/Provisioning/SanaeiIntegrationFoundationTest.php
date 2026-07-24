<?php

declare(strict_types=1);

use App\Entity\Order;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceTrafficAddonService;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\UnsupportedPanelOperationException;
use App\Provisioning\Infrastructure\DummyVpnPanelDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Legacy\Sanaei3xuiLegacyDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

final class SanaeiIntegrationFoundationLegacySpy extends Sanaei3xuiLegacyDriver
{
    public int $addTrafficCalls = 0;
    public int $renewCalls = 0;

    public function __construct()
    {
    }

    public function addTrafficLimit(string $remoteId, int $trafficLimitGb, ?VpnPanel $panel = null): void
    {
        ++$this->addTrafficCalls;
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        ++$this->renewCalls;
    }
}

final class SanaeiIntegrationFoundationModernSpy extends Sanaei3xuiModernDriver
{
    public int $renewCalls = 0;

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        ++$this->renewCalls;
        parent::renewService($remoteId, $request, $panel);
    }
}

$legacyDriver = new SanaeiIntegrationFoundationLegacySpy();
$modernDriver = new SanaeiIntegrationFoundationModernSpy();
$registry = new VpnPanelDriverRegistry([
    new DummyVpnPanelDriver(),
    $modernDriver,
    $legacyDriver,
]);

$legacyByDefault = (new VpnPanel())->setType('sanaei_3xui');
assertSame(SanaeiIntegrationMode::LEGACY, $legacyByDefault->getSanaeiIntegrationMode(), 'Missing Sanaei integration mode must read as legacy.');
assertInstanceOf(Sanaei3xuiLegacyDriver::class, $registry->resolve($legacyByDefault), 'Existing Sanaei panels must resolve to legacy.');

$explicitLegacy = (new VpnPanel())
    ->setType('sanaei_3xui')
    ->setSanaeiIntegrationMode(SanaeiIntegrationMode::LEGACY);
assertInstanceOf(Sanaei3xuiLegacyDriver::class, $registry->resolve($explicitLegacy), 'Explicit legacy must resolve to legacy.');

$modern = (new VpnPanel())
    ->setType('sanaei_3xui')
    ->setSanaeiIntegrationMode(SanaeiIntegrationMode::MODERN);
assertInstanceOf(Sanaei3xuiModernDriver::class, $registry->resolve($modern), 'Explicit modern must resolve to modern.');
assertFalse($legacyDriver->supports($modern), 'Legacy driver must not accidentally select modern panels.');

$dummy = (new VpnPanel())->setType('dummy');
assertInstanceOf(DummyVpnPanelDriver::class, $registry->resolve($dummy), 'Dummy driver resolution must be unchanged.');

$jsonFallbackPanel = (new VpnPanel())
    ->setType('sanaei_3xui')
    ->setConfig(['sanaei_integration_mode' => 'modern']);
assertSame(SanaeiIntegrationMode::LEGACY, $jsonFallbackPanel->getSanaeiIntegrationMode(), 'Config JSON must not be used as an integration-mode fallback source.');
assertInstanceOf(Sanaei3xuiLegacyDriver::class, $registry->resolve($jsonFallbackPanel), 'JSON mode key must not select modern.');

$roundTrip = (new VpnPanel())
    ->setType('sanaei_3xui')
    ->setSanaeiIntegrationMode(SanaeiIntegrationMode::MODERN);
assertSame(SanaeiIntegrationMode::MODERN, $roundTrip->getSanaeiIntegrationMode(), 'Integration mode enum must round-trip through the entity property.');
$property = new ReflectionProperty(VpnPanel::class, 'sanaeiIntegrationMode');
$column = $property->getAttributes(Doctrine\ORM\Mapping\Column::class)[0] ?? null;
assertTrue(null !== $column, 'Sanaei integration mode must be a mapped Doctrine column.');
assertSame(SanaeiIntegrationMode::class, $column->getArguments()['enumType'] ?? null, 'Sanaei integration mode column must use enum mapping.');
assertSame(true, $column->getArguments()['nullable'] ?? null, 'Sanaei integration mode column must be nullable for historical rows.');

$trafficAddonService = new ServiceTrafficAddonService($registry);
$legacyService = serviceForPanel($legacyByDefault);
$trafficAddonService->addTraffic($legacyService, 5, new Order());
assertSame(1, $legacyDriver->addTrafficCalls, 'Legacy dispatch must call Sanaei3xuiLegacyDriver through the orchestration service.');
assertSame(0, $modernDriver->renewCalls, 'Legacy dispatch must not call Sanaei3xuiModernDriver.');

$modernService = serviceForPanel($modern);
assertThrows(
    UnsupportedPanelOperationException::class,
    static fn () => $trafficAddonService->addTraffic($modernService, 5, new Order()),
    'Modern dispatch must raise the expected unsupported operation exception.'
);
assertSame(1, $modernDriver->renewCalls, 'Modern dispatch must call Sanaei3xuiModernDriver through the orchestration service.');
assertSame(1, $legacyDriver->addTrafficCalls, 'Modern dispatch must not fall back to Sanaei3xuiLegacyDriver.');

echo "Sanaei integration foundation tests passed.\n";

function serviceForPanel(VpnPanel $panel): VpnService
{
    return (new VpnService())
        ->setPanel($panel)
        ->setRemoteId('remote-id')
        ->setTrafficLimitGb(10);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.describe($expected).', got '.describe($actual).'.');
    }
}

function assertTrue(bool $actual, string $message): void
{
    assertSame(true, $actual, $message);
}

function assertFalse(bool $actual, string $message): void
{
    assertSame(false, $actual, $message);
}

function assertInstanceOf(string $expectedClass, object $actual, string $message): void
{
    if (!$actual instanceof $expectedClass) {
        throw new RuntimeException($message.' Expected '.$expectedClass.', got '.$actual::class.'.');
    }
}

/**
 * @param callable(): mixed $callback
 */
function assertThrows(string $expectedClass, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException($message.' Expected '.$expectedClass.', got '.$throwable::class.'.');
    }

    throw new RuntimeException($message.' Expected '.$expectedClass.' but nothing was thrown.');
}

function describe(mixed $value): string
{
    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}
