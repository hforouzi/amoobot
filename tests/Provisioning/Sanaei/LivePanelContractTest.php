<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe\SanaeiCapabilityProbe;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Shared\Infrastructure\SecretCipher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Opt-in contract test against a REAL 3x-ui panel: read-only (probe, inbound options, client list shapes).
 * Skipped unless both variables are set; never commit their values:
 *
 *   PANEL_E2E_URL=https://panel.example.com:2053/secret/ PANEL_E2E_TOKEN=... php bin/phpunit --group panel-e2e
 *
 * Optional: PANEL_E2E_TLS_PIN (base64 SHA-256 public-key pin of a self-signed panel).
 * Writes are verified by the operator command instead: php bin/console app:panel:doctor <id> --self-test --yes
 */
#[Group('panel-e2e')]
final class LivePanelContractTest extends TestCase
{
    private VpnPanel $panel;
    private ModernPanelHttpClient $http;
    private PanelCredentials $credentials;

    protected function setUp(): void
    {
        $url = (string) (getenv('PANEL_E2E_URL') ?: '');
        $token = (string) (getenv('PANEL_E2E_TOKEN') ?: '');
        if ('' === $url || '' === $token) {
            self::markTestSkipped('Set PANEL_E2E_URL and PANEL_E2E_TOKEN to run the contract test against a real panel.');
        }
        $credentials = new PanelCredentials(new SecretCipher(bin2hex(random_bytes(16))));
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('e2e')->setBaseUrl($url)->setPlainApiToken($token)
            ->setTlsPinSha256((string) (getenv('PANEL_E2E_TLS_PIN') ?: ''));
        // The real network (the test container's HttpClient is a mock).
        $this->http = new ModernPanelHttpClient(HttpClient::create(), new PanelHttpClientFactory(), $credentials);
        $this->credentials = $credentials;
    }

    public function testTheTokenReadsTheClientsApi(): void
    {
        $probe = new SanaeiCapabilityProbe($this->http, new Sanaei3xuiApiClient(HttpClient::create(), new PanelHttpClientFactory(), $this->credentials), $this->credentials);
        $report = $probe->probe($this->panel);

        self::assertSame(SanaeiIntegrationMode::MODERN, $report->detected, $report->message);
    }

    public function testDocumentedShapes(): void
    {
        $api = new ModernClientsApi($this->http);

        $options = $api->inboundOptions($this->panel);
        self::assertNotEmpty($options, 'the panel has at least one inbound');

        $page = $api->listPaged($this->panel, 1, 1);
        self::assertGreaterThanOrEqual(0, $page['total']);

        $clients = $api->listClients($this->panel);
        foreach (array_slice($clients, 0, 3) as $client) {
            self::assertNotSame('', (string) ($client['id'] ?? ''), 'list rows carry the client UUID');
            self::assertNotNull(ModernClientsApi::usedBytes($client), 'list rows carry traffic (batch usage relies on it)');
            $full = $api->getClient($this->panel, (string) $client['email']);
            self::assertNotNull($full, 'clients/get/{email} returns the client');
            self::assertSame($client['id'], $full['id'] ?? null, 'clients/get returns the same UUID');
            foreach (['subId', 'totalGB', 'expiryTime', 'enable'] as $field) {
                self::assertArrayHasKey($field, $full, 'clients/get returns '.$field.' (needed for the full-row update)');
            }
        }

        self::assertNull($api->getClient($this->panel, 'amoobot_e2e_absent_'.bin2hex(random_bytes(4))), 'an unknown email is recognised as "no such client"');
    }
}
