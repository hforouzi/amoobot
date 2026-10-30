<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\VpnServiceConfigRefreshService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiConfigGenerator;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\CapturesErrorLog;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The legacy 3x-ui driver and its API client log every request, answer and step of a provisioning or refresh. None of
 * those lines may carry the client's UUID, subscription id, trojan password, subscription URL or config links, nor the
 * panel's secret base path, even when the panel echoes them back in an answer or an error.
 */
final class LegacyDriverLogRedactionTest extends TestCase
{
    use CapturesErrorLog;
    use Fake3xuiWiring;

    private const UUID = '4d2c8e6a-1f3b-4a5c-9d7e-8f0a1b2c3d4e';
    private const SUB_ID = 'legacySub98765432';
    private const PASSWORD = 'trojanPass-4242';

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->fake->mode = 'legacy';
        $this->fake->basePath = '/hiddenPanelPath';
        $echo = (string) json_encode([
            'success' => false,
            'msg' => sprintf('client %s (subId: %s) at https://sub.panel.test/sub/%s failed', self::UUID, self::SUB_ID, self::SUB_ID),
            'obj' => ['clients' => [['id' => self::UUID, 'subId' => self::SUB_ID, 'password' => self::PASSWORD, 'link' => 'vless://'.self::UUID.'@edge.test:443#x']]],
        ]);
        $this->fake->script(null, null, static function (string $method, string $url) use ($echo): MockResponse {
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/login')) {
                return new MockResponse('{"success":true,"msg":"","obj":null}', ['response_headers' => ['content-type' => 'application/json', 'set-cookie' => '3x-ui=s1; Path=/']]);
            }

            return new MockResponse($echo, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        }, 1000);
    }

    /** @return array<string, string> */
    private static function secrets(): array
    {
        return [
            'client UUID' => self::UUID,
            'subscription id' => self::SUB_ID,
            'trojan password' => self::PASSWORD,
            'subscription URL' => 'https://sub.panel.test/sub/'.self::SUB_ID,
            'panel base path' => 'hiddenPanelPath',
            'panel password' => Fake3xuiPanel::PASSWORD,
        ];
    }

    private function driver(): Sanaei3xuiDriver
    {
        return new Sanaei3xuiDriver($this->legacyApi(), new Sanaei3xuiRemoteIdParser(), new Sanaei3xuiConfigGenerator());
    }

    public function testCreatingAClientLogsNoAccess(): void
    {
        $panel = $this->fakePanel(false, true);
        $inbound = $this->fakeInbound($panel, 1);

        $log = $this->captureErrorLog(function () use ($panel, $inbound): void {
            try {
                $this->driver()->createService(new CreateVpnServiceRequest('tg_1_order_1', 30, 10, null, $inbound, '1', [], self::UUID), $panel);
            } catch (\RuntimeException) {
                // The echoing panel refuses the client; only the log lines matter here.
            }
        });

        $sent = (string) json_encode($this->fake->requests);
        self::assertSame(1, preg_match('/subId\\\\*"\s*:\s*\\\\*"([A-Za-z0-9]+)/', $sent, $m), 'the request carried a generated subId');
        self::assertStringContainsString('create_service_add_client_request', $log);
        self::assertStringContainsString('uuid="••••3d4e"', $log, 'the last 4 characters stay for correlation');
        self::assertContainsNoSecret($log, self::secrets() + ['generated subscription id' => $m[1]], 'the legacy provisioning log');
    }

    public function testRefreshingAServiceLogsNoAccess(): void
    {
        $panel = $this->fakePanel(false, true);
        $service = (new VpnService())
            ->setPanel($panel)
            ->setInbound($this->fakeInbound($panel, 1))
            ->setClientUuid(self::UUID)
            ->setSubId(self::SUB_ID)
            ->setUsername('tg_1_order_1')
            ->setSubscriptionUrl('https://sub.panel.test/sub/'.self::SUB_ID);
        $refresh = new VpnServiceConfigRefreshService($this->driver());

        $log = $this->captureErrorLog(function () use ($refresh, $service): void {
            $refresh->refreshSanaeiLegacy($service, 'test');
            (new ServiceConfigDeliveryRefresher($refresh))->refreshBeforeDelivery($service, 'test');
        });

        self::assertStringContainsString('service_config_online_refresh_start', $log);
        self::assertStringContainsString('config_delivery_online_refresh', $log);
        self::assertContainsNoSecret($log, self::secrets(), 'the legacy refresh log');
    }
}
