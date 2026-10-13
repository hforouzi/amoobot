<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\ExistingClientAwareDriverInterface;
use App\Provisioning\Domain\VpnPanelDriverInterface;

/**
 * Test VPN panel driver for panels of type "test" (registered in config/services_test.yaml).
 *
 * - failNext(n): the next n create/renew calls throw, simulating a panel outage
 * - failNextAfterCreate(): the next create adds the client on the "panel" and then throws (lost
 *   response / timeout after the panel already did the work)
 * - $clients: what the panel holds, keyed by email; findExistingService() searches it
 * - $lookupFails: findExistingService() throws (panel unreadable)
 * - TEST_PANEL_DELAY_MS env var: sleeps inside createService, used to overlap concurrent settlements
 * - $stateFile / TEST_PANEL_STATE_FILE env var: $clients persisted in a JSON file, so the "panel"
 *   outlives a PHP process that is killed (shared by the test and its subprocesses)
 * - TEST_PANEL_SLEEP_AFTER_CREATE_MS env var: sleeps after the client was created (and saved), giving a
 *   test the moment to kill the process between createService and the database commit
 * - $usage / $usageFails: what getUsage() reports (the panel's current state of a client)
 */
final class ProgrammableVpnPanelDriver implements VpnPanelDriverInterface, ExistingClientAwareDriverInterface
{
    public const TYPE = 'test';

    private int $failuresLeft = 0;
    private ?string $failureMessage = null;
    private bool $failAfterCreate = false;
    public int $createCalls = 0;
    public int $renewCalls = 0;
    public int $lookupCalls = 0;
    public ?VpnUsage $usage = null;
    public bool $usageFails = false;
    public bool $lookupFails = false;
    /** @var array<string, array{uuid: string, email: string, subId: string}> */
    public array $clients = [];
    public ?string $stateFile = null;

    public function failNext(int $times = 1, ?string $message = null): void
    {
        $this->failuresLeft = $times;
        $this->failureMessage = $message;
    }

    public function failNextAfterCreate(): void
    {
        $this->failAfterCreate = true;
    }

    public function reset(): void
    {
        $this->failuresLeft = 0;
        $this->failAfterCreate = false;
        $this->createCalls = 0;
        $this->renewCalls = 0;
        $this->lookupCalls = 0;
        $this->usage = null;
        $this->usageFails = false;
        $this->lookupFails = false;
        $this->clients = [];
        $this->stateFile = null;
    }

    public function supports(?VpnPanel $panel): bool
    {
        return $panel instanceof VpnPanel && self::TYPE === $panel->getType();
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        ++$this->createCalls;
        $delay = (int) ($_SERVER['TEST_PANEL_DELAY_MS'] ?? getenv('TEST_PANEL_DELAY_MS') ?: 0);
        if ($delay > 0) {
            usleep($delay * 1000);
        }
        $this->maybeFail('createService');
        $this->loadState();
        if (isset($this->clients[$request->username])) {
            throw new \RuntimeException('Duplicate email: '.$request->username); // like 3x-ui
        }

        $uuid = $request->clientUuid ?? bin2hex(random_bytes(6));
        $this->clients[$request->username] = ['uuid' => $uuid, 'email' => $request->username, 'subId' => bin2hex(random_bytes(6))];
        $this->saveState();
        $sleep = (int) ($_SERVER['TEST_PANEL_SLEEP_AFTER_CREATE_MS'] ?? getenv('TEST_PANEL_SLEEP_AFTER_CREATE_MS') ?: 0);
        if ($sleep > 0) {
            usleep($sleep * 1000);
        }
        if ($this->failAfterCreate) {
            $this->failAfterCreate = false;
            throw new \RuntimeException('Simulated timeout after the panel created the client');
        }

        return $this->service($this->clients[$request->username]);
    }

    public function findExistingService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): ?AdoptedVpnService
    {
        ++$this->lookupCalls;
        if ($this->lookupFails) {
            throw new \RuntimeException('Simulated panel outage during client lookup');
        }
        $this->loadState();

        foreach ($this->clients as $client) {
            if (null !== $request->clientUuid && $client['uuid'] === $request->clientUuid) {
                return new AdoptedVpnService($this->service($client), AdoptedVpnService::MATCHED_BY_UUID, new \DateTimeImmutable('+25 days'), 40);
            }
        }
        if (isset($this->clients[$request->username])) {
            return new AdoptedVpnService($this->service($this->clients[$request->username]), AdoptedVpnService::MATCHED_BY_EMAIL, new \DateTimeImmutable('+25 days'), 40);
        }

        return null;
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        ++$this->renewCalls;
        $this->maybeFail('renewService');
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        if ($this->usageFails) {
            throw new \RuntimeException('Simulated panel outage during getUsage');
        }

        return $this->usage ?? new VpnUsage(trafficUsedGb: 0, trafficLimitGb: null, usedBytes: 0, totalBytes: null);
    }

    /**
     * @param array{uuid: string, email: string, subId: string} $client
     */
    private function service(array $client): CreatedVpnService
    {
        return new CreatedVpnService(
            remoteId: 'test_'.$client['uuid'],
            username: $client['email'],
            subscriptionUrl: 'https://panel.test/sub',
            configText: 'vless://test',
            clientUuid: $client['uuid'],
            clientEmail: $client['email'],
            subId: $client['subId'],
            configLinks: ['vless://test'],
        );
    }

    private function stateFile(): ?string
    {
        $file = $this->stateFile ?? ((string) ($_SERVER['TEST_PANEL_STATE_FILE'] ?? getenv('TEST_PANEL_STATE_FILE') ?: ''));

        return '' !== $file ? $file : null;
    }

    private function loadState(): void
    {
        $file = $this->stateFile();
        if (null !== $file && is_file($file)) {
            $this->clients = json_decode((string) file_get_contents($file), true) ?: [];
        }
    }

    private function saveState(): void
    {
        $file = $this->stateFile();
        if (null !== $file) {
            file_put_contents($file, (string) json_encode($this->clients), LOCK_EX);
        }
    }

    private function maybeFail(string $operation): void
    {
        if ($this->failuresLeft > 0) {
            --$this->failuresLeft;
            throw new \RuntimeException($this->failureMessage ?? sprintf('Simulated panel outage during %s (token=supersecret123)', $operation));
        }
    }
}
