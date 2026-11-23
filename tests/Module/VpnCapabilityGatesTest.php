<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\ServiceClaim;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Module\Application\VpnPanelCapabilities;
use App\Module\Domain\CapabilitySet;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\InboundAttachRetry;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Provisioning\Application\PanelLinkRefresh;
use App\Provisioning\Application\PanelLinkSnapshot;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Application\ServiceTrafficAddonService;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Reseller\Application\ClaimPanelSync;
use App\Shop\Application\Catalog\TrialBackingPlans;
use App\Shop\Application\Checkout\PlanSaleRule;
use App\Shop\Application\MultiInboundSettings;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Tests\Support\CapabilityLimitedVpnDriver;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Real application gates on a Fake3xui backend; all persistence is mocked, so no database is required. */
final class VpnCapabilityGatesTest extends KernelTestCase
{
    use Fake3xuiWiring;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private Connection&\PHPUnit\Framework\MockObject\MockObject $db;
    private array $rows = [];
    private VpnPanel $panel;
    private VpnInbound $primary;
    private VpnInbound $additional;
    private VpnService $service;
    private CapabilityLimitedVpnDriver $driver;
    private VpnPanelDriverRegistry $drivers;
    private VpnPanelCapabilities $capabilities;
    private MultiInboundSettings $settings;
    private CreateVpnServiceRequest $request;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        self::id($this->panel, 1);
        $this->primary = $this->fakeInbound($this->panel, 1)->setIsActive(true);
        $this->additional = $this->fakeInbound($this->panel, 2)->setIsActive(true);
        self::id($this->primary, 1);
        self::id($this->additional, 2);
        $this->request = new CreateVpnServiceRequest(username: 'pm3a_client', durationDays: 30, trafficLimitGb: 50,
            inbound: $this->primary, remoteInboundId: '1', clientUuid: '7f3c9a10-5b2e-4c8d-9e1f-000000000042');
        $created = $this->modernDriver()->createService($this->request, $this->panel);
        $this->service = (new VpnService())->setPanel($this->panel)->setInbound($this->primary)
            ->setRemoteId($created->remoteId)->setClientUuid($created->clientUuid)->setClientEmail($created->clientEmail)
            ->setSubId($created->subId)->setUsername($created->username)->setStatus(VpnServiceStatus::ACTIVE)
            ->setTrafficLimitGb(50)->setUser(new User());
        self::id($this->service, 1);
        $this->db = $this->createMock(Connection::class);
        $this->db->method('fetchAllAssociative')->willReturnCallback(fn () => $this->rows);
        $this->db->method('fetchFirstColumn')->willReturn([]);
        $this->db->method('fetchOne')->willReturn(false);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->db);
        $this->em->method('find')->willReturnCallback(fn (string $class) => match ($class) {
            VpnPanel::class => $this->panel, VpnService::class => $this->service, default => null,
        });
        $values = $this->createMock(SettingValueProvider::class);
        $values->method('getBool')->willReturn(true);
        $this->settings = new MultiInboundSettings($values);
    }

    public static function declared(): iterable
    {
        yield 'undeclared, although implemented' => [false];
        yield 'declared' => [true];
    }

    private function select(string $capability, bool $declared): void
    {
        $names = $this->modernDriver()->capabilities()->names();
        $this->driver = new CapabilityLimitedVpnDriver($this->modernDriver(), CapabilitySet::of(...array_values(
            $declared ? $names : array_diff($names, [$capability])
        )));
        $this->drivers = new VpnPanelDriverRegistry([$this->driver]);
        $this->capabilities = new VpnPanelCapabilities($this->drivers);
    }

    /** Constructor dependencies come from the real container; persistence and panel calls are replaced explicitly. */
    private function build(string $class, array $overrides = []): object
    {
        $defaults = ['entityManager' => $this->em, 'drivers' => $this->drivers, 'driverRegistry' => $this->drivers,
            'capabilities' => $this->capabilities, 'logger' => new NullLogger(), 'settings' => $this->settings,
            'multiInbound' => $this->settings, 'api' => new ModernClientsApi($this->modernHttp()),
            'trialBacking' => new TrialBackingPlans($this->em), 'trialBackingPlans' => new TrialBackingPlans($this->em),
            'panelLinkSnapshot' => $this->snapshot(), 'credentials' => $this->credentials(), 'appSecret' => 'pm3a-test'];
        $reflection = new \ReflectionClass($class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                throw new \LogicException('The fixture builder requires named constructor dependencies.');
            }
            $args[] = $overrides[$parameter->name] ?? $defaults[$parameter->name]
                ?? ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : self::getContainer()->get($type->getName()));
        }
        return $reflection->newInstanceArgs($args);
    }

    private function snapshot(): PanelLinkSnapshot
    {
        return new PanelLinkSnapshot($this->drivers, new NullLogger(), $this->em, self::getContainer()->get(\App\Shared\Infrastructure\TransactionRollback::class));
    }

    private static function id(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }

    #[DataProvider('declared')]
    public function testSaleGateAtCatalogOrderAndPayment(bool $declared): void
    {
        $this->select(VpnCapability::MULTI_INBOUND, $declared);
        // A different panel type with exactly the same declarations gets the same answer.
        $this->panel->setType('capability_fake');
        $plan = (new Plan())->setTitle('PM3a')->setIsActive(true)->setInbound($this->primary)->setAdditionalInbounds([$this->additional]);
        $rule = $this->build(PlanSaleRule::class);
        self::assertSame($declared, $rule->isSellable($plan));
        self::assertSame($declared, $rule->acceptsOrders($plan));
        self::assertSame($declared ? [$plan] : [], $rule->sellable([$plan]));
        self::assertSame($declared, $rule->acceptsPayment((new Order())->setPlan($plan)));
    }

    #[DataProvider('declared')]
    public function testPanelLinkReadAndPendingDisplay(bool $declared): void
    {
        $this->select(VpnCapability::PANEL_SUB_LINKS, $declared);
        $snapshot = $this->snapshot();
        self::assertSame($declared ? $this->driver : null, $snapshot->driverFor($this->service));
        $result = $snapshot->refresh($this->service, 'pm3a', false);
        self::assertSame($declared, in_array('panelLinks', $this->driver->calls, true));
        self::assertSame($declared, $result->succeeded());
        if (!$declared) self::assertSame(PanelLinkRefresh::NO_IDENTITY, $result->outcome);
        $this->service->setInboundChangedAt(new \DateTimeImmutable());
        self::assertSame($declared, $this->build(FinalConfigLinkProvider::class)->linksPending($this->service));
    }

    #[DataProvider('declared')]
    public function testManualAttachIsUnavailableWithoutDeclaration(bool $declared): void
    {
        $this->select(VpnCapability::MULTI_INBOUND, $declared);
        $result = $this->build(ServiceInboundAttacher::class)->retry($this->service, 'pm3a');
        self::assertSame($declared ? InboundAttachRetry::NOTHING_TO_DO : InboundAttachRetry::NOT_MODERN, $result->outcome);
        self::assertSame([], $this->driver->calls, 'No failed rows to attach; an unsupported driver is refused before even claiming rows.');
    }

    #[DataProvider('declared')]
    public function testProvisioningRecordsUnattachableInbounds(bool $declared): void
    {
        $this->select(VpnCapability::MULTI_INBOUND, $declared);
        [$attachable, $unsupported] = self::call($this->build(VpnProvisioningService::class), 'additionalInbounds', [$this->additional], $this->driver, $this->panel);
        self::assertSame($declared ? [$this->additional] : [], $attachable);
        self::assertSame($declared ? [] : [[$this->additional, VpnProvisioningService::NOT_MODERN]], $unsupported);
    }

    #[DataProvider('declared')]
    public function testLostResponseAdoptionNeedsDeclaration(bool $declared): void
    {
        $this->select(VpnCapability::ADOPT_EXISTING, $declared);
        $result = self::call($this->build(VpnProvisioningService::class), 'findAdoptableClient', $this->driver, $this->request, $this->panel, new Order(), true);
        self::assertSame($declared, null !== $result);
        self::assertSame($declared, in_array('findExistingService', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testBatchUsageFallsBackWithoutCallingUndeclaredMethod(bool $declared): void
    {
        $this->select(VpnCapability::BATCH_USAGE, $declared);
        $result = self::call($this->build(ServiceUsageSyncService::class), 'prefetchBatchUsages', [$this->service]);
        self::assertSame($declared, [] !== $result);
        self::assertSame($declared, in_array('getUsages', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testTrafficAddonKeepsBaseRenewalFallback(bool $declared): void
    {
        $this->select(VpnCapability::TRAFFIC_ADD, $declared);
        (new ServiceTrafficAddonService($this->drivers))->addTraffic($this->service, 5, new Order());
        self::assertSame([$declared ? 'addTrafficLimit' : 'renewService'], $this->driver->calls);
        self::assertSame(55, $this->service->getTrafficLimitGb());
    }

    #[DataProvider('declared')]
    public function testResumeKeepsZeroDayRenewalFallback(bool $declared): void
    {
        $this->select(VpnCapability::RESUME, $declared);
        $this->service->setStatus(VpnServiceStatus::SUSPENDED);
        (new ServiceAdministration($this->em, $this->drivers))->activate($this->service);
        self::assertSame([$declared ? 'activateService' : 'renewService'], $this->driver->calls);
        self::assertSame(VpnServiceStatus::ACTIVE, $this->service->getStatus());
    }

    #[DataProvider('declared')]
    public function testOwnerNoteSyncSkipsUndeclaredMethod(bool $declared): void
    {
        $this->select(VpnCapability::CLIENT_OWNER_NOTE, $declared);
        $claim = new ServiceClaim($this->service, new User(), str_repeat('a', 64), new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'));
        $result = $this->build(ClaimPanelSync::class)->sync($claim);
        self::assertSame($declared ? ServiceClaim::SYNC_SYNCED : ServiceClaim::SYNC_SKIPPED, $result);
        self::assertSame($declared, in_array('updateClientOwner', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testMovePlannerRejectsUndeclaredMoves(bool $declared): void
    {
        $this->select(VpnCapability::INBOUND_MOVE, $declared);
        $row = ['id' => 1, 'status' => VpnServiceStatus::ACTIVE, 'panel_id' => 1, 'inbound_id' => 1, 'remote_id' => $this->service->getRemoteId()];
        [$reason] = $this->build(InboundMovePlanner::class, ['settings' => self::getContainer()->get(\App\Shop\Application\InboundMoveSettings::class)])
            ->skipReason($row, $this->additional, new InboundMoveScope(), null);
        self::assertSame($declared ? null : InboundMovePlanner::SKIP_NOT_MODERN, $reason);
        self::assertSame([], $this->driver->calls, 'Planning never calls an unsupported driver.');
    }
    #[DataProvider('declared')]
    public function testCredentialRotationIsHiddenWhenUndeclared(bool $declared): void
    {
        $this->select(VpnCapability::CREDENTIAL_ROTATION, $declared);
        $owner = $this->service->getUser();
        self::id($owner, 1);
        $this->db->method('fetchAssociative')->willReturn(['to_user_id' => 1, 'claim_id' => 1]);
        $settings = $this->createMock(\App\Reseller\Application\ResellerSettings::class);
        $settings->method('claimsEnabled')->willReturn(true);
        $ownership = $this->build(\App\Reseller\Application\ServiceOwnership::class, [
            'settings' => $settings,
            'resetLimiter' => self::getContainer()->get('limiter.claim_reset_links'),
            'ordersInFlight' => new \App\Reseller\Application\ServiceOrdersInFlight($this->em),
        ]);
        self::assertSame($declared, $ownership->canResetLinks($owner, $this->service));
        self::assertSame($declared, in_array('canRotateCredentials', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testInboundSelectionReadNeedsItsOwnDeclaration(bool $declared): void
    {
        $this->select(VpnCapability::PER_USER_INBOUND_SELECTION, $declared);
        $planner = $this->build(InboundMovePlanner::class, ['settings' => self::getContainer()->get(\App\Shop\Application\InboundMoveSettings::class)]);
        self::call($planner, 'readClientInbounds', ['panel_id' => 1, 'id' => 1, 'remote_id' => $this->service->getRemoteId()]);
        self::assertSame($declared, in_array('clientInboundIds', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testAdminPlanChoicesFollowDeclarations(bool $declared): void
    {
        $this->select(VpnCapability::MULTI_INBOUND, $declared);
        $this->panel->setType('capability_fake');
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findBy')->willReturn([$this->primary, $this->additional]);
        $this->em->method('getRepository')->willReturn($repo);
        $controller = $this->build(\App\Admin\UI\Crud\PlanCrudController::class);
        self::assertSame($declared ? [$this->primary, $this->additional] : [], self::call($controller, 'additionalChoices', null));
    }

    #[DataProvider('declared')]
    public function testPlanValidationRefusesUnsupportedPanel(bool $declared): void
    {
        $this->select(VpnCapability::MULTI_INBOUND, $declared);
        $plan = (new Plan())->setInbound($this->primary)->setAdditionalInbounds([$this->additional]);
        $messages = [];
        $context = $this->createMock(\Symfony\Component\Validator\Context\ExecutionContextInterface::class);
        $violations = new \Symfony\Component\Validator\ConstraintViolationList();
        $context->method('getViolations')->willReturn($violations);
        $builder = $this->createMock(\Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface::class);
        $builder->method('atPath')->willReturnSelf();
        $builder->method('setParameters')->willReturnSelf();
        $context->method('buildViolation')->willReturnCallback(function (string $message) use (&$messages, $builder) {
            $messages[] = $message;
            return $builder;
        });
        $validator = $this->build(\App\Shop\Application\Catalog\PlanInboundsValidator::class);
        $validator->initialize($context);
        $validator->validate($plan, new \App\Shop\Application\Catalog\PlanInbounds());
        self::assertSame(!$declared, in_array('plan.additional_inbounds.not_modern', $messages, true));
    }

    #[DataProvider('declared')]
    public function testInboundCountsUseTheScheduledAttachGate(bool $declared): void
    {
        $this->select(VpnCapability::MULTI_INBOUND, $declared);
        self::assertSame($declared, self::call($this->build(\App\Provisioning\Application\ServiceInboundCounts::class), 'panelCanAttach', $this->service));
    }

    public function testProviderRegistryResolvesAPackageOnlyAfterTaggedDriversDecline(): void
    {
        $this->select(VpnCapability::PANEL_SUB_LINKS, true);
        $this->panel->setType('capability_fake');
        $resolver = new \App\Module\Application\VpnProviderResolver(new \App\Module\Application\ProviderDriverRegistry([$this->driver]));
        self::assertSame($this->driver, (new VpnPanelDriverRegistry([], $resolver))->resolve($this->panel));
        $core = new \App\Provisioning\Infrastructure\DummyVpnPanelDriver();
        self::assertSame($core, (new VpnPanelDriverRegistry([$core], $resolver))->resolve((new VpnPanel())->setType('dummy')));
    }

    #[DataProvider('declared')]
    public function testMoveRunnerUndoCannotDetachWithoutDeclaration(bool $declared): void
    {
        $this->select(VpnCapability::INBOUND_DETACH, $declared);
        $settings = self::getContainer()->get(\App\Shop\Application\InboundMoveSettings::class);
        $planner = $this->build(InboundMovePlanner::class, ['settings' => $settings]);
        $runner = $this->build(\App\Provisioning\Application\InboundMoveRunner::class, ['settings' => $settings, 'planner' => $planner]);
        // A capable driver refuses removal of the primary using its usual safety guard.
        // An undeclared operation must be refused by the application before reaching that driver guard.
        $result = self::call($runner, 'removeAdded', $this->service,
            ['id' => 1, 'run_id' => 1, 'to_inbound_id' => 1, 'to_remote_inbound_id' => '1'],
            ['claimedAt' => '2026-11-23 00:00:00', 'previous' => 'done'], 'A', 'B');
        self::assertSame(\App\Provisioning\Application\InboundMoveItem::SKIPPED, $result->outcome);
        self::assertSame($declared, in_array('detachInbounds', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testResetRequiresItsDeclaredBaseCapability(bool $declared): void
    {
        $this->select(VpnCapability::TRAFFIC_RESET, $declared);
        $result = (new ServiceAdministration($this->em, $this->drivers))->resetUsage($this->service);
        self::assertSame($declared ? \App\Provisioning\Application\ServiceAdminOutcome::DONE : \App\Provisioning\Application\ServiceAdminOutcome::PANEL_FAILED, $result);
        self::assertSame($declared, in_array('resetUsage', $this->driver->calls, true));
    }

    #[DataProvider('declared')]
    public function testRemovePlannerRequiresDetachBeforeOfferingRemoval(bool $declared): void
    {
        $this->select(VpnCapability::INBOUND_DETACH, $declared);
        $this->rows = [['id' => 1, 'status' => VpnServiceStatus::ACTIVE, 'panel_id' => 1, 'inbound_id' => 1,
            'remote_id' => $this->service->getRemoteId(), 'row_remote_id' => '2']];
        $planner = $this->build(InboundMovePlanner::class, ['settings' => self::getContainer()->get(\App\Shop\Application\InboundMoveSettings::class)]);
        $candidates = $planner->removeCandidates(new Plan(), $this->additional, new InboundMoveScope());
        self::assertCount(1, $candidates);
        self::assertSame($declared ? null : InboundMovePlanner::SKIP_NOT_MODERN, $candidates[0]->skipReason);
        self::assertSame([], $this->driver->calls);
    }

    #[DataProvider('declared')]
    public function testAttachSchedulerRequiresPerUserSelectionBeforeReading(bool $declared): void
    {
        $this->select(VpnCapability::PER_USER_INBOUND_SELECTION, $declared);
        $scheduler = $this->build(\App\Provisioning\Application\InboundAttachScheduler::class,
            ['attacher' => $this->build(ServiceInboundAttacher::class)]);
        $run = new \App\Provisioning\Application\InboundAttachRun();
        $failures = [];
        (new \ReflectionMethod($scheduler, 'one'))->invokeArgs($scheduler, [1, $run, &$failures]);
        self::assertSame($declared, in_array('clientInboundIds', $this->driver->calls, true));
    }

}
