<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake3xui;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe\SanaeiCapabilityProbe;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Shared\Infrastructure\SecretCipher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the modern Sanaei services on top of a Fake3xuiPanel, without the container.
 */
trait Fake3xuiWiring
{
    protected Fake3xuiPanel $fake;
    /** @var list<int> milliseconds the HTTP client waited before retries */
    protected array $sleeps = [];

    protected function credentials(): PanelCredentials
    {
        return new PanelCredentials(new SecretCipher('test-app-secret-for-panels'));
    }

    protected function modernHttp(?PanelHttpClientFactory $factory = null, ?LoggerInterface $logger = null): ModernPanelHttpClient
    {
        return new ModernPanelHttpClient($this->fake->client(), $factory ?? new PanelHttpClientFactory(), $this->credentials(), $logger ?? new NullLogger(), function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    protected function legacyApi(): Sanaei3xuiApiClient
    {
        return new Sanaei3xuiApiClient($this->fake->client(), new PanelHttpClientFactory(), $this->credentials());
    }

    protected function capabilityProbe(): SanaeiCapabilityProbe
    {
        return new SanaeiCapabilityProbe($this->modernHttp(), $this->legacyApi(), $this->credentials());
    }

    protected function fakePanel(bool $withToken = true, bool $withCredentials = false): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('fake')->setBaseUrl($this->fake->origin.$this->fake->basePath.'/');
        if ($withToken) {
            $panel->setPlainApiToken($this->fake->token);
            $this->credentials()->encryptPending($panel);
        }
        if ($withCredentials) {
            $panel->setUsername(Fake3xuiPanel::USERNAME)->setPlainPassword(Fake3xuiPanel::PASSWORD);
            $this->credentials()->encryptPending($panel);
        }

        return $panel;
    }

    protected function modernDriver(): Sanaei3xuiModernDriver
    {
        return new Sanaei3xuiModernDriver(new ModernClientsApi($this->modernHttp()), new Sanaei3xuiRemoteIdParser());
    }

    protected function modernPanel(): VpnPanel
    {
        return $this->fakePanel()->setIntegrationMode(SanaeiIntegrationMode::MODERN);
    }

    protected function fakeInbound(VpnPanel $panel, int $remoteId = 1): VpnInbound
    {
        return (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remoteId)->setTitle('in-'.$remoteId)->setProtocol('vless')->setFlow('xtls-rprx-vision');
    }
}
