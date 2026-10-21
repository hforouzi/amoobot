<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe\SanaeiCapabilityProbe;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Capability detection: modern only on a positive clients-API answer, legacy only on a working cookie login,
 * and a named failure otherwise. Against the in-memory fake panel; nothing real is contacted.
 */
final class CapabilityProbeTest extends TestCase
{
    use Fake3xuiWiring;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
    }

    public function testTokenThatReadsTheClientsApiMeansModern(): void
    {
        $report = $this->probe($this->panel());

        self::assertSame(SanaeiIntegrationMode::MODERN, $report->detected);
        self::assertSame('ok', $report->status());
        self::assertNotNull($report->latencyMs);
        self::assertSame(['GET panel/api/clients/list/paged'], $this->fake->requestLines());
        self::assertSame(['page' => '1', 'pageSize' => '1'], $this->fake->requests[0]['query']);
        self::assertSame('Bearer '.Fake3xuiPanel::TOKEN, $this->fake->requests[0]['headers']['authorization'][0]);
    }

    public function testOldPanelWithoutClientsApiFallsBackToTheLegacyLogin(): void
    {
        $this->fake->mode = 'legacy';
        $report = $this->probe($this->panel(withCredentials: true));

        self::assertSame(SanaeiIntegrationMode::LEGACY, $report->detected);
        self::assertTrue($report->tokenProbeAttempted);
        self::assertTrue($report->legacyProbeAttempted);
        self::assertSame(['GET panel/api/clients/list/paged', 'POST login', 'GET panel/api/inbounds/list'], $this->fake->requestLines());
    }

    public function testLegacyOnlyWhenNoTokenIsConfigured(): void
    {
        $this->fake->mode = 'legacy';
        $report = $this->probe($this->panel(withToken: false, withCredentials: true));

        self::assertSame(SanaeiIntegrationMode::LEGACY, $report->detected);
        self::assertFalse($report->tokenProbeAttempted);
    }

    public function testRevokedTokenWithoutCredentialsIsAnAuthFailureNotAGuess(): void
    {
        $this->fake->tokenRevoked = true;
        $report = $this->probe($this->panel());

        self::assertNull($report->detected);
        self::assertSame(PanelFailure::AUTH_REJECTED, $report->failure);
        self::assertSame(401, $report->httpStatus);
        self::assertFalse($report->legacyProbeAttempted);
    }

    public function testWrongLegacyCredentialsAfterAFailedTokenProbeDecideNothing(): void
    {
        $this->fake->mode = 'legacy';
        $panel = $this->panel(withCredentials: true)->setPlainPassword('wrong-password');
        $report = $this->probe($panel);

        self::assertNull($report->detected);
        self::assertSame(PanelFailure::WRONG_URL, $report->failure, 'the token probe got the old panel\'s 404 page');
        self::assertTrue($report->legacyProbeAttempted);
    }

    public function testNothingConfigured(): void
    {
        $report = $this->probe($this->panel(withToken: false));

        self::assertNull($report->detected);
        self::assertSame(PanelFailure::NOT_CONFIGURED, $report->failure);
        self::assertSame([], $this->fake->requestLines());
    }

    /**
     * @return iterable<string, array{MockResponse, PanelFailure}>
     */
    public static function unreachable(): iterable
    {
        yield 'DNS' => [Fake3xuiPanel::networkError('Could not resolve host: panel.example.test'), PanelFailure::NETWORK_DNS];
        yield 'refused' => [Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053 after 3 ms: Connection refused'), PanelFailure::NETWORK_REFUSED];
        yield 'timeout' => [Fake3xuiPanel::networkError('Connection timed out after 3001 milliseconds'), PanelFailure::NETWORK_TIMEOUT];
        yield 'idle timeout' => [Fake3xuiPanel::networkError('Idle timeout reached for "https://panel.example.test:2053/".'), PanelFailure::NETWORK_TIMEOUT];
        yield 'TLS' => [Fake3xuiPanel::networkError('SSL certificate problem: self-signed certificate'), PanelFailure::TLS_CERTIFICATE];
        yield 'TLS host' => [Fake3xuiPanel::networkError('SSL: no alternative certificate subject name matches target host name'), PanelFailure::TLS_CERTIFICATE];
    }

    #[DataProvider('unreachable')]
    public function testUnreachablePanelIsNamedAndTheLegacyLoginIsNotTried(MockResponse $error, PanelFailure $expected): void
    {
        $this->fake->script(null, null, $error, 5);
        $report = $this->probe($this->panel(withCredentials: true));

        self::assertNull($report->detected);
        self::assertSame($expected, $report->failure);
        self::assertTrue($report->failure->isUnreachable());
        self::assertFalse($report->legacyProbeAttempted);
        self::assertNotContains('POST login', $this->fake->requestLines());
    }

    /**
     * @return iterable<string, array{MockResponse, PanelFailure, ?int}>
     */
    public static function answeredWrongly(): iterable
    {
        yield 'HTML page with 200' => [Fake3xuiPanel::html(), PanelFailure::WRONG_URL, 200];
        yield 'HTML page without content type' => [new MockResponse('<html><body>login</body></html>'), PanelFailure::WRONG_URL, 200];
        yield '404' => [new MockResponse('404 page not found', ['http_code' => 404]), PanelFailure::WRONG_URL, 404];
        yield '403' => [Fake3xuiPanel::envelope(false, null, 'forbidden', 403), PanelFailure::AUTH_REJECTED, 403];
        yield 'success:false' => [Fake3xuiPanel::envelope(false, null, 'clients API disabled'), PanelFailure::PANEL_REJECTED, 200];
        yield 'JSON without envelope' => [new MockResponse('{"clients":[]}', ['response_headers' => ['content-type' => 'application/json']]), PanelFailure::UNEXPECTED_RESPONSE, 200];
        yield 'success:true, obj not a list' => [Fake3xuiPanel::envelope(true, 'ok'), PanelFailure::UNEXPECTED_RESPONSE, 200];
        yield 'not JSON' => [new MockResponse('OK', ['response_headers' => ['content-type' => 'text/plain']]), PanelFailure::UNEXPECTED_RESPONSE, 200];
        yield '500' => [new MockResponse('', ['http_code' => 500]), PanelFailure::HTTP_ERROR, 500];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/secret/login']]), PanelFailure::HTTP_ERROR, 302];
    }

    #[DataProvider('answeredWrongly')]
    public function testEachWrongAnswerIsNamed(MockResponse $response, PanelFailure $expected, ?int $status): void
    {
        $this->fake->script('GET', SanaeiCapabilityProbe::PROBE_PATH, $response);
        $report = $this->probe($this->panel());

        self::assertNull($report->detected);
        self::assertSame($expected, $report->failure);
        self::assertSame($status, $report->httpStatus);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $report->message);
    }

    public function testWrongWebBasePathIsAWrongUrl(): void
    {
        $report = $this->probe($this->panel()->setBaseUrl(Fake3xuiPanel::ORIGIN.'/other/'));

        self::assertSame(PanelFailure::WRONG_URL, $report->failure);
    }

    public function testThePanelPageUrlFromTheAddressBarWorks(): void
    {
        $report = $this->probe($this->panel()->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/panel/inbounds'));

        self::assertSame(SanaeiIntegrationMode::MODERN, $report->detected);
    }

    public function testRecordingKeepsTheLastDetectedModeWhenAProbeCannotDecide(): void
    {
        $panel = $this->panel();
        $probe = $this->newProbe();
        $probe->probeAndRecord($panel);
        self::assertSame(SanaeiIntegrationMode::MODERN, $panel->getDetectedIntegrationMode());
        self::assertSame('ok', $panel->getProbeStatus());
        self::assertNotNull($panel->getProbedAt());

        $this->fake->script(null, null, Fake3xuiPanel::networkError('Connection timed out after 3000 milliseconds'), 3);
        $probe->probeAndRecord($panel);

        self::assertSame(SanaeiIntegrationMode::MODERN, $panel->getDetectedIntegrationMode(), 'a panel that is down for a moment keeps its mode');
        self::assertSame('network_timeout', $panel->getProbeStatus());
        self::assertTrue($panel->isModern());
    }

    public function testEffectiveMode(): void
    {
        $panel = $this->panel();
        self::assertSame(SanaeiIntegrationMode::AUTO, $panel->getIntegrationMode(), 'auto is the default');
        self::assertSame(SanaeiIntegrationMode::LEGACY, $panel->getEffectiveIntegrationMode(), 'never probed: legacy, as before modes existed');

        $panel->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        self::assertSame(SanaeiIntegrationMode::MODERN, $panel->getEffectiveIntegrationMode());

        $panel->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        self::assertSame(SanaeiIntegrationMode::LEGACY, $panel->getEffectiveIntegrationMode(), 'a forced mode wins over detection');

        $panel->setIntegrationMode('bogus');
        self::assertSame(SanaeiIntegrationMode::AUTO, $panel->getIntegrationMode());
    }

    private function panel(bool $withToken = true, bool $withCredentials = false): VpnPanel
    {
        return $this->fakePanel($withToken, $withCredentials);
    }

    private function newProbe(): SanaeiCapabilityProbe
    {
        return $this->capabilityProbe();
    }

    private function probe(VpnPanel $panel): \App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe\CapabilityProbeReport
    {
        return $this->newProbe()->probe($panel);
    }
}
